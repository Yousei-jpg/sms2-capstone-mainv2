<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/audit.php';

header('Content-Type: application/json; charset=utf-8');
if (function_exists('isAuthenticated') && !isAuthenticated()) {
    http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Authentication required.']); exit;
}
if (function_exists('userCanAccessModule') && !userCanAccessModule('scheduling')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'You do not have permission to access Class Scheduling.']); exit;
}

function clOut(array $payload, int $status = 200): never {
    http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
function clBody(): array {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) throw new InvalidArgumentException('A valid JSON request body is required.');
    return $body;
}
function clActor(): ?int { return function_exists('getCurrentUserId') ? getCurrentUserId() : null; }
function clBool(mixed $value, bool $default = false): bool {
    if ($value === null) return $default;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}
function clTime(?string $value): string { return $value ? date('g:i A', strtotime($value)) : '—'; }
function clShiftTime(?string $value, int $minutes): ?string {
    if (!$value) return null;
    $base = strtotime('2000-01-01 ' . $value); $shifted = $base + ($minutes * 60);
    if (date('Y-m-d', $shifted) !== '2000-01-01') throw new InvalidArgumentException('The time shift moves a class outside the same day.');
    return date('H:i:s', $shifted);
}
function clOptions(array $raw): array {
    $policy = trim((string)($raw['existing_policy'] ?? 'block'));
    if (!in_array($policy, ['block', 'append'], true)) $policy = 'block';
    $shift = clBool($raw['apply_time_shift'] ?? false) ? (int)($raw['time_shift_minutes'] ?? 0) : 0;
    if ($shift < -240 || $shift > 240) throw new InvalidArgumentException('Time shift must be between -240 and 240 minutes.');
    return [
        'copy_subjects' => true,
        'copy_time_slots' => clBool($raw['copy_time_slots'] ?? true, true),
        'copy_faculty' => clBool($raw['copy_faculty'] ?? true, true),
        'copy_rooms' => clBool($raw['copy_rooms'] ?? true, true),
        'copy_class_types' => clBool($raw['copy_class_types'] ?? true, true),
        'auto_conflict_check' => clBool($raw['auto_conflict_check'] ?? true, true),
        'time_shift_minutes' => $shift,
        'existing_policy' => $policy,
        'remarks' => mb_substr(trim((string)($raw['remarks'] ?? '')), 0, 180),
    ];
}
function clHistory(PDO $pdo, int $batchId, string $action, ?string $from, ?string $to, string $detail, array $snapshot = []): void {
    $s = $pdo->prepare('INSERT INTO schedule_clone_history(clone_batch_id,action,from_status,to_status,detail,snapshot_json,changed_by) VALUES(?,?,?,?,?,?,?)');
    $s->execute([$batchId, $action, $from, $to, $detail, $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, clActor()]);
}
function clSection(PDO $pdo, int $id, string $label): array {
    $s = $pdo->prepare("SELECT id,code,name,program,year_level,semester,academic_year,current_students,status FROM sections WHERE id=? LIMIT 1");
    $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'Active') throw new RuntimeException($label . ' section was not found or is inactive.');
    $row['id'] = (int)$row['id']; $row['year_level'] = (int)$row['year_level']; $row['current_students'] = (int)$row['current_students'];
    return $row;
}
function clTargetState(PDO $pdo, int $sectionId): array {
    $out = ['Draft' => 0, 'Validated' => 0, 'Published' => 0, 'Cancelled' => 0, 'total' => 0];
    $s = $pdo->prepare('SELECT status,COUNT(*) total FROM schedule_entries WHERE section_id=? GROUP BY status'); $s->execute([$sectionId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[$r['status']] = (int)$r['total']; if ($r['status'] !== 'Cancelled') $out['total'] += (int)$r['total']; }
    return $out;
}
function clSourceRows(PDO $pdo, int $sectionId): array {
    $s = $pdo->prepare("SELECT se.id,se.subject_id,se.teacher_id,se.room_id,se.time_block_id,se.day_of_week,se.start_time,se.end_time,se.class_type,se.status,
        sub.code subject_code,sub.name subject_name,sub.units,t.full_name teacher_name,t.employee_no,t.status teacher_status,
        r.room_code,r.room_type,r.capacity,r.status room_status,tb.label time_block_name,tb.is_active time_block_active
        FROM schedule_entries se JOIN subjects sub ON sub.id=se.subject_id
        LEFT JOIN teachers t ON t.id=se.teacher_id LEFT JOIN rooms r ON r.id=se.room_id LEFT JOIN time_blocks tb ON tb.id=se.time_block_id
        WHERE se.section_id=? AND se.status IN ('Draft','Validated','Published')
        ORDER BY FIELD(se.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),se.start_time,sub.code");
    $s->execute([$sectionId]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        foreach (['id','subject_id','teacher_id','room_id','time_block_id','capacity','time_block_active'] as $key) $r[$key] = $r[$key] !== null ? (int)$r[$key] : null;
        $r['units'] = (float)$r['units'];
    }
    unset($r); return $rows;
}
function clPreview(PDO $pdo, int $sourceId, int $targetId, array $options): array {
    $source = clSection($pdo, $sourceId, 'Source'); $target = clSection($pdo, $targetId, 'Target');
    $rows = clSourceRows($pdo, $sourceId); $state = clTargetState($pdo, $targetId);
    $issues = []; $projected = []; $teacherIds = []; $roomIds = [];
    if ($sourceId === $targetId) $issues[] = ['level'=>'error','message'=>'Source and target sections must be different records.'];
    if (!$rows) $issues[] = ['level'=>'error','message'=>'The source schedule has no active schedule entries to clone.'];
    if ($state['Published'] > 0) $issues[] = ['level'=>'error','message'=>'The target section already has a published schedule. Published data is never overwritten by cloning.'];
    elseif ($state['total'] > 0) $issues[] = ['level'=>$options['existing_policy']==='block'?'error':'warning','message'=>'The target section already has '.$state['total'].' draft or validated entry/entries.'];
    if (!$options['copy_faculty']) $issues[] = ['level'=>'warning','message'=>'Faculty assignments will be omitted and must be completed in Teacher Schedule Mapping.'];
    if (!$options['copy_rooms']) $issues[] = ['level'=>'warning','message'=>'Room assignments will be omitted and must be completed in Room Availability Checker.'];
    if (!$options['copy_time_slots']) $issues[] = ['level'=>'warning','message'=>'Time slots will be omitted and must be assigned before validation can pass.'];
    $assigned = $pdo->prepare("SELECT 1 FROM section_subjects WHERE section_id=? AND subject_id=? AND status='Assigned' LIMIT 1");
    $block = $pdo->prepare("SELECT id,label AS name FROM time_blocks WHERE start_time=? AND end_time=? AND block_type='Class' AND is_active=1 LIMIT 1");
    foreach ($rows as $row) {
        $warnings = []; $teacherId = $options['copy_faculty'] ? $row['teacher_id'] : null; $roomId = $options['copy_rooms'] ? $row['room_id'] : null;
        $day = $options['copy_time_slots'] ? $row['day_of_week'] : null;
        $start = $options['copy_time_slots'] ? clShiftTime($row['start_time'], $options['time_shift_minutes']) : null;
        $end = $options['copy_time_slots'] ? clShiftTime($row['end_time'], $options['time_shift_minutes']) : null;
        $timeBlockId = null; $timeBlockName = null;
        if ($options['copy_time_slots'] && $start && $end) {
            if ($options['time_shift_minutes'] === 0 && $row['time_block_id'] && $row['time_block_active']) { $timeBlockId = $row['time_block_id']; $timeBlockName = $row['time_block_name']; }
            else { $block->execute([$start,$end]); $found=$block->fetch(PDO::FETCH_ASSOC); if($found){$timeBlockId=(int)$found['id'];$timeBlockName=$found['name'];} }
            if (!$timeBlockId) $warnings[] = 'No active configured time block matches the proposed time.';
        }
        if ($row['status'] !== 'Published') $warnings[] = 'The source entry is '.$row['status'].', not Published.';
        if ($teacherId && $row['teacher_status'] !== 'Active') $warnings[] = 'The copied faculty member is no longer active.';
        if ($roomId && $row['room_status'] !== 'Available') $warnings[] = 'Room '.$row['room_code'].' is currently '.$row['room_status'].'.';
        $assigned->execute([$targetId,$row['subject_id']]); if (!$assigned->fetchColumn()) $warnings[] = $row['subject_code'].' is not assigned to the target section.';
        if ($teacherId) $teacherIds[$teacherId]=true; if ($roomId) $roomIds[$roomId]=true;
        $projected[] = [
            'source_entry_id'=>$row['id'],'subject_id'=>$row['subject_id'],'subject_code'=>$row['subject_code'],'subject_name'=>$row['subject_name'],'units'=>$row['units'],
            'teacher_id'=>$teacherId,'teacher_name'=>$teacherId?$row['teacher_name']:null,'room_id'=>$roomId,'room_code'=>$roomId?$row['room_code']:null,
            'day_of_week'=>$day,'start_time'=>$start,'end_time'=>$end,'time_label'=>$start&&$end?clTime($start).' – '.clTime($end):'Not copied',
            'time_block_id'=>$timeBlockId,'time_block_name'=>$timeBlockName,'class_type'=>$options['copy_class_types']?$row['class_type']:'Lecture',
            'source_status'=>$row['status'],'warnings'=>$warnings,
        ];
        foreach ($warnings as $warning) $issues[]=['level'=>'warning','entry'=>$row['subject_code'],'message'=>$warning];
    }
    $canClone = (bool)$rows && $sourceId !== $targetId && $state['Published'] === 0 && !($state['total'] > 0 && $options['existing_policy'] === 'block');
    return ['source'=>$source,'target'=>$target,'target_state'=>$state,'options'=>$options,'entries'=>$projected,'issues'=>$issues,'can_clone'=>$canClone,
        'stats'=>['entries'=>count($projected),'subjects'=>count(array_unique(array_column($projected,'subject_id'))),'faculty'=>count($teacherIds),'rooms'=>count($roomIds),'warnings'=>count(array_filter($issues,fn($x)=>$x['level']==='warning'))]];
}
function clSources(PDO $pdo): array {
    $sql="SELECT sec.id section_id,sec.code,sec.name,sec.program,sec.year_level,sec.academic_year,sec.semester,
        COUNT(se.id) entry_count,COUNT(DISTINCT se.subject_id) subject_count,COUNT(DISTINCT se.teacher_id) faculty_count,COUNT(DISTINCT se.room_id) room_count,
        GROUP_CONCAT(DISTINCT se.status ORDER BY FIELD(se.status,'Published','Validated','Draft') SEPARATOR ', ') status_label,MAX(se.updated_at) last_updated
        FROM sections sec JOIN schedule_entries se ON se.section_id=sec.id AND se.status IN ('Draft','Validated','Published')
        WHERE sec.status='Active' GROUP BY sec.id ORDER BY sec.academic_year DESC,FIELD(sec.semester,'1st Semester','2nd Semester','Summer'),sec.program,sec.code";
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r)foreach(['section_id','year_level','entry_count','subject_count','faculty_count','room_count'] as $k)$r[$k]=(int)$r[$k];unset($r);return $rows;
}
function clBatch(PDO $pdo, int $id): ?array {
    $s=$pdo->prepare("SELECT b.*,ss.code source_code,ss.name source_name,ts.code target_code,ts.name target_name,ts.program,creator.full_name created_by_name
        FROM schedule_clone_batches b JOIN sections ss ON ss.id=b.source_section_id JOIN sections ts ON ts.id=b.target_section_id
        LEFT JOIN users creator ON creator.id=b.created_by WHERE b.id=?");$s->execute([$id]);$batch=$s->fetch(PDO::FETCH_ASSOC);if(!$batch)return null;
    $batch['id']=(int)$batch['id'];$batch['cloned_count']=(int)$batch['cloned_count'];$batch['options']=json_decode((string)$batch['options_json'],true)?:[];
    $e=$pdo->prepare("SELECT m.source_entry_id,m.cloned_entry_id,se.status,se.day_of_week,se.start_time,se.end_time,se.class_type,
        sub.code subject_code,sub.name subject_name,t.full_name teacher_name,r.room_code,m.preview_warning_json
        FROM schedule_clone_batch_entries m JOIN schedule_entries se ON se.id=m.cloned_entry_id JOIN subjects sub ON sub.id=se.subject_id
        LEFT JOIN teachers t ON t.id=se.teacher_id LEFT JOIN rooms r ON r.id=se.room_id WHERE m.clone_batch_id=? ORDER BY se.day_of_week,se.start_time,sub.code");
    $e->execute([$id]);$batch['entries']=$e->fetchAll(PDO::FETCH_ASSOC);foreach($batch['entries'] as &$x){$x['source_entry_id']=(int)$x['source_entry_id'];$x['cloned_entry_id']=(int)$x['cloned_entry_id'];$x['warnings']=json_decode((string)$x['preview_warning_json'],true)?:[];}unset($x);
    $h=$pdo->prepare("SELECT h.*,COALESCE(u.full_name,u.username,'System') changed_by_name FROM schedule_clone_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.clone_batch_id=? ORDER BY h.changed_at DESC,h.id DESC");$h->execute([$id]);$batch['history']=$h->fetchAll(PDO::FETCH_ASSOC);
    if($batch['latest_validation_run_id']){$v=$pdo->prepare('SELECT * FROM validation_runs WHERE run_id=?');$v->execute([$batch['latest_validation_run_id']]);$batch['validation_run']=$v->fetch(PDO::FETCH_ASSOC)?:null;}else$batch['validation_run']=null;
    return $batch;
}
function clHistoryRows(PDO $pdo): array {
    return $pdo->query("SELECT b.id,b.reference_no,b.schedule_name,b.status,b.cloned_count,b.source_academic_year,b.source_semester,b.target_academic_year,b.target_semester,b.created_at,b.published_at,ss.code source_code,ts.code target_code
        FROM schedule_clone_batches b JOIN sections ss ON ss.id=b.source_section_id JOIN sections ts ON ts.id=b.target_section_id ORDER BY b.created_at DESC,b.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $pdo=getDatabaseConnection();$method=$_SERVER['REQUEST_METHOD']??'GET';$body=$method==='POST'?clBody():[];$action=$method==='GET'?trim((string)($_GET['action']??'dashboard')):trim((string)($body['action']??''));
    if($method==='GET'){
        if($action==='dashboard'||$action==='options'){
            $sections=$pdo->query("SELECT id,code,name,program,year_level,academic_year,semester,current_students FROM sections WHERE status='Active' ORDER BY academic_year DESC,semester,program,code")->fetchAll(PDO::FETCH_ASSOC);
            foreach($sections as &$s){$s['id']=(int)$s['id'];$s['year_level']=(int)$s['year_level'];$s['current_students']=(int)$s['current_students'];}unset($s);
            $sources=clSources($pdo);$history=clHistoryRows($pdo);$years=array_values(array_unique(array_merge(array_column($sections,'academic_year'),array_column($sources,'academic_year'))));rsort($years);
            clOut(['ok'=>true,'sources'=>$sources,'sections'=>$sections,'history'=>$history,'filters'=>['academic_years'=>$years,'semesters'=>['1st Semester','2nd Semester','Summer'],'programs'=>array_values(array_unique(array_filter(array_column($sections,'program')))),'schedule_types'=>['Regular']]]);
        }
        if($action==='batch'){$batch=clBatch($pdo,(int)($_GET['id']??0));if(!$batch)clOut(['ok'=>false,'error'=>'Clone batch was not found.'],404);clOut(['ok'=>true,'batch'=>$batch]);}
        if($action==='history')clOut(['ok'=>true,'history'=>clHistoryRows($pdo)]);
        clOut(['ok'=>false,'error'=>'Unknown Schedule Cloning action.'],400);
    }
    if($method!=='POST')clOut(['ok'=>false,'error'=>'Method not allowed.'],405);
    if($action==='preview'){
        $options=clOptions($body['options']??[]);$preview=clPreview($pdo,(int)($body['source_section_id']??0),(int)($body['target_section_id']??0),$options);
        clOut(['ok'=>true,'preview'=>$preview]);
    }
    if($action==='clone'){
        $sourceId=(int)($body['source_section_id']??0);$targetId=(int)($body['target_section_id']??0);$name=mb_substr(trim((string)($body['schedule_name']??'')),0,150);if($name==='')throw new InvalidArgumentException('A target schedule name is required.');$options=clOptions($body['options']??[]);
        $pdo->beginTransaction();$lock=$pdo->prepare('SELECT id FROM sections WHERE id IN (?,?) FOR UPDATE');$lock->execute([$sourceId,$targetId]);$preview=clPreview($pdo,$sourceId,$targetId,$options);if(!$preview['can_clone'])throw new RuntimeException('The clone preview contains a blocking issue. Return to Configure Options and resolve it.');
        $temporary='TMP-'.bin2hex(random_bytes(8));$ins=$pdo->prepare("INSERT INTO schedule_clone_batches(reference_no,schedule_name,source_section_id,target_section_id,source_academic_year,source_semester,target_academic_year,target_semester,options_json,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,'Draft',?)");
        $ins->execute([$temporary,$name,$sourceId,$targetId,$preview['source']['academic_year'],$preview['source']['semester'],$preview['target']['academic_year'],$preview['target']['semester'],json_encode($options,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),clActor()]);$batchId=(int)$pdo->lastInsertId();$reference='CLN-'.date('Ymd').'-'.str_pad((string)$batchId,6,'0',STR_PAD_LEFT);$pdo->prepare('UPDATE schedule_clone_batches SET reference_no=? WHERE id=?')->execute([$reference,$batchId]);
        $entryIns=$pdo->prepare("INSERT INTO schedule_entries(section_id,subject_id,teacher_id,room_id,time_block_id,day_of_week,start_time,end_time,class_type,status,source,academic_year,semester,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?,'Draft','Cloned',?,?,?,?)");
        $mapIns=$pdo->prepare('INSERT INTO schedule_clone_batch_entries(clone_batch_id,source_entry_id,cloned_entry_id,preview_warning_json) VALUES(?,?,?,?)');$ids=[];
        foreach($preview['entries'] as $entry){$remarks=mb_substr('Cloned by '.$reference.' from '.$preview['source']['code'].'.'.($options['remarks']?' '.$options['remarks']:''),0,255);$entryIns->execute([$targetId,$entry['subject_id'],$entry['teacher_id'],$entry['room_id'],$entry['time_block_id'],$entry['day_of_week'],$entry['start_time'],$entry['end_time'],$entry['class_type'],$preview['target']['academic_year'],$preview['target']['semester'],$remarks,clActor()]);$created=(int)$pdo->lastInsertId();$ids[]=$created;$mapIns->execute([$batchId,$entry['source_entry_id'],$created,json_encode($entry['warnings'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
        $pdo->prepare('UPDATE schedule_clone_batches SET cloned_count=? WHERE id=?')->execute([count($ids),$batchId]);clHistory($pdo,$batchId,'Draft created',null,'Draft',count($ids).' schedule entry/entries were cloned as Draft.',['source'=>$preview['source'],'target'=>$preview['target'],'options'=>$options,'entry_ids'=>$ids]);$pdo->commit();
        if(function_exists('logActivity'))logActivity('Clone schedule',$reference.' copied '.count($ids).' entry/entries from '.$preview['source']['code'].' to '.$preview['target']['code'].' as Draft.','scheduling');clOut(['ok'=>true,'message'=>'Cloned schedule created as Draft. It must pass Conflict Checker before publication.','batch'=>clBatch($pdo,$batchId),'record_keys'=>array_map(fn($id)=>'Regular:'.$id,$ids),'auto_conflict_check'=>$options['auto_conflict_check']]);
    }
    if($action==='sync_validation'){
        $batchId=(int)($body['batch_id']??0);$runId=trim((string)($body['run_id']??''));if(!$runId)throw new InvalidArgumentException('Validation run is required.');$pdo->beginTransaction();$s=$pdo->prepare('SELECT * FROM schedule_clone_batches WHERE id=? FOR UPDATE');$s->execute([$batchId]);$batch=$s->fetch(PDO::FETCH_ASSOC);if(!$batch)throw new RuntimeException('Clone batch was not found.');if($batch['status']==='Published')throw new RuntimeException('A published clone cannot be relinked to another validation run.');$r=$pdo->prepare('SELECT * FROM validation_runs WHERE run_id=?');$r->execute([$runId]);$run=$r->fetch(PDO::FETCH_ASSOC);if(!$run)throw new RuntimeException('Validation run was not found.');$count=$pdo->prepare("SELECT COUNT(*) FROM schedule_clone_batch_entries m JOIN validation_run_records vr ON vr.reference_id=m.cloned_entry_id AND vr.schedule_type='Regular' AND vr.run_id=? WHERE m.clone_batch_id=?");$count->execute([$runId,$batchId]);$matched=(int)$count->fetchColumn();$active=$pdo->prepare('SELECT COUNT(*) FROM schedule_clone_batch_entries m JOIN schedule_entries se ON se.id=m.cloned_entry_id WHERE m.clone_batch_id=?');$active->execute([$batchId]);$expected=(int)$active->fetchColumn();if(!$expected||$matched!==$expected)throw new RuntimeException('The validation run does not include every entry in this cloned draft.');$new=(int)$run['critical_count']>0?'Has Conflicts':($run['status']==='Completed'?'Ready to Publish':'Validated');$validated=$new==='Ready to Publish'?date('Y-m-d H:i:s'):null;$pdo->prepare('UPDATE schedule_clone_batches SET status=?,latest_validation_run_id=?,validated_at=? WHERE id=?')->execute([$new,$runId,$validated,$batchId]);clHistory($pdo,$batchId,'Validation synchronized',$batch['status'],$new,'Conflict Checker '.$runId.' recorded '.$run['critical_count'].' critical issue(s).',['run'=>$run]);$pdo->commit();if(function_exists('logActivity'))logActivity('Sync cloned schedule validation',$batch['reference_no'].' linked to '.$runId.' with status '.$new.'.','scheduling');clOut(['ok'=>true,'message'=>$new==='Ready to Publish'?'Validation completed. The cloned draft is Ready to Publish.':($new==='Has Conflicts'?'Critical conflicts must be resolved and rechecked.':'No critical conflicts found. Save the validation result to continue.'),'batch'=>clBatch($pdo,$batchId)]);
    }
    if($action==='publish'){
        $batchId=(int)($body['batch_id']??0);$pdo->beginTransaction();$s=$pdo->prepare('SELECT * FROM schedule_clone_batches WHERE id=? FOR UPDATE');$s->execute([$batchId]);$batch=$s->fetch(PDO::FETCH_ASSOC);if(!$batch)throw new RuntimeException('Clone batch was not found.');if($batch['status']!=='Ready to Publish')throw new RuntimeException('Only a validated clone marked Ready to Publish can be published.');$r=$pdo->prepare("SELECT status,critical_count,completed_at FROM validation_runs WHERE run_id=? AND status='Completed' AND critical_count=0");$r->execute([$batch['latest_validation_run_id']]);$run=$r->fetch(PDO::FETCH_ASSOC);if(!$run)throw new RuntimeException('The latest Conflict Checker result is not completed or still has critical conflicts.');$state=$pdo->prepare("SELECT COUNT(*) total,SUM(se.status='Validated') validated,MAX(se.updated_at) last_change FROM schedule_clone_batch_entries m JOIN schedule_entries se ON se.id=m.cloned_entry_id WHERE m.clone_batch_id=?");$state->execute([$batchId]);$entryState=$state->fetch(PDO::FETCH_ASSOC);if(!(int)$entryState['total']||(int)$entryState['validated']!==(int)$entryState['total'])throw new RuntimeException('Every cloned entry must remain Validated before publication.');if($batch['validated_at']&&$entryState['last_change']>$batch['validated_at'])throw new RuntimeException('The cloned schedule changed after validation. Run Conflict Checker again before publishing.');$u=$pdo->prepare("UPDATE schedule_entries se JOIN schedule_clone_batch_entries m ON m.cloned_entry_id=se.id SET se.status='Published' WHERE m.clone_batch_id=? AND se.status='Validated'");$u->execute([$batchId]);$published=$u->rowCount();$pdo->prepare("UPDATE schedule_clone_batches SET status='Published',published_at=NOW() WHERE id=?")->execute([$batchId]);clHistory($pdo,$batchId,'Published','Ready to Publish','Published','The validated cloned schedule was published.',['validation_run_id'=>$batch['latest_validation_run_id'],'published_count'=>$published]);$pdo->commit();if(function_exists('logActivity'))logActivity('Publish cloned schedule',$batch['reference_no'].' published '.$published.' validated entry/entries.','scheduling');clOut(['ok'=>true,'message'=>'The validated cloned schedule was published successfully.','published_count'=>$published,'batch'=>clBatch($pdo,$batchId)]);
    }
    clOut(['ok'=>false,'error'=>'Unknown Schedule Cloning action.'],400);
} catch(Throwable $e) {
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    error_log('Schedule Cloning API: '.$e->getMessage());
    $safe=!($e instanceof PDOException)&&($e instanceof InvalidArgumentException||$e instanceof RuntimeException)?$e->getMessage():'Schedule cloning failed. Please try again or contact the administrator.';
    clOut(['ok'=>false,'error'=>$safe],$e instanceof InvalidArgumentException?422:500);
}
