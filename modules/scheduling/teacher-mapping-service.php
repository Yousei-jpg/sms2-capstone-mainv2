<?php
declare(strict_types=1);
require_once __DIR__.'/conflict-rules.php';

function tmRevision(?array $entry): string {
    if (!$entry) return 'new';
    $keys=['id','section_id','subject_id','teacher_id','room_id','time_block_id','day_of_week','start_time','end_time','status','updated_at'];
    $values=[];foreach($keys as $key)$values[$key]=(string)($entry[$key]??'');
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
}

/** Save only teacher/time requirements; full room validation remains mandatory downstream. */
function tmProcess(PDO $pdo,array $p,bool $save): array {
    $pdo->beginTransaction();
    try {
        // Take range locks before any consistent reads establish a snapshot.
        // The final checker then sees the latest committed scheduling state.
        $pdo->query('SELECT id FROM schedule_entries FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM special_classes FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM exam_schedules FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM teacher_availability FOR UPDATE')->fetchAll();
        $one=static function(string $sql,array $args=[]) use($pdo): ?array {$s=$pdo->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:null;};
        $section=$one("SELECT * FROM sections WHERE id=? AND status='Active' FOR UPDATE",[(int)($p['section_id']??0)]);
        if(!$section)throw new InvalidArgumentException('Select an active section.');
        $subject=$one("SELECT sub.* FROM section_subjects ss JOIN subjects sub ON sub.id=ss.subject_id WHERE ss.section_id=? AND ss.subject_id=? AND ss.status='Assigned' AND sub.status='Active' FOR UPDATE",[$section['id'],(int)($p['subject_id']??0)]);
        if(!$subject)throw new InvalidArgumentException('This active subject is not assigned to the section. Refresh Section Assignment.');
        $teacher=$one('SELECT * FROM teachers WHERE id=? FOR UPDATE',[(int)($p['teacher_id']??0)]);
        if(!$teacher||$teacher['status']!=='Active')throw new InvalidArgumentException('Select an active teacher.');
        $qualified=$one("SELECT id FROM teacher_subject_qualifications WHERE teacher_id=? AND subject_id=? AND status='Active' FOR UPDATE",[$teacher['id'],$subject['id']]);
        if(!$qualified)throw new InvalidArgumentException('An active subject qualification is required for this teacher.');
        $block=$one("SELECT * FROM time_blocks WHERE id=? AND is_active=1 AND block_type='Class' FOR UPDATE",[(int)($p['time_block_id']??0)]);
        if(!$block||$block['start_time']>=$block['end_time'])throw new InvalidArgumentException('Select a valid active Class time block.');
        $day=(string)($p['day']??'');
        if(!in_array($day,['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],true))throw new InvalidArgumentException('Select a valid day.');
        $st=$pdo->prepare("SELECT * FROM schedule_entries WHERE section_id=? AND subject_id=? AND status<>'Cancelled' ORDER BY id");
        $st->execute([$section['id'],$subject['id']]);$existing=$st->fetchAll(PDO::FETCH_ASSOC);
        $entryId=(int)($p['entry_id']??0);$entry=null;
        foreach($existing as $row)if((int)$row['id']===$entryId)$entry=$row;
        if(($entryId&&!$entry)||(!$entryId&&$existing))throw new DomainException('The mapping changed. Refresh and select the current assignment.');
        if($entry&&$entry['status']==='Published')throw new InvalidArgumentException('Published mappings are read-only. Revise through the publication workflow.');
        if(!hash_equals(tmRevision($entry),(string)($p['revision']??'')))throw new DomainException('This mapping was updated by another user. Refresh before saving.');
        $id=$entry?(int)$entry['id']:0;$key='Regular:'.$id;
        $record=['id'=>$id,'key'=>$key,'schedule_type'=>'Regular','display_type'=>'Lecture','assignment_mode'=>'Section','date'=>null,'student_keys'=>[],
            'section_id'=>(int)$section['id'],'section_code'=>$section['code'],'section_name'=>$section['name'],'participant_count'=>(int)$section['current_students'],
            'subject_id'=>(int)$subject['id'],'subject_code'=>$subject['code'],'subject_name'=>$subject['name'],'units'=>(float)$subject['units'],
            'teacher_id'=>(int)$teacher['id'],'teacher_name'=>$teacher['full_name'],'employee_no'=>$teacher['employee_no'],
            'room_id'=>null,'room_code'=>null,'room_status'=>null,'room_capacity'=>null,'building'=>null,'room_type'=>null,
            'time_block_id'=>(int)$block['id'],'day_of_week'=>$day,'start_time'=>$block['start_time'],'end_time'=>$block['end_time'],
            'academic_year'=>$section['academic_year'],'semester'=>$section['semester'],'status'=>'Draft','schedule_label'=>$day.' '.$block['start_time'].'–'.$block['end_time']];
        $all=ccRecords($pdo);$all[$key]=$record;
        $findings=ccValidate($pdo,[$key=>$record],$all);
        // Only the deliberately unassigned room is deferred. No teacher/time findings are bypassed.
        $findings=array_values(array_filter($findings,fn($f)=>$f['conflict_type']!=='Room'));
        $critical=array_filter($findings,fn($f)=>$f['severity']==='Error');
        $load=0;foreach($all as $r)if($r['teacher_id']===$record['teacher_id']&&ccSameTerm($record,$r))$load+=$r['units'];
        $result=['ok'=>true,'valid'=>!$critical,'findings'=>$findings,'projected_load'=>$load,'max_load'=>(float)$teacher['max_load_units'],
            'checks'=>['Active teacher','Verified subject qualification','Teaching load','Teacher availability','Section conflicts','Active time block','Section-subject assignment']];
        if(!$save||$critical){$pdo->rollBack();return $result;}
        $classType=in_array($subject['subject_type'],['Lecture','Laboratory','Online'],true)?$subject['subject_type']:'Lecture';
        $values=[$teacher['id'],$block['id'],$day,$block['start_time'],$block['end_time'],$classType];
        if($entry){
            $st=$pdo->prepare("UPDATE schedule_entries SET teacher_id=?,time_block_id=?,day_of_week=?,start_time=?,end_time=?,class_type=?,room_id=NULL,status='Draft',remarks='Teacher Assigned / Ready for Room Assignment',updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $st->execute([...$values,$id]);
        }else{
            $st=$pdo->prepare("INSERT INTO schedule_entries(teacher_id,time_block_id,day_of_week,start_time,end_time,class_type,section_id,subject_id,academic_year,semester,created_by,status,remarks) VALUES(?,?,?,?,?,?,?,?,?,?,?,'Draft','Teacher Assigned / Ready for Room Assignment')");
            $st->execute([...$values,$section['id'],$subject['id'],$section['academic_year'],$section['semester'],getCurrentUserId()]);$id=(int)$pdo->lastInsertId();
        }
        $st=$pdo->prepare("INSERT INTO activity_logs(user_id,user_name,role_key,action,module_key,detail,ip_address,user_agent) VALUES(?,?,?,'Save schedule','scheduling',?,?,?)");
        $detail='Teacher mapping #'.$id.' for '.$section['code'].'. '.$subject['code'].'; teacher '.$teacher['employee_no'].'; '.$day.' '.$block['start_time'].'-'.$block['end_time'].'; room pending'.($entry?'; previous teacher #'.$entry['teacher_id'].', room #'.($entry['room_id']??'none'):'');
        $st->execute([getCurrentUserId(),getCurrentUserName(),getCurrentUserRoleKey(),substr($detail,0,500),smsClientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
        $pdo->commit();
        return $result+['saved_id'=>$id,'status'=>'Teacher Assigned','room_status'=>'Ready for Room Assignment','next_url'=>BASE_URL.'/modules/scheduling/pages/room-availability-checker.php?source_type=Regular&record_id='.$id];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
