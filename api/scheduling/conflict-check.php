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

require_once ROOT_PATH . '/modules/scheduling/conflict-rules.php';

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
