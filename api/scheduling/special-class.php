<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('isAuthenticated') && !isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}
if (function_exists('userCanAccessModule') && !userCanAccessModule('scheduling')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to access Class Scheduling.']);
    exit;
}

const SPC_TYPES = ['Remedial','Irregular','Midyear','Overload','Makeup','Cross-enrollment','Tutorial','Review','Seminar','Other'];
const SPC_STATUSES = ['Draft','For Scheduling','Scheduled','Validated','Ready to Publish','Published','Cancelled'];

function spcBody(): array {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) throw new InvalidArgumentException('Invalid JSON request body.');
    return $body;
}
function spcTime(string $value, string $label): string {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $m)) throw new InvalidArgumentException($label . ' is invalid.');
    if ((int)$m[1] > 23 || (int)$m[2] > 59) throw new InvalidArgumentException($label . ' is invalid.');
    return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
}
function spcDate(string $value): array {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Class date is invalid.');
    return [$value, $date->format('l')];
}
function spcShortTime(?string $value): string { return $value ? date('g:i A', strtotime($value)) : ''; }
function spcActorId(): ?int { return function_exists('getCurrentUserId') ? getCurrentUserId() : null; }
function spcFinding(string $key, string $label, string $message, string $action = '', string $level = 'error'): array {
    return compact('key','label','message','action','level');
}
function spcHistory(PDO $pdo, int $id, string $action, ?string $from, ?string $to, string $detail): void {
    $stmt = $pdo->prepare('INSERT INTO special_class_history (special_class_id,action,from_status,to_status,detail,actor_user_id) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$id,$action,$from,$to,mb_substr($detail,0,500),spcActorId()]);
}
function spcTableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function spcStudents(PDO $pdo): array {
    $students = [];
    foreach ($pdo->query("SELECT id,student_id,full_name,email,status FROM users WHERE role_key='student' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $students['user:'.$r['id']] = [
            'key'=>'user:'.$r['id'],'student_user_id'=>(int)$r['id'],'pre_registration_id'=>null,
            'student_ref'=>$r['student_id'] ?: 'USER-'.$r['id'],'name'=>$r['full_name'],'email'=>$r['email'],
            'program'=>'','year_level'=>null,'record_status'=>$r['status'],
            'eligibility_status'=>$r['status']==='active'?'Not Configured':'Not Eligible',
            'eligibility_note'=>$r['status']==='active'?'No enrollment eligibility rule is configured for this student record.':'Student account is not active.'
        ];
    }
    if (!spcTableExists($pdo,'enr_pre_registrations') || !spcTableExists($pdo,'enr_applicant_profiles')) {
        return array_values($students);
    }
    $sql = "SELECT pr.id,pr.student_ref,pr.program_ref,pr.year_level_claimed,pr.status,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ',ap.first_name,ap.middle_name,ap.last_name)),''),
                     CASE WHEN u.role_key='student' THEN u.full_name ELSE NULL END,
                     CONCAT('Student ',COALESCE(pr.student_ref,pr.id))) student_name,
                   COALESCE(ap.email,CASE WHEN u.role_key='student' THEN u.email ELSE NULL END) email
              FROM enr_pre_registrations pr LEFT JOIN enr_applicant_profiles ap ON ap.id=pr.applicant_profile_id
              LEFT JOIN users u ON u.id=pr.user_id WHERE pr.status<>'cancelled' ORDER BY student_name";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $students['prereg:'.$r['id']] = [
            'key'=>'prereg:'.$r['id'],'student_user_id'=>null,'pre_registration_id'=>(int)$r['id'],
            'student_ref'=>$r['student_ref'] ?: 'PR-'.$r['id'],'name'=>$r['student_name'],'email'=>$r['email']??'',
            'program'=>$r['program_ref']??'','year_level'=>$r['year_level_claimed']!==null?(int)$r['year_level_claimed']:null,
            'record_status'=>$r['status'],'eligibility_status'=>$r['status']==='validated'?'Conditional':'Not Configured',
            'eligibility_note'=>$r['status']==='validated'?'Enrollment is validated; subject eligibility is checked when a subject is selected.':'Enrollment validation is not complete; no automatic subject decision is available.'
        ];
    }
    return array_values($students);
}
function spcStudentEligibility(PDO $pdo, array $students, int $subjectId): array {
    if ($subjectId<=0 || !$students) return $students;
    $stmt=$pdo->prepare('SELECT code FROM subjects WHERE id=?'); $stmt->execute([$subjectId]); $code=(string)($stmt->fetchColumn()?:'');
    if ($code==='' || !spcTableExists($pdo,'enr_pre_registration_subjects')) return $students;
    $check=$pdo->prepare("SELECT prereq_status FROM enr_pre_registration_subjects WHERE pre_reg_id=? AND (subject_ref=? OR subject_code_snapshot=?) LIMIT 1");
    foreach ($students as &$s) {
        if (empty($s['pre_registration_id'])) continue;
        $check->execute([(int)$s['pre_registration_id'],(string)$subjectId,$code]); $status=$check->fetchColumn();
        if (in_array($status,['met','waived','retake'],true)) { $s['eligibility_status']='Eligible'; $s['eligibility_note']='Enrollment subject record is '.$status.'.'; }
        elseif ($status==='conditional') { $s['eligibility_status']='Conditional'; $s['eligibility_note']='Subject eligibility is conditional and requires staff review.'; }
    }
    unset($s); return $students;
}
function spcSelectedStudents(PDO $pdo, int $id): array {
    $stmt=$pdo->prepare('SELECT student_key `key`,student_user_id,pre_registration_id,student_ref,student_name name,program,year_level,eligibility_status,eligibility_note FROM special_class_students WHERE special_class_id=? ORDER BY student_name');
    $stmt->execute([$id]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function spcPayload(array $source): array {
    [$date,$weekday]=spcDate(trim((string)($source['class_date']??'')));
    $start=spcTime((string)($source['start_time']??''),'Start time'); $end=spcTime((string)($source['end_time']??''),'End time');
    if ($start >= $end) throw new InvalidArgumentException('Start time must be earlier than end time.');
    $title=trim((string)($source['title']??'')); if ($title==='') throw new InvalidArgumentException('Class title is required.');
    $type=trim((string)($source['special_type']??'Other')); if (!in_array($type,SPC_TYPES,true)) throw new InvalidArgumentException('Special class type is invalid.');
    $mode=trim((string)($source['assignment_mode']??'Section')); if (!in_array($mode,['Section','Students'],true)) throw new InvalidArgumentException('Student assignment mode is invalid.');
    $keys=array_values(array_unique(array_filter(array_map('strval',$source['student_keys']??[]))));
    return ['id'=>(int)($source['id']??0),'title'=>mb_substr($title,0,150),'special_type'=>$type,
        'academic_year'=>mb_substr(trim((string)($source['academic_year']??'')),0,20),'semester'=>mb_substr(trim((string)($source['semester']??'')),0,30),
        'program'=>mb_substr(trim((string)($source['program']??'')),0,30),'assignment_mode'=>$mode,
        'section_id'=>(int)($source['section_id']??0)?:null,'subject_id'=>(int)($source['subject_id']??0)?:null,
        'teacher_id'=>(int)($source['teacher_id']??0)?:null,'room_id'=>(int)($source['room_id']??0)?:null,
        'time_block_id'=>(int)($source['time_block_id']??0)?:null,'class_date'=>$date,'weekday'=>$weekday,
        'start_time'=>$start,'end_time'=>$end,'remarks'=>mb_substr(trim((string)($source['remarks']??'')),0,255),'student_keys'=>$keys];
}

function spcValidate(PDO $pdo, array $d, int $exclude=0): array {
    $errors=[]; $warnings=[]; $passes=[];
    $pass=static function($k,$l,$m)use(&$passes){$passes[]=spcFinding($k,$l,$m,'','pass');};
    $subject=null;
    if (!$d['subject_id']) $errors[]=spcFinding('subject','Subject required','Select an active subject.','Edit Class Details');
    else { $s=$pdo->prepare('SELECT code,name,units,status FROM subjects WHERE id=?');$s->execute([$d['subject_id']]);$subject=$s->fetch(PDO::FETCH_ASSOC);
        if (!$subject||$subject['status']!=='Active') $errors[]=spcFinding('subject','Invalid subject','The selected subject is inactive or missing.','Edit Class Details');
        else $pass('subject','Subject active',$subject['code'].' is active.'); }

    $count=0;
    if ($d['assignment_mode']==='Section') {
        if (!$d['section_id']) $errors[]=spcFinding('section','Section required','Select a section.','Edit Students / Section');
        else { $s=$pdo->prepare('SELECT code,current_students,status FROM sections WHERE id=?');$s->execute([$d['section_id']]);$sec=$s->fetch(PDO::FETCH_ASSOC);
            if (!$sec||$sec['status']!=='Active') $errors[]=spcFinding('section','Invalid section','The section is inactive or missing.','Edit Students / Section');
            else { $count=(int)$sec['current_students'];$x=$pdo->prepare("SELECT 1 FROM section_subjects WHERE section_id=? AND subject_id=? AND status='Assigned'");$x->execute([$d['section_id'],$d['subject_id']]);
                if (!$x->fetchColumn()) $errors[]=spcFinding('section_subject','Subject not assigned','The subject is not assigned to '.$sec['code'].'.','Edit Class Details or Section');
                else $pass('section_subject','Section eligible',$sec['code'].' is assigned to the selected subject.'); }}
    } else {
        $count=count($d['student_keys']);
        if (!$count) $errors[]=spcFinding('students','Students required','Select at least one student.','Edit Students / Section');
        else { $all=spcStudentEligibility($pdo,spcStudents($pdo),(int)$d['subject_id']);$by=[];foreach($all as $s)$by[$s['key']]=$s;
            foreach($d['student_keys'] as $key){if(!isset($by[$key])){$errors[]=spcFinding('student_missing','Invalid student','A selected student is no longer available.','Edit Students / Section');continue;}
                if($by[$key]['eligibility_status']==='Not Eligible')$errors[]=spcFinding('student_eligibility','Student not eligible',$by[$key]['name'].': '.$by[$key]['eligibility_note'],'Replace Student');
                elseif(in_array($by[$key]['eligibility_status'],['Conditional','Not Configured'],true))$warnings[]=spcFinding('student_eligibility','Eligibility review',$by[$key]['name'].': '.$by[$key]['eligibility_note'],'Review enrollment record','warning');}
            if(!array_filter($errors,fn($f)=>str_starts_with($f['key'],'student')))$pass('students','Students selected',$count.' student(s) are included.'); }
    }

    if(!$d['teacher_id'])$errors[]=spcFinding('teacher','Teacher required','Select a faculty member.','Edit Faculty');
    else{$s=$pdo->prepare('SELECT full_name,max_load_units,status FROM teachers WHERE id=?');$s->execute([$d['teacher_id']]);$t=$s->fetch(PDO::FETCH_ASSOC);
        if(!$t||$t['status']!=='Active')$errors[]=spcFinding('teacher','Teacher inactive','The faculty member is inactive or missing.','Replace Faculty');
        else{$pass('teacher','Teacher active',$t['full_name'].' is active.');$qc=(int)$pdo->query("SELECT COUNT(*) FROM teacher_subject_qualifications WHERE status='Active'")->fetchColumn();
            if($qc){$q=$pdo->prepare("SELECT specialization_label FROM teacher_subject_qualifications WHERE teacher_id=? AND subject_id=? AND status='Active'");$q->execute([$d['teacher_id'],$d['subject_id']]);$spec=$q->fetchColumn();
                if($spec===false)$errors[]=spcFinding('teacher_qualification','Teacher not qualified','No active qualification maps this teacher to the subject.','Replace Faculty');else$pass('teacher_qualification','Teacher qualified',$spec?:'Active subject qualification found.');}
            else$warnings[]=spcFinding('teacher_qualification','Qualification map not configured','No institution-wide teacher qualification records exist; staff review is required.','Configure faculty qualifications','warning');
            $l=$pdo->prepare("SELECT COALESCE(SUM(sub.units),0) FROM schedule_entries se JOIN subjects sub ON sub.id=se.subject_id WHERE se.teacher_id=? AND se.status IN ('Draft','Validated','Published') AND se.academic_year<=>? AND se.semester<=>?");$l->execute([$d['teacher_id'],$d['academic_year']?:null,$d['semester']?:null]);$load=(float)$l->fetchColumn();
            $sl=$pdo->prepare("SELECT COALESCE(SUM(sub.units),0) FROM special_classes sc JOIN subjects sub ON sub.id=sc.subject_id WHERE sc.teacher_id=? AND sc.id<>? AND sc.status<>'Cancelled' AND sc.academic_year<=>? AND sc.semester<=>?");$sl->execute([$d['teacher_id'],$exclude,$d['academic_year']?:null,$d['semester']?:null]);$projected=$load+(float)$sl->fetchColumn()+(float)($subject['units']??0);$max=(float)$t['max_load_units'];
            if($max>0&&$projected>$max)$errors[]=spcFinding('teacher_load','Teaching load exceeded',$t['full_name'].' would have '.$projected.' / '.$max.' units.','Replace Faculty');else$pass('teacher_load','Teaching load valid',$t['full_name'].' will have '.$projected.' / '.($max?:'unconfigured').' units.');}}

    if(!$d['time_block_id'])$errors[]=spcFinding('time_block','Time block required','Select a configured class time block.','Edit Schedule & Room');
    else{$s=$pdo->prepare("SELECT 1 FROM time_blocks WHERE id=? AND start_time=? AND end_time=? AND block_type='Class' AND is_active=1");$s->execute([$d['time_block_id'],$d['start_time'],$d['end_time']]);if(!$s->fetchColumn())$errors[]=spcFinding('time_block','Invalid time block','The time is not an active class block from Time Block Generator.','Choose Another Time');else$pass('time_block','Time block valid','The time is an active configured class block.');}
    if(!$d['room_id'])$errors[]=spcFinding('room','Room required','Select a room.','Edit Schedule & Room');
    else{$s=$pdo->prepare('SELECT room_code,capacity,status FROM rooms WHERE id=?');$s->execute([$d['room_id']]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r||$r['status']!=='Available')$errors[]=spcFinding('room','Room unavailable','The room is not available for scheduling.','Choose Another Room');elseif((int)$r['capacity']<$count)$errors[]=spcFinding('room_capacity','Room capacity insufficient',$r['room_code'].' holds '.$r['capacity'].' but '.$count.' participant(s) are assigned.','Choose Larger Room');else$pass('room','Room valid',$r['room_code'].' is available and has sufficient capacity.');}

    $reg=$pdo->prepare("SELECT se.teacher_id,se.room_id,se.section_id,sec.code section_code,sub.code subject_code,t.full_name,r.room_code FROM schedule_entries se JOIN sections sec ON sec.id=se.section_id JOIN subjects sub ON sub.id=se.subject_id LEFT JOIN teachers t ON t.id=se.teacher_id LEFT JOIN rooms r ON r.id=se.room_id WHERE se.status IN ('Draft','Validated','Published') AND se.day_of_week=? AND se.start_time<? AND se.end_time>? AND se.academic_year<=>? AND se.semester<=>?");
    $reg->execute([$d['weekday'],$d['end_time'],$d['start_time'],$d['academic_year']?:null,$d['semester']?:null]);
    foreach($reg->fetchAll(PDO::FETCH_ASSOC) as $r){if($d['teacher_id']&&(int)$r['teacher_id']===(int)$d['teacher_id'])$errors[]=spcFinding('teacher_conflict','Teacher conflict',($r['full_name']?:'Teacher').' already teaches '.$r['subject_code'].' for '.$r['section_code'].'.','Choose Another Faculty or Time');if($d['room_id']&&(int)$r['room_id']===(int)$d['room_id'])$errors[]=spcFinding('room_conflict','Room conflict',($r['room_code']?:'Room').' is occupied by '.$r['section_code'].' – '.$r['subject_code'].'.','Choose Another Room or Time');if($d['section_id']&&(int)$r['section_id']===(int)$d['section_id'])$errors[]=spcFinding('section_conflict','Section conflict',$r['section_code'].' already has '.$r['subject_code'].' at this time.','Choose Another Time');}
    $sp=$pdo->prepare("SELECT sc.title,sc.teacher_id,sc.room_id,sc.section_id,t.full_name,r.room_code,sec.code section_code FROM special_classes sc LEFT JOIN teachers t ON t.id=sc.teacher_id LEFT JOIN rooms r ON r.id=sc.room_id LEFT JOIN sections sec ON sec.id=sc.section_id WHERE sc.id<>? AND sc.class_date=? AND sc.status<>'Cancelled' AND sc.start_time<? AND sc.end_time>?");$sp->execute([$exclude,$d['class_date'],$d['end_time'],$d['start_time']]);
    foreach($sp->fetchAll(PDO::FETCH_ASSOC) as $r){if($d['teacher_id']&&(int)$r['teacher_id']===(int)$d['teacher_id'])$errors[]=spcFinding('teacher_conflict','Teacher conflict',($r['full_name']?:'Teacher').' is assigned to “'.$r['title'].'”.','Choose Another Faculty or Time');if($d['room_id']&&(int)$r['room_id']===(int)$d['room_id'])$errors[]=spcFinding('room_conflict','Room conflict',($r['room_code']?:'Room').' is assigned to “'.$r['title'].'”.','Choose Another Room or Time');if($d['section_id']&&(int)$r['section_id']===(int)$d['section_id'])$errors[]=spcFinding('section_conflict',($r['section_code']?:'Section').' conflict',($r['section_code']?:'Section').' is assigned to “'.$r['title'].'”.','Choose Another Time');}
    if($d['subject_id']){$q=$pdo->prepare("SELECT title FROM special_classes WHERE id<>? AND subject_id=? AND class_date=? AND start_time=? AND end_time=? AND status<>'Cancelled' LIMIT 1");$q->execute([$exclude,$d['subject_id'],$d['class_date'],$d['start_time'],$d['end_time']]);if($title=$q->fetchColumn())$errors[]=spcFinding('duplicate','Possible duplicate','A matching subject session already exists: “'.$title.'”.','Review Existing Class');}
    if($d['assignment_mode']==='Students'&&$d['student_keys']){$ph=implode(',',array_fill(0,count($d['student_keys']),'?'));$q=$pdo->prepare("SELECT DISTINCT sc.title,scs.student_name FROM special_class_students scs JOIN special_classes sc ON sc.id=scs.special_class_id WHERE sc.id<>? AND sc.class_date=? AND sc.status<>'Cancelled' AND sc.start_time<? AND sc.end_time>? AND scs.student_key IN ($ph)");$q->execute(array_merge([$exclude,$d['class_date'],$d['end_time'],$d['start_time']],$d['student_keys']));foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$errors[]=spcFinding('student_conflict','Student conflict',$r['student_name'].' is already assigned to “'.$r['title'].'”.','Replace Student or Time');}
    if($d['teacher_id']){$q=$pdo->prepare("SELECT availability,start_time,end_time,remarks FROM teacher_availability WHERE teacher_id=? AND day_of_week=? AND (academic_year IS NULL OR academic_year=?) AND (semester IS NULL OR semester=?)");$q->execute([$d['teacher_id'],$d['weekday'],$d['academic_year'],$d['semester']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r)if($r['availability']==='Unavailable'&&$r['start_time']<$d['end_time']&&$r['end_time']>$d['start_time'])$errors[]=spcFinding('teacher_availability','Teacher unavailable','Faculty availability marks this period unavailable.'.($r['remarks']?' '.$r['remarks']:''),'Choose Another Faculty or Time');if(!$rows)$warnings[]=spcFinding('teacher_availability','Availability map not configured','No availability windows are stored for this teacher and term; schedule conflicts were still checked.','Configure Faculty Availability','warning');elseif(!array_filter($errors,fn($f)=>$f['key']==='teacher_availability'))$pass('teacher_availability','Teacher availability valid','No unavailable window overlaps the selected time.');}
    if(!array_filter($errors,fn($f)=>in_array($f['key'],['teacher_conflict','room_conflict','section_conflict','student_conflict','duplicate'],true)))$pass('conflicts','No schedule conflicts','Teacher, room, section, student, and duplicate checks are clear.');
    return ['valid'=>count($errors)===0,'errors'=>$errors,'warnings'=>$warnings,'passes'=>$passes,'checked_at'=>date(DATE_ATOM),'participant_count'=>$count];
}

function spcRecord(PDO $pdo,int $id):?array{
    $stmt=$pdo->prepare("SELECT sc.*,sub.code subject_code,sub.name subject_name,sub.units,sec.code section_code,sec.name section_name,sec.current_students,t.employee_no,t.full_name teacher_name,t.department,r.room_code,r.building,r.room_type,r.capacity,tb.code time_block_code,tb.label time_block_label FROM special_classes sc LEFT JOIN subjects sub ON sub.id=sc.subject_id LEFT JOIN sections sec ON sec.id=sc.section_id LEFT JOIN teachers t ON t.id=sc.teacher_id LEFT JOIN rooms r ON r.id=sc.room_id LEFT JOIN time_blocks tb ON tb.id=sc.time_block_id WHERE sc.id=?");$stmt->execute([$id]);$r=$stmt->fetch(PDO::FETCH_ASSOC);if(!$r)return null;$r['id']=(int)$r['id'];$r['weekday']=date('l',strtotime($r['class_date']));$r['time_label']=spcShortTime($r['start_time']).' – '.spcShortTime($r['end_time']);$r['students']=spcSelectedStudents($pdo,$id);$h=$pdo->prepare("SELECT h.action,h.from_status,h.to_status,h.detail,h.created_at,COALESCE(u.full_name,'System') actor_name FROM special_class_history h LEFT JOIN users u ON u.id=h.actor_user_id WHERE h.special_class_id=? ORDER BY h.created_at DESC,h.id DESC");$h->execute([$id]);$r['history']=$h->fetchAll(PDO::FETCH_ASSOC);return $r;
}

try{
    $pdo=getDatabaseConnection();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=trim((string)($_GET['action']??'options'));
        if($action==='options'){
            $ay=trim((string)($_GET['academic_year']??''));$sem=trim((string)($_GET['semester']??''));
            $sections=$pdo->query("SELECT id,code,name,program,year_level,semester,academic_year,current_students,max_students,status FROM sections WHERE status='Active' ORDER BY academic_year DESC,code")->fetchAll(PDO::FETCH_ASSOC);
            $subjects=$pdo->query("SELECT id,code,name,units,subject_type,program,year_level,semester,status FROM subjects WHERE status='Active' ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
            $ts=$pdo->prepare("SELECT t.id,t.employee_no,t.full_name,t.department,t.email,t.max_load_units,t.status,COALESCE(SUM(CASE WHEN se.status IN ('Draft','Validated','Published') AND (?='' OR se.academic_year=?) AND (?='' OR se.semester=?) THEN sub.units ELSE 0 END),0) current_load_units FROM teachers t LEFT JOIN schedule_entries se ON se.teacher_id=t.id LEFT JOIN subjects sub ON sub.id=se.subject_id GROUP BY t.id ORDER BY t.full_name");$ts->execute([$ay,$ay,$sem,$sem]);$teachers=$ts->fetchAll(PDO::FETCH_ASSOC);
            $quals=$pdo->query("SELECT q.teacher_id,q.subject_id,q.specialization_label,sub.code subject_code,sub.name subject_name FROM teacher_subject_qualifications q JOIN subjects sub ON sub.id=q.subject_id WHERE q.status='Active'")->fetchAll(PDO::FETCH_ASSOC);$qb=[];foreach($quals as $q)$qb[(int)$q['teacher_id']][]=$q;foreach($teachers as &$t)$t['qualifications']=$qb[(int)$t['id']]??[];unset($t);
            $rooms=$pdo->query('SELECT id,room_code,building,room_type,capacity,status FROM rooms ORDER BY room_code')->fetchAll(PDO::FETCH_ASSOC);
            $blocks=$pdo->query("SELECT id,code,label,start_time,end_time FROM time_blocks WHERE is_active=1 AND block_type='Class' ORDER BY start_time")->fetchAll(PDO::FETCH_ASSOC);
            $schedules=$pdo->query("SELECT se.id,se.teacher_id,se.room_id,se.section_id,se.day_of_week,se.start_time,se.end_time,se.status,se.academic_year,se.semester,sub.code subject_code,sec.code section_code,r.room_code FROM schedule_entries se JOIN subjects sub ON sub.id=se.subject_id JOIN sections sec ON sec.id=se.section_id LEFT JOIN rooms r ON r.id=se.room_id WHERE se.status IN ('Draft','Validated','Published')")->fetchAll(PDO::FETCH_ASSOC);
            $years=array_values(array_unique(array_filter(array_column($sections,'academic_year'))));$programs=array_values(array_unique(array_filter(array_merge(array_column($sections,'program'),array_column($subjects,'program')),fn($p)=>$p!=='ALL')));
            echo json_encode(['ok'=>true,'types'=>SPC_TYPES,'statuses'=>SPC_STATUSES,'sections'=>$sections,'subjects'=>$subjects,'teachers'=>$teachers,'rooms'=>$rooms,'time_blocks'=>$blocks,'students'=>spcStudents($pdo),'schedule_entries'=>$schedules,'academic_years'=>$years,'programs'=>$programs,'semesters'=>['1st Semester','2nd Semester','Summer'],'qualification_configured'=>count($quals)>0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }
        if($action==='students'){echo json_encode(['ok'=>true,'students'=>spcStudentEligibility($pdo,spcStudents($pdo),(int)($_GET['subject_id']??0))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        if($action==='check'){$src=$_GET;if(isset($src['student_keys'])&&is_string($src['student_keys']))$src['student_keys']=array_filter(explode(',',$src['student_keys']));$d=spcPayload($src);echo json_encode(['ok'=>true,'validation'=>spcValidate($pdo,$d,$d['id'])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        if($action==='details'){$r=spcRecord($pdo,(int)($_GET['id']??0));if(!$r)throw new RuntimeException('Special class was not found.');echo json_encode(['ok'=>true,'record'=>$r],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        if($action==='list'){$status=trim((string)($_GET['status']??''));$search=trim((string)($_GET['search']??''));$sql="SELECT sc.id,sc.reference_no,sc.title,sc.special_type,sc.class_date,sc.start_time,sc.end_time,sc.status,sc.assignment_mode,sub.code subject_code,sec.code section_code,t.full_name teacher_name,r.room_code,(SELECT COUNT(*) FROM special_class_students x WHERE x.special_class_id=sc.id) student_count FROM special_classes sc LEFT JOIN subjects sub ON sub.id=sc.subject_id LEFT JOIN sections sec ON sec.id=sc.section_id LEFT JOIN teachers t ON t.id=sc.teacher_id LEFT JOIN rooms r ON r.id=sc.room_id WHERE 1=1";$p=[];if($status!==''&&in_array($status,SPC_STATUSES,true)){$sql.=' AND sc.status=:status';$p['status']=$status;}if($search!==''){$sql.=' AND (sc.reference_no LIKE :search OR sc.title LIKE :search OR sub.code LIKE :search OR t.full_name LIKE :search)';$p['search']='%'.$search.'%';}$sql.=' ORDER BY sc.created_at DESC,sc.id DESC LIMIT 200';$s=$pdo->prepare($sql);$s->execute($p);$rows=$s->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$r){$r['weekday']=date('l',strtotime($r['class_date']));$r['time_label']=spcShortTime($r['start_time']).' – '.spcShortTime($r['end_time']);}unset($r);echo json_encode(['ok'=>true,'records'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        throw new InvalidArgumentException('Unknown Special Class Scheduler action.');
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new InvalidArgumentException('Method not allowed.');
    $body=spcBody();$action=trim((string)($body['action']??''));
    if($action==='save'){
        $d=spcPayload($body);$mode=($body['save_mode']??'draft')==='confirm'?'confirm':'draft';$pdo->beginTransaction();$pdo->query("SELECT id FROM special_classes WHERE status<>'Cancelled' FOR UPDATE");$validation=spcValidate($pdo,$d,$d['id']);if($mode==='confirm'&&!$validation['valid'])throw new RuntimeException('This class cannot be confirmed while critical validation issues remain.');$status=$mode==='confirm'?'Ready to Publish':'Draft';$old=null;
        $params=$d;unset($params['weekday'],$params['student_keys']);$params['status']=$status;$params['is_confirm']=$mode==='confirm'?1:0;
        if($d['id']>0){$f=$pdo->prepare('SELECT status FROM special_classes WHERE id=?');$f->execute([$d['id']]);$old=$f->fetchColumn();if($old===false)throw new RuntimeException('The special class being edited was not found.');if($old==='Published')throw new RuntimeException('A published class cannot be edited. Cancel it and create a replacement.');$s=$pdo->prepare("UPDATE special_classes SET title=:title,special_type=:special_type,academic_year=:academic_year,semester=:semester,program=:program,assignment_mode=:assignment_mode,section_id=:section_id,subject_id=:subject_id,teacher_id=:teacher_id,room_id=:room_id,time_block_id=:time_block_id,class_date=:class_date,start_time=:start_time,end_time=:end_time,status=:status,remarks=:remarks,validated_at=CASE WHEN :is_confirm=1 THEN NOW() ELSE NULL END WHERE id=:id");$s->execute($params);$id=$d['id'];$verb='Updated';}
        else{unset($params['id']);$params['created_by']=spcActorId();$s=$pdo->prepare("INSERT INTO special_classes(title,special_type,academic_year,semester,program,assignment_mode,section_id,subject_id,teacher_id,room_id,time_block_id,class_date,start_time,end_time,status,remarks,validated_at,created_by) VALUES(:title,:special_type,:academic_year,:semester,:program,:assignment_mode,:section_id,:subject_id,:teacher_id,:room_id,:time_block_id,:class_date,:start_time,:end_time,:status,:remarks,CASE WHEN :is_confirm=1 THEN NOW() ELSE NULL END,:created_by)");$s->execute($params);$id=(int)$pdo->lastInsertId();$ref='SC-'.date('Y').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);$pdo->prepare('UPDATE special_classes SET reference_no=? WHERE id=?')->execute([$ref,$id]);$verb='Created';}
        $pdo->prepare('DELETE FROM special_class_students WHERE special_class_id=?')->execute([$id]);
        if($d['assignment_mode']==='Students'&&$d['student_keys']){$all=spcStudentEligibility($pdo,spcStudents($pdo),(int)$d['subject_id']);$by=[];foreach($all as $x)$by[$x['key']]=$x;$ins=$pdo->prepare('INSERT INTO special_class_students(special_class_id,student_key,student_user_id,pre_registration_id,student_ref,student_name,program,year_level,eligibility_status,eligibility_note) VALUES(?,?,?,?,?,?,?,?,?,?)');foreach($d['student_keys'] as $key)if(isset($by[$key])){$x=$by[$key];$ins->execute([$id,$key,$x['student_user_id'],$x['pre_registration_id'],$x['student_ref'],$x['name'],$x['program']?:null,$x['year_level'],$x['eligibility_status'],$x['eligibility_note']]);}}
        spcHistory($pdo,$id,$verb,$old,$status,$verb.' through the Special Class Scheduler workflow.');$pdo->commit();$record=spcRecord($pdo,$id);logActivity('Save special class',$verb.' '.($record['reference_no']?:'special class #'.$id).' as '.$status.'.','scheduling');echo json_encode(['ok'=>true,'record'=>$record,'validation'=>$validation,'message'=>$mode==='confirm'?'Special class validated and saved as Ready to Publish.':'Special class saved as Draft. It has not been published.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($action==='update_status'){$id=(int)($body['id']??0);$target=trim((string)($body['status']??''));if($id<=0||!in_array($target,['Published','Cancelled'],true))throw new InvalidArgumentException('Invalid status request.');$r=spcRecord($pdo,$id);if(!$r)throw new RuntimeException('Special class was not found.');if($target==='Published'){if($r['status']!=='Ready to Publish')throw new RuntimeException('Only a Ready to Publish class can be published.');$d=spcPayload([...$r,'student_keys'=>array_column($r['students'],'key')]);$pdo->beginTransaction();$pdo->query("SELECT id FROM special_classes WHERE status<>'Cancelled' FOR UPDATE");$v=spcValidate($pdo,$d,$id);if(!$v['valid'])throw new RuntimeException('Publication stopped because a new conflict or invalid value was detected. Reopen and resolve it first.');$pdo->prepare("UPDATE special_classes SET status='Published',published_at=NOW() WHERE id=?")->execute([$id]);spcHistory($pdo,$id,'Published',$r['status'],'Published','Published after a fresh conflict, availability, capacity, and eligibility recheck.');$pdo->commit();$message='Special class published successfully.';}else{if($r['status']==='Cancelled')throw new RuntimeException('This class is already cancelled.');$pdo->beginTransaction();$pdo->prepare("UPDATE special_classes SET status='Cancelled' WHERE id=?")->execute([$id]);spcHistory($pdo,$id,'Cancelled',$r['status'],'Cancelled','Class cancelled and retained for audit history.');$pdo->commit();$message='Special class cancelled and retained in history.';}logActivity($target.' special class',($r['reference_no']?:'Special class #'.$id).' marked '.$target.'.','scheduling');echo json_encode(['ok'=>true,'message'=>$message,'record'=>spcRecord($pdo,$id)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
    throw new InvalidArgumentException('Unknown Special Class Scheduler action.');
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$safe=!($e instanceof PDOException)&&($e instanceof InvalidArgumentException||$e instanceof RuntimeException)?$e->getMessage():'Special Class Scheduler failed. Please try again or contact the administrator.';http_response_code($e instanceof InvalidArgumentException?422:500);echo json_encode(['ok'=>false,'error'=>$safe],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
