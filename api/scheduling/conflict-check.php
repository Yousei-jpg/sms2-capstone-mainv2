<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/audit.php';

header('Content-Type: application/json; charset=utf-8');
if (function_exists('isAuthenticated') && !isAuthenticated()) {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Authentication required.']); exit;
}
if (function_exists('userCanAccessModule') && !userCanAccessModule('scheduling')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'You do not have permission to access Class Scheduling.']); exit;
}

function ccBody(): array {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($body) && !empty($_POST)) $body=$_POST;
    if(!is_array($body)) throw new InvalidArgumentException('Invalid JSON request body.');
    return $body;
}
function ccTime(?string $time): string { return $time ? date('g:i A',strtotime($time)) : '—'; }
function ccActor(): ?int { return function_exists('getCurrentUserId') ? getCurrentUserId() : null; }
function ccScope(string $type): string { return $type==='Regular'?'Class':$type; }
function ccTypeFromScope(string $scope): string { return $scope==='Class'?'Regular':$scope; }
function ccRecordKey(string $type,int $id): string { return $type.':'.$id; }
function ccModuleUrl(string $module, array $record=[]): string {
    $paths=[
        'teacher-mapping'=>'/modules/scheduling/pages/teacher-schedule-mapping.php',
        'room-availability'=>'/modules/scheduling/pages/room-availability-checker.php',
        'section-assignment'=>'/modules/scheduling/pages/section-assignment-tool.php',
        'special-class'=>'/modules/scheduling/pages/special-class-scheduler.php',
        'exam-timetable'=>'/modules/scheduling/pages/exam-timetable-generator.php',
        'time-blocks'=>'/modules/scheduling/pages/time-block-generator.php',
    ];
    $path=$paths[$module]??'/modules/scheduling/index.php';
    $query=[];
    if(!empty($record['section_code']))$query['section_code']=$record['section_code'];
    if(!empty($record['id']))$query['record_id']=$record['id'];
    return BASE_URL.$path.($query?'?'.http_build_query($query):'');
}

