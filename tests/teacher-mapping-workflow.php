<?php
// All writes use connection-local temporary tables; real scheduling data is untouched.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/config.php';
require_once ROOT_PATH.'/config/database.php';
require_once ROOT_PATH.'/includes/authentication.php';
require_once ROOT_PATH.'/includes/audit.php';
require_once ROOT_PATH.'/modules/scheduling/teacher-mapping-service.php';
$_SESSION['user_id']=1;$_SESSION['user_role_key']='registrar';$_SESSION['user_name']='Isolated test';
$pdo=getDatabaseConnection();
foreach(['sections','subjects','teachers','section_subjects','teacher_subject_qualifications','time_blocks','teacher_availability','schedule_entries','special_classes','special_class_students','exam_schedules','activity_logs'] as $table){
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^\s*CONSTRAINT .*$/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
$pdo->exec("INSERT INTO sections(id,code,name,program,year_level,academic_year,semester,max_students,current_students,status) VALUES(1,'TEST-A','Test A','BSIT',2,'2026-2027','1st Semester',40,30,'Active'),(2,'TEST-B','Test B','BSIT',2,'2026-2027','1st Semester',40,30,'Active')");
$pdo->exec("INSERT INTO subjects(id,code,name,units,program,year_level,semester) VALUES(1,'TEST101','Test subject',3,'BSIT',2,'1st Semester'),(2,'TEST102','Other subject',3,'BSIT',2,'1st Semester')");
$pdo->exec("INSERT INTO teachers(id,employee_no,full_name,max_load_units,status) VALUES(1,'TEST-F1','Test Faculty',6,'Active'),(2,'TEST-F2','Other Faculty',6,'Active')");
$pdo->exec("INSERT INTO section_subjects(section_id,subject_id,status) VALUES(1,1,'Assigned'),(2,2,'Assigned')");
$pdo->exec("INSERT INTO teacher_subject_qualifications(teacher_id,subject_id,specialization_label,status) VALUES(1,1,'Test qualification','Active')");
$pdo->exec("INSERT INTO time_blocks(id,code,label,start_time,end_time,block_type,is_active) VALUES(1,'TEST-T1','Test block','09:00','10:00','Class',1),(2,'TEST-T2','Later block','10:00','11:00','Class',1)");
$p=['section_id'=>1,'subject_id'=>1,'teacher_id'=>1,'time_block_id'=>1,'day'=>'Monday','entry_id'=>0,'revision'=>'new'];
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function reject(PDO $db,array $payload,string $part): void {try{tmProcess($db,$payload,true);throw new RuntimeException('Expected rejection: '.$part);}catch(InvalidArgumentException|DomainException $e){check(str_contains($e->getMessage(),$part),'Unexpected message: '.$e->getMessage());}}
$cases=0;
$r=tmProcess($pdo,$p,false);check($r['valid'],'Valid teacher preview');check((int)$pdo->query('SELECT COUNT(*) FROM schedule_entries')->fetchColumn()===0,'Preview wrote a record');$cases++;
$pdo->exec("UPDATE teachers SET status='Inactive' WHERE id=1");reject($pdo,$p,'active teacher');$pdo->exec("UPDATE teachers SET status='Active' WHERE id=1");$cases++;
$pdo->exec("UPDATE teacher_subject_qualifications SET status='Inactive'");reject($pdo,$p,'qualification');$pdo->exec("UPDATE teacher_subject_qualifications SET status='Active'");$cases++;
reject($pdo,array_replace($p,['subject_id'=>2]),'not assigned');$cases++;
$pdo->exec('UPDATE time_blocks SET is_active=0 WHERE id=1');reject($pdo,$p,'time block');$pdo->exec('UPDATE time_blocks SET is_active=1 WHERE id=1');$cases++;
reject($pdo,array_replace($p,['day'=>'Invalid']),'valid day');$cases++;
$pdo->exec("INSERT INTO teacher_availability(teacher_id,day_of_week,start_time,end_time,availability) VALUES(1,'Monday','09:00','10:00','Unavailable')");
$r=tmProcess($pdo,$p,true);check(!$r['valid']&&count(array_filter($r['findings'],fn($f)=>$f['conflict_type']==='Availability'&&$f['severity']==='Error'))>0,'Unavailable window accepted');$pdo->exec('DELETE FROM teacher_availability');$cases++;
$pdo->exec("INSERT INTO schedule_entries(id,section_id,subject_id,teacher_id,time_block_id,day_of_week,start_time,end_time,academic_year,semester) VALUES(20,2,2,1,1,'Monday','09:00','10:00','2026-2027','1st Semester')");
$r=tmProcess($pdo,$p,true);check(!$r['valid']&&count(array_filter($r['findings'],fn($f)=>$f['conflict_type']==='Faculty'))>0,'Faculty overlap accepted');$cases++;
$pdo->exec('UPDATE schedule_entries SET section_id=1,teacher_id=2 WHERE id=20');$r=tmProcess($pdo,$p,true);check(!$r['valid']&&count(array_filter($r['findings'],fn($f)=>$f['conflict_type']==='Student/Section'))>0,'Section overlap accepted');$cases++;
$pdo->exec("UPDATE schedule_entries SET section_id=2,teacher_id=1,start_time='10:00',end_time='11:00' WHERE id=20");$r=tmProcess($pdo,$p,false);check($r['valid'],'Adjacent periods should not overlap');$cases++;
$pdo->exec('UPDATE teachers SET max_load_units=5 WHERE id=1');$r=tmProcess($pdo,$p,true);check(!$r['valid']&&count(array_filter($r['findings'],fn($f)=>$f['conflict_type']==='Load'))>0,'Overload accepted');$pdo->exec('UPDATE teachers SET max_load_units=6 WHERE id=1');$cases++;
$pdo->exec('DELETE FROM schedule_entries');
$pdo->exec("INSERT INTO special_classes(id,title,section_id,subject_id,teacher_id,time_block_id,class_date,start_time,end_time,status,academic_year,semester) VALUES(1,'Test special',2,2,1,1,'2026-09-21','09:00','10:00','Scheduled','2026-2027','1st Semester')");
$r=tmProcess($pdo,$p,true);check(!$r['valid']&&count(array_filter($r['findings'],fn($f)=>$f['conflict_type']==='Faculty'))>0,'Special class overlap accepted');$pdo->exec('DELETE FROM special_classes');$cases++;
$pdo->exec("INSERT INTO exam_schedules(id,section_id,subject_id,proctor_id,exam_date,start_time,end_time,status,academic_year,semester) VALUES(1,2,2,1,'2026-09-21','09:00','10:00','Draft','2026-2027','1st Semester')");
$r=tmProcess($pdo,$p,true);check(!$r['valid'],'Exam overlap accepted');$pdo->exec('DELETE FROM exam_schedules');$cases++;
$r=tmProcess($pdo,$p,true);check($r['valid']&&!empty($r['saved_id']),'Save failed');$saved=$pdo->query('SELECT * FROM schedule_entries')->fetch(PDO::FETCH_ASSOC);check($saved['room_id']===null&&$saved['status']==='Draft','Room or publication assigned prematurely');check(str_contains($r['next_url'],'record_id='.$saved['id']),'Missing room handoff');check((int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()===1,'Missing history');$cases++;
reject($pdo,$p,'mapping changed');$cases++;
$p['entry_id']=(int)$saved['id'];$p['revision']=tmRevision($saved);
reject($pdo,array_replace($p,['revision'=>'stale']),'updated by another');$cases++;
$r=tmProcess($pdo,$p,true);check($r['valid']&&(int)$pdo->query('SELECT COUNT(*) FROM schedule_entries')->fetchColumn()===1,'Editing duplicated mapping');$cases++;
$pdo->exec("UPDATE schedule_entries SET status='Published'");reject($pdo,$p,'Published mappings');$pdo->exec("UPDATE schedule_entries SET status='Draft'");$cases++;
$p['revision']=tmRevision($pdo->query('SELECT * FROM schedule_entries')->fetch(PDO::FETCH_ASSOC));$p['time_block_id']=2;
$pdo->exec('ALTER TABLE activity_logs DROP COLUMN detail');
try{tmProcess($pdo,$p,true);throw new RuntimeException('Audit error should fail save');}catch(PDOException $e){check($pdo->query('SELECT start_time FROM schedule_entries')->fetchColumn()==='09:00:00','Audit failure did not rollback');}$cases++;
$all=ccRecords($pdo);$findings=ccValidate($pdo,$all,$all);check(count(array_filter($findings,fn($f)=>$f['conflict_type']==='Room'&&$f['severity']==='Error'))>0,'Full checker must block roomless publication');$cases++;
echo "PASS $cases isolated teacher-mapping checks\n";