function ccRecords(PDO $pdo): array {
    $records=[];
    $sql="SELECT se.id,se.section_id,se.subject_id,se.teacher_id,se.room_id,se.time_block_id,
                se.day_of_week,se.start_time,se.end_time,se.class_type,se.status,se.academic_year,se.semester,
                sec.code section_code,sec.name section_name,sec.current_students participant_count,
                sub.code subject_code,sub.name subject_name,sub.units,
                t.full_name teacher_name,t.employee_no,r.room_code,r.building,r.room_type,r.capacity room_capacity,r.status room_status
           FROM schedule_entries se JOIN sections sec ON sec.id=se.section_id JOIN subjects sub ON sub.id=se.subject_id
      LEFT JOIN teachers t ON t.id=se.teacher_id LEFT JOIN rooms r ON r.id=se.room_id
          WHERE se.status IN ('Draft','Validated','Published')";
    foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){
        $r['schedule_type']='Regular';$r['key']=ccRecordKey('Regular',(int)$r['id']);$r['date']=null;
        $r['display_type']=$r['class_type']?:'Regular';$r['assignment_mode']='Section';$r['student_keys']=[];$records[$r['key']]=$r;
    }
    $sql="SELECT sc.id,sc.section_id,sc.subject_id,sc.teacher_id,sc.room_id,sc.time_block_id,
                DATE_FORMAT(sc.class_date,'%W') day_of_week,sc.class_date date,sc.start_time,sc.end_time,
                sc.special_type display_type,sc.status,sc.academic_year,sc.semester,sc.assignment_mode,
                sec.code section_code,sec.name section_name,
                CASE WHEN sc.assignment_mode='Section' THEN COALESCE(sec.current_students,0)
                     ELSE (SELECT COUNT(*) FROM special_class_students x WHERE x.special_class_id=sc.id) END participant_count,
                sub.code subject_code,sub.name subject_name,sub.units,
                t.full_name teacher_name,t.employee_no,r.room_code,r.building,r.room_type,r.capacity room_capacity,r.status room_status,
                sc.title
           FROM special_classes sc LEFT JOIN sections sec ON sec.id=sc.section_id LEFT JOIN subjects sub ON sub.id=sc.subject_id
      LEFT JOIN teachers t ON t.id=sc.teacher_id LEFT JOIN rooms r ON r.id=sc.room_id
          WHERE sc.status<>'Cancelled'";
    $specialStudents=[];
    foreach($pdo->query('SELECT special_class_id,student_key FROM special_class_students')->fetchAll(PDO::FETCH_ASSOC) as $s)$specialStudents[(int)$s['special_class_id']][]=$s['student_key'];
    foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){
        $r['schedule_type']='Special';$r['key']=ccRecordKey('Special',(int)$r['id']);$r['student_keys']=$specialStudents[(int)$r['id']]??[];$records[$r['key']]=$r;
    }
    $sql="SELECT ex.id,ex.section_id,ex.subject_id,ex.proctor_id teacher_id,ex.room_id,ex.time_block_id,
                DATE_FORMAT(ex.exam_date,'%W') day_of_week,ex.exam_date date,ex.start_time,ex.end_time,
                ex.exam_type display_type,ex.status,ex.academic_year,ex.semester,'Section' assignment_mode,
                sec.code section_code,sec.name section_name,sec.current_students participant_count,
                sub.code subject_code,sub.name subject_name,sub.units,
                t.full_name teacher_name,t.employee_no,r.room_code,r.building,r.room_type,r.capacity room_capacity,r.status room_status
           FROM exam_schedules ex JOIN sections sec ON sec.id=ex.section_id JOIN subjects sub ON sub.id=ex.subject_id
      LEFT JOIN teachers t ON t.id=ex.proctor_id LEFT JOIN rooms r ON r.id=ex.room_id
          WHERE ex.status IN ('Draft','Generated','For Validation','Validated','Ready to Publish','Published')";
    foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r){$r['schedule_type']='Exam';$r['key']=ccRecordKey('Exam',(int)$r['id']);$r['student_keys']=[];$records[$r['key']]=$r;}
    foreach($records as &$r){
        foreach(['id','section_id','subject_id','teacher_id','room_id','time_block_id','participant_count','room_capacity'] as $k)$r[$k]=$r[$k]!==null?(int)$r[$k]:null;
        $r['units']=(float)($r['units']??0);
        $r['schedule_label']=($r['date']?$r['date'].' · ':'').($r['day_of_week']?:'No day').' · '.ccTime($r['start_time']).' – '.ccTime($r['end_time']);
    }
    unset($r);return $records;
}
function ccPublicRecord(array $r): array {
    return array_intersect_key($r,array_flip(['key','id','schedule_type','display_type','section_id','section_code','section_name','subject_id','subject_code','subject_name','teacher_id','teacher_name','employee_no','room_id','room_code','building','room_type','room_capacity','date','day_of_week','start_time','end_time','schedule_label','academic_year','semester','status','participant_count','assignment_mode']));
}
function ccSameTerm(array $a,array $b): bool {
    if(!empty($a['academic_year'])&&!empty($b['academic_year'])&&$a['academic_year']!==$b['academic_year'])return false;
    if(!empty($a['semester'])&&!empty($b['semester'])&&$a['semester']!==$b['semester'])return false;
    return true;
}
function ccOverlap(array $a,array $b): bool {
    if(!$a['start_time']||!$a['end_time']||!$b['start_time']||!$b['end_time'])return false;
    if(!ccSameTerm($a,$b))return false;
    $aExact=!empty($a['date']);$bExact=!empty($b['date']);
    if($aExact&&$bExact&&$a['date']!==$b['date'])return false;
    if(!$aExact&&!$bExact&&$a['day_of_week']!==$b['day_of_week'])return false;
    if($aExact!==$bExact&&$a['day_of_week']!==$b['day_of_week'])return false;
    return $a['start_time']<$b['end_time']&&$a['end_time']>$b['start_time'];
}
function ccSuggestedModule(string $type,string $scheduleType): string {
    if($scheduleType==='Special')return 'special-class';
    if($scheduleType==='Exam')return 'exam-timetable';
    return match($type){'Faculty','Load','Availability'=>'teacher-mapping','Room','Capacity'=>'room-availability','Student/Section','Eligibility'=>'section-assignment','Time','Configured Rule'=>'time-blocks',default=>'teacher-mapping'};
}
function ccAdd(array &$out,array $record,?array $related,string $type,string $severity,string $what,string $why,string $action,?string $module=null): void {
    $module=$module?:ccSuggestedModule($type,$record['schedule_type']);
    $basis=implode('|',[$record['key'],$related['key']??'',$type,$record['date']??$record['day_of_week'],$record['start_time'],$record['end_time'],$what]);
    $key=hash('sha256',$basis);
    if(isset($out[$key]))return;
    $out[$key]=[
        'finding_key'=>$key,'scope'=>ccScope($record['schedule_type']),'reference_id'=>$record['id'],
        'related_scope'=>$related?ccScope($related['schedule_type']):null,'related_reference_id'=>$related['id']??null,
        'conflict_type'=>$type,'severity'=>$severity,'section_id'=>$record['section_id'],'teacher_id'=>$record['teacher_id'],'room_id'=>$record['room_id'],
        'conflict_date'=>$record['date'],'day_of_week'=>$record['day_of_week'],'start_time'=>$record['start_time'],'end_time'=>$record['end_time'],
        'message'=>$what,'explanation'=>$why,'recommended_action'=>$action,'suggested_module'=>$module,
        'affected_record_json'=>json_encode(['primary'=>ccPublicRecord($record),'related'=>$related?ccPublicRecord($related):null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ];
}

function ccValidate(PDO $pdo,array $selected,array $all): array {
    $out=[];
    $qualCount=(int)$pdo->query("SELECT COUNT(*) FROM teacher_subject_qualifications WHERE status='Active'")->fetchColumn();
    $qual=$pdo->prepare("SELECT specialization_label FROM teacher_subject_qualifications WHERE teacher_id=? AND subject_id=? AND status='Active' LIMIT 1");
    $sectionSubject=$pdo->prepare("SELECT 1 FROM section_subjects WHERE section_id=? AND subject_id=? AND status='Assigned' LIMIT 1");
    $teacher=$pdo->prepare('SELECT status,max_load_units FROM teachers WHERE id=?');
    $block=$pdo->prepare("SELECT 1 FROM time_blocks WHERE id=? AND start_time=? AND end_time=? AND block_type='Class' AND is_active=1");
    $examBlock=$pdo->prepare("SELECT 1 FROM time_blocks WHERE id=? AND start_time=? AND end_time=? AND block_type='Exam' AND is_active=1");
    $availability=$pdo->prepare("SELECT availability,start_time,end_time,remarks FROM teacher_availability WHERE teacher_id=? AND day_of_week=? AND (academic_year IS NULL OR academic_year=?) AND (semester IS NULL OR semester=?)");
    $studentEligibility=$pdo->prepare("SELECT eligibility_status,student_name,eligibility_note FROM special_class_students WHERE special_class_id=?");

    $loadByTeacher=[];$maxByTeacher=[];
    foreach($all as $r){if(!$r['teacher_id'])continue;$term=($r['academic_year']??'').'|'.($r['semester']??'');$loadByTeacher[$r['teacher_id'].'|'.$term]=($loadByTeacher[$r['teacher_id'].'|'.$term]??0)+$r['units'];}

    foreach($selected as $r){
        if(!$r['start_time']||!$r['end_time']||$r['start_time']>=$r['end_time'])ccAdd($out,$r,null,'Time','Error','The schedule has an invalid or incomplete time range.','A schedule needs a valid start time earlier than its end time before overlap rules can run.','Choose a valid configured time block.','time-blocks');
        if($r['schedule_type']!=='Exam'){
            if(!$r['time_block_id'])ccAdd($out,$r,null,'Configured Rule','Error','No configured time block is linked to this schedule.','Regular and special classes must use an active Class block from the Time Block Generator.','Select an active class time block.','time-blocks');
            else{$block->execute([$r['time_block_id'],$r['start_time'],$r['end_time']]);if(!$block->fetchColumn())ccAdd($out,$r,null,'Configured Rule','Error','The linked time block is inactive or no longer matches the schedule.','The saved times differ from the shared Time Block Generator record.','Choose a current active time block.','time-blocks');}
        }else{
            if(!$r['time_block_id'])ccAdd($out,$r,null,'Configured Rule','Error','No exam-specific time block is linked to this examination.','Exam schedules must use an active Exam block from the Time Block Generator.','Select an active exam-specific time block.','time-blocks');
            else{$examBlock->execute([$r['time_block_id'],$r['start_time'],$r['end_time']]);if(!$examBlock->fetchColumn())ccAdd($out,$r,null,'Configured Rule','Error','The linked exam time block is inactive or no longer matches the examination.','The exam times differ from the shared Time Block Generator record.','Choose a current active exam-specific time block.','time-blocks');}
        }
        if(!$r['teacher_id'])ccAdd($out,$r,null,'Faculty','Error','No faculty member or proctor is assigned.','Faculty availability and overlap rules cannot be completed without an assigned person.','Assign an active qualified faculty member.');
        else{
            $teacher->execute([$r['teacher_id']]);$t=$teacher->fetch(PDO::FETCH_ASSOC);
            if(!$t||$t['status']!=='Active')ccAdd($out,$r,null,'Eligibility','Error','The assigned faculty member is inactive or missing.','Inactive faculty cannot receive a validated schedule.','Reassign an active faculty member.');
            if($qualCount>0){$qual->execute([$r['teacher_id'],$r['subject_id']]);if($qual->fetchColumn()===false)ccAdd($out,$r,null,'Eligibility','Error','The faculty member has no active qualification for '.$r['subject_code'].'.','The configured teacher-subject qualification map does not include this assignment.','Reassign faculty or update the verified qualification record.');}
            else ccAdd($out,$r,null,'Configured Rule','Warning','Faculty qualification rules are not configured.','The qualification table has no active institutional records, so the checker cannot invent an eligibility decision.','Review or configure faculty qualifications.','teacher-mapping');
            $availability->execute([$r['teacher_id'],$r['day_of_week'],$r['academic_year']??'',$r['semester']??'']);$av=$availability->fetchAll(PDO::FETCH_ASSOC);
            if(!$av)ccAdd($out,$r,null,'Availability','Warning','Faculty availability is not configured for this term.','No availability window exists; saved schedule overlaps are still checked.','Review or configure faculty availability.','teacher-mapping');
            foreach($av as $a)if($a['availability']==='Unavailable'&&$a['start_time']<$r['end_time']&&$a['end_time']>$r['start_time'])ccAdd($out,$r,null,'Availability','Error','The faculty member is unavailable during this schedule.','A configured Unavailable window overlaps the selected time.'.($a['remarks']?' '.$a['remarks']:''),'Move the class or reassign faculty.','teacher-mapping');
            if($t){$term=($r['academic_year']??'').'|'.($r['semester']??'');$load=$loadByTeacher[$r['teacher_id'].'|'.$term]??0;$max=(float)$t['max_load_units'];if($max>0&&$load>$max)ccAdd($out,$r,null,'Load','Error','The faculty load is '.$load.' units, above the '.$max.'-unit limit.','All active regular and special assignments in the same term are included.','Reassign one or more subjects or apply an approved load change.','teacher-mapping');}
        }
        if(!$r['room_id'])ccAdd($out,$r,null,'Room','Error','No room is assigned.','Room availability and capacity cannot be validated without a room.','Select an available room.','room-availability');
        elseif($r['room_status']!=='Available')ccAdd($out,$r,null,'Room','Error','Room '.$r['room_code'].' is marked '.$r['room_status'].'.','Rooms in maintenance or unavailable status cannot be validated.','Choose another available room.','room-availability');
        elseif((int)$r['room_capacity']<(int)$r['participant_count'])ccAdd($out,$r,null,'Capacity','Error','Room '.$r['room_code'].' holds '.$r['room_capacity'].' but the schedule has '.$r['participant_count'].' participant(s).','The assigned room cannot safely accommodate the section or selected students.','Assign a larger room or revise the roster.','room-availability');
        if($r['section_id']){$sectionSubject->execute([$r['section_id'],$r['subject_id']]);if(!$sectionSubject->fetchColumn())ccAdd($out,$r,null,'Eligibility','Error',$r['subject_code'].' is not assigned to '.$r['section_code'].'.','The shared Section Assignment data does not contain this active section-subject pair.','Correct the section subject assignment.','section-assignment');}
        if($r['schedule_type']==='Special'&&$r['assignment_mode']==='Students'){$studentEligibility->execute([$r['id']]);foreach($studentEligibility->fetchAll(PDO::FETCH_ASSOC) as $s){if($s['eligibility_status']==='Not Eligible')ccAdd($out,$r,null,'Eligibility','Error',$s['student_name'].' is not eligible for this special class.',$s['eligibility_note']?:'The saved enrollment eligibility result is Not Eligible.','Replace the student or correct the enrollment record.','special-class');elseif(in_array($s['eligibility_status'],['Conditional','Not Configured'],true))ccAdd($out,$r,null,'Eligibility','Warning',$s['student_name'].' requires eligibility review.',$s['eligibility_note']?:'No conclusive institutional eligibility decision is stored.','Review the student enrollment record.','special-class');}}

        foreach($all as $other){if($other['key']===$r['key']||!ccOverlap($r,$other))continue;
            if($r['teacher_id']&&$other['teacher_id']===$r['teacher_id'])ccAdd($out,$r,$other,'Faculty','Error',$r['teacher_name'].' is assigned to two overlapping schedules.',$r['subject_code'].' ('.$r['section_code'].') overlaps '.$other['subject_code'].' ('.$other['section_code'].') on '.$r['schedule_label'].'.','Change the time or reassign faculty.');
            if($r['room_id']&&$other['room_id']===$r['room_id'])ccAdd($out,$r,$other,'Room','Error','Room '.$r['room_code'].' is assigned to two overlapping schedules.',$r['subject_code'].' ('.$r['section_code'].') overlaps '.$other['subject_code'].' ('.$other['section_code'].').','Change the room or time.','room-availability');
            if($r['section_id']&&$other['section_id']===$r['section_id'])ccAdd($out,$r,$other,'Student/Section','Error','Section '.$r['section_code'].' has two overlapping schedules.',$r['subject_code'].' overlaps '.$other['subject_code'].' during '.$r['schedule_label'].'.','Move one schedule to a different time.','section-assignment');
            $shared=array_intersect($r['student_keys']??[],$other['student_keys']??[]);if($shared)ccAdd($out,$r,$other,'Student/Section','Error',count($shared).' selected student(s) have overlapping special classes.','The same student record appears in both special-class rosters at this time.','Change the time or revise one roster.','special-class');
            $sameSlot=$r['subject_id']===$other['subject_id']&&$r['section_id']===$other['section_id']&&$r['start_time']===$other['start_time']&&$r['end_time']===$other['end_time'];if($sameSlot)ccAdd($out,$r,$other,'Duplicate','Error','A duplicate schedule exists for '.$r['subject_code'].' and '.$r['section_code'].'.','Both records use the same subject, section, and time.','Keep one authoritative record and cancel or correct the duplicate.',ccSuggestedModule('Duplicate',$r['schedule_type']));
        }
    }
    return array_values($out);
}

function ccRunSummary(array $findings,int $selectedCount): array {
    $summary=['selected'=>$selectedCount,'total'=>count($findings),'critical'=>0,'warnings'=>0,'info'=>0,'resolved'=>0,'by_type'=>[]];
    foreach($findings as $f){if($f['severity']==='Error')$summary['critical']++;elseif($f['severity']==='Warning')$summary['warnings']++;else$summary['info']++;$summary['by_type'][$f['conflict_type']]=($summary['by_type'][$f['conflict_type']]??0)+1;}
    return $summary;
}
function ccGetRun(PDO $pdo,string $runId): ?array {
    $s=$pdo->prepare('SELECT * FROM validation_runs WHERE run_id=?');$s->execute([$runId]);$run=$s->fetch(PDO::FETCH_ASSOC);if(!$run)return null;
    $f=$pdo->prepare("SELECT cr.*,sec.code section_code,t.full_name teacher_name,r.room_code FROM conflict_results cr LEFT JOIN sections sec ON sec.id=cr.section_id LEFT JOIN teachers t ON t.id=cr.teacher_id LEFT JOIN rooms r ON r.id=cr.room_id WHERE cr.run_id=? ORDER BY FIELD(cr.severity,'Error','Warning','Info'),cr.id");$f->execute([$runId]);$findings=$f->fetchAll(PDO::FETCH_ASSOC);
    foreach($findings as &$x){$x['affected_records']=json_decode((string)$x['affected_record_json'],true)?:[];$x['module_url']=ccModuleUrl((string)$x['suggested_module'],$x['affected_records']['primary']??[]);}unset($x);
    $rr=$pdo->prepare('SELECT * FROM validation_run_records WHERE run_id=? ORDER BY id');$rr->execute([$runId]);$run['records']=$rr->fetchAll(PDO::FETCH_ASSOC);$run['findings']=$findings;
    return $run;
}

try{
    $pdo=getDatabaseConnection();$method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $action=trim((string)($_GET['action']??'dashboard'));
        if($action==='dashboard'||$action==='options'){
            $records=ccRecords($pdo);$years=[];$semesters=[];$validated=0;foreach($records as $r){if($r['academic_year'])$years[]=$r['academic_year'];if($r['semester'])$semesters[]=$r['semester'];if(in_array($r['status'],['Validated','Ready to Publish','Published'],true))$validated++;}
            $latest=$pdo->query('SELECT * FROM validation_runs ORDER BY created_at DESC,id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC)?:null;
            $stats=['records'=>count($records),'conflicts'=>$latest?(int)$latest['findings_count']:0,'unresolved'=>$latest?(int)$latest['critical_count']:0,'resolved'=>$latest?(int)$latest['resolved_count']:0,'validated'=>$validated];
            echo json_encode(['ok'=>true,'stats'=>$stats,'latest'=>$latest,'academic_years'=>array_values(array_unique($years)),'semesters'=>array_values(array_unique($semesters)),'schedule_types'=>['All','Regular','Special','Exam'],'statuses'=>['All','Draft','Validated','Ready to Publish','Published']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }
        if($action==='queue'){
            $records=ccRecords($pdo);$ay=trim((string)($_GET['academic_year']??''));$sem=trim((string)($_GET['semester']??''));$type=trim((string)($_GET['schedule_type']??'All'));$status=trim((string)($_GET['status']??'All'));$search=strtolower(trim((string)($_GET['search']??'')));
            $filtered=array_values(array_filter($records,function($r)use($ay,$sem,$type,$status,$search){if($ay!==''&&$r['academic_year']!==$ay)return false;if($sem!==''&&$r['semester']!==$sem)return false;if($type!=='All'&&$type!==''&&$r['schedule_type']!==$type)return false;if($status!=='All'&&$status!==''&&$r['status']!==$status)return false;if($search!==''&&!str_contains(strtolower(($r['section_code']??'').' '.($r['subject_code']??'').' '.($r['teacher_name']??'').' '.($r['room_code']??'')),$search))return false;return true;}));
            echo json_encode(['ok'=>true,'records'=>array_map('ccPublicRecord',$filtered),'count'=>count($filtered)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }
        if($action==='run'){$run=ccGetRun($pdo,trim((string)($_GET['run_id']??'')));if(!$run)throw new RuntimeException('Validation run was not found.');echo json_encode(['ok'=>true,'run'=>$run],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        if($action==='conflict'){$id=(int)($_GET['id']??0);$s=$pdo->prepare("SELECT cr.*,sec.code section_code,t.full_name teacher_name,r.room_code FROM conflict_results cr LEFT JOIN sections sec ON sec.id=cr.section_id LEFT JOIN teachers t ON t.id=cr.teacher_id LEFT JOIN rooms r ON r.id=cr.room_id WHERE cr.id=?");$s->execute([$id]);$f=$s->fetch(PDO::FETCH_ASSOC);if(!$f)throw new RuntimeException('Conflict was not found.');$f['affected_records']=json_decode((string)$f['affected_record_json'],true)?:[];$f['module_url']=ccModuleUrl((string)$f['suggested_module'],$f['affected_records']['primary']??[]);echo json_encode(['ok'=>true,'finding'=>$f],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        if($action==='history'){$rows=$pdo->query('SELECT * FROM validation_runs ORDER BY created_at DESC,id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);echo json_encode(['ok'=>true,'runs'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        throw new InvalidArgumentException('Unknown Conflict Checker action.');
    }
    if($method!=='POST')throw new InvalidArgumentException('Method not allowed.');
    $body=ccBody();$action=trim((string)($body['action']??''));
    if(in_array($action,['run','recheck'],true)){
        $all=ccRecords($pdo);$previous=trim((string)($body['previous_run_id']??''));$keys=array_values(array_unique(array_map('strval',$body['record_keys']??[])));
        if($action==='recheck'&&!$keys&&$previous){$s=$pdo->prepare('SELECT record_key FROM validation_run_records WHERE run_id=?');$s->execute([$previous]);$keys=array_column($s->fetchAll(PDO::FETCH_ASSOC),'record_key');}
        if(!$keys)throw new InvalidArgumentException('Select at least one schedule record to validate.');$selected=[];foreach($keys as $key){if(!isset($all[$key]))throw new RuntimeException('A selected schedule no longer exists or is cancelled: '.$key);$selected[$key]=$all[$key];}
        $findings=ccValidate($pdo,$selected,$all);$summary=ccRunSummary($findings,count($selected));$runId='VAL-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(3)),0,6);$status=$summary['critical']?'Has Conflicts':'Valid';
        $ay=trim((string)($body['academic_year']??''));$sem=trim((string)($body['semester']??''));$type=trim((string)($body['schedule_type']??'All'));if(!in_array($type,['All','Regular','Special','Exam'],true))$type='All';
        $pdo->beginTransaction();$runIns=$pdo->prepare('INSERT INTO validation_runs(run_id,previous_run_id,academic_year,semester,schedule_type,selected_count,findings_count,critical_count,warning_count,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$runIns->execute([$runId,$previous?:null,$ay?:null,$sem?:null,$type,count($selected),$summary['total'],$summary['critical'],$summary['warnings'],$status,ccActor()]);
        $previousByKey=[];if($previous){$p=$pdo->prepare('SELECT id,finding_key FROM conflict_results WHERE run_id=?');$p->execute([$previous]);foreach($p->fetchAll(PDO::FETCH_ASSOC) as $x)$previousByKey[$x['finding_key']]=$x['id'];}
        $ins=$pdo->prepare('INSERT INTO conflict_results(run_id,finding_key,scope,reference_id,related_scope,related_reference_id,conflict_type,severity,section_id,teacher_id,room_id,conflict_date,day_of_week,start_time,end_time,affected_record_json,explanation,message,recommended_action,suggested_module,previous_finding_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $newKeys=[];foreach($findings as $f){$newKeys[]=$f['finding_key'];$ins->execute([$runId,$f['finding_key'],$f['scope'],$f['reference_id'],$f['related_scope'],$f['related_reference_id'],$f['conflict_type'],$f['severity'],$f['section_id'],$f['teacher_id'],$f['room_id'],$f['conflict_date'],$f['day_of_week'],$f['start_time'],$f['end_time'],$f['affected_record_json'],$f['explanation'],$f['message'],$f['recommended_action'],$f['suggested_module'],$previousByKey[$f['finding_key']]??null,ccActor()]);}
        $resolved=0;if($previous){foreach($previousByKey as $key=>$id)if(!in_array($key,$newKeys,true)){$pdo->prepare('UPDATE conflict_results SET is_resolved=1,resolved_at=NOW(),rechecked_at=NOW() WHERE id=?')->execute([$id]);$resolved++;}$pdo->prepare('UPDATE validation_runs SET resolved_count=? WHERE run_id=?')->execute([$resolved,$runId]);}
        $findingsByRecord=[];foreach($findings as $f)$findingsByRecord[$f['scope'].':'.$f['reference_id']][]=$f;
        $recIns=$pdo->prepare('INSERT INTO validation_run_records(run_id,record_key,schedule_type,reference_id,before_status,result_status) VALUES(?,?,?,?,?,?)');foreach($selected as $r){$fs=$findingsByRecord[ccScope($r['schedule_type']).':'.$r['id']]??[];$hasError=(bool)array_filter($fs,fn($f)=>$f['severity']==='Error');$hasWarning=(bool)array_filter($fs,fn($f)=>$f['severity']==='Warning');$result=$hasError?'Conflict':($hasWarning?'Warning':'Valid');$recIns->execute([$runId,$r['key'],$r['schedule_type'],$r['id'],$r['status'],$result]);}
        $pdo->commit();logActivity($action==='recheck'?'Recheck conflicts':'Run conflict check',"Conflict Checker {$runId} checked ".count($selected)." schedule record(s) and found {$summary['critical']} critical issue(s).",'scheduling');$run=ccGetRun($pdo,$runId);echo json_encode(['ok'=>true,'run'=>$run,'summary'=>$summary+['resolved'=>$resolved],'message'=>$summary['critical']?"{$summary['critical']} critical conflict(s) must be resolved before completion.":'No critical conflicts remain. The validation result can be completed.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($action==='complete'){
        $runId=trim((string)($body['run_id']??''));$run=ccGetRun($pdo,$runId);if(!$run)throw new RuntimeException('Validation run was not found.');if((int)$run['critical_count']>0)throw new RuntimeException('Unresolved critical conflicts prevent validation completion. Fix them and run Recheck first.');if($run['status']==='Completed')throw new RuntimeException('This validation run is already complete.');
        $pdo->beginTransaction();$regular=$pdo->prepare("UPDATE schedule_entries SET status='Validated' WHERE id=? AND status='Draft'");$special=$pdo->prepare("UPDATE special_classes SET status='Validated',validated_at=NOW() WHERE id=? AND status IN ('Draft','For Scheduling','Scheduled','Validated')");$exam=$pdo->prepare("UPDATE exam_schedules SET status='Validated' WHERE id=? AND status='Draft'");$rr=$pdo->prepare('UPDATE validation_run_records SET after_status=? WHERE run_id=? AND record_key=?');
        foreach($run['records'] as $rec){$after=$rec['before_status'];if($rec['schedule_type']==='Regular'){$regular->execute([(int)$rec['reference_id']]);if($rec['before_status']==='Draft')$after='Validated';}elseif($rec['schedule_type']==='Special'){$special->execute([(int)$rec['reference_id']]);if(in_array($rec['before_status'],['Draft','For Scheduling','Scheduled'],true))$after='Validated';}else{$exam->execute([(int)$rec['reference_id']]);if($rec['before_status']==='Draft')$after='Validated';}$rr->execute([$after,$runId,$rec['record_key']]);}
        $pdo->prepare("UPDATE validation_runs SET status='Completed',completed_at=NOW() WHERE run_id=?")->execute([$runId]);$pdo->commit();logActivity('Complete conflict validation',"Conflict Checker completed {$runId}; selected schedules are validated or retained in their later status.",'scheduling');echo json_encode(['ok'=>true,'message'=>'Validation result saved. Conflict-free draft schedules are now Validated / Ready for Review.','run'=>ccGetRun($pdo,$runId)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($action==='acknowledge'){$id=(int)($body['id']??0);$s=$pdo->prepare("UPDATE conflict_results SET is_resolved=1,resolved_at=NOW() WHERE id=? AND severity IN ('Warning','Info')");$s->execute([$id]);if(!$s->rowCount())throw new RuntimeException('Only warning or informational findings can be acknowledged manually. Critical findings require a schedule fix and recheck.');echo json_encode(['ok'=>true,'message'=>'Warning acknowledged.']);exit;}
    throw new InvalidArgumentException('Unknown Conflict Checker action.');
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('Conflict Check API: '.$e->getMessage());$safe=!($e instanceof PDOException)&&($e instanceof InvalidArgumentException||$e instanceof RuntimeException)?$e->getMessage():'Conflict check failed. Please try again or contact the administrator.';http_response_code($e instanceof InvalidArgumentException?422:500);echo json_encode(['ok'=>false,'error'=>$safe],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
