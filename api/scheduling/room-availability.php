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

const RA_SOURCE_TYPES = ['Regular', 'Special', 'Exam'];
const RA_DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

function raJson(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function raBody(): array {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) throw new InvalidArgumentException('A valid JSON request body is required.');
    return $body;
}
function raTime(string $value): string {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($value), $m)) {
        throw new InvalidArgumentException('Enter a valid start and end time.');
    }
    $h=(int)$m[1];$i=(int)$m[2];$s=isset($m[3])?(int)$m[3]:0;
    if($h>23||$i>59||$s>59) throw new InvalidArgumentException('Enter a valid start and end time.');
    return sprintf('%02d:%02d:%02d',$h,$i,$s);
}
function raDate(string $value): string {
    $d=DateTime::createFromFormat('Y-m-d',$value);
    if(!$d||$d->format('Y-m-d')!==$value) throw new InvalidArgumentException('Select a valid date.');
    return $value;
}
function raSource(string $value): string {
    $value=ucfirst(strtolower(trim($value)));
    if(!in_array($value,RA_SOURCE_TYPES,true)) throw new InvalidArgumentException('Select a valid scheduling request.');
    return $value;
}
function raDay(string $date): string { return (new DateTime($date))->format('l'); }
function raShortTime(?string $value): ?string { return $value ? substr($value,0,5) : null; }
function raCsvFacilities(mixed $value): array {
    $items=is_array($value)?$value:explode(',',(string)$value);
    $out=[];foreach($items as $item){$item=trim((string)$item);if($item!=='')$out[$item]=true;}
    return array_keys($out);
}
function raFacilityMap(PDO $pdo): array {
    $map=[];
    $rows=$pdo->query("SELECT room_id, facility_name FROM room_facilities WHERE status='Active' ORDER BY facility_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)$map[(int)$r['room_id']][]=$r['facility_name'];
    return $map;
}
function raRoomRows(PDO $pdo): array {
    return $pdo->query("SELECT id, room_code, building, room_type, capacity, status FROM rooms ORDER BY room_code")->fetchAll(PDO::FETCH_ASSOC);
}
// SELECT for one request type, ending in its id column so callers can add "=:id" or "IN (...)".
function raRequestSql(string $type): string {
    if($type==='Regular'){
        return "SELECT se.id, 'Regular' source_type, 'Regular Class' schedule_type, se.status,
                    se.academic_year, se.semester, NULL schedule_date, se.day_of_week,
                    se.start_time, se.end_time, se.time_block_id, se.class_type,
                    se.room_id, r.room_code, sec.code section_code, sec.current_students participant_count,
                    sub.code subject_code, sub.name subject_name, t.full_name faculty_name,
                    CONCAT(sub.code,' · ',sub.name) title
             FROM schedule_entries se JOIN sections sec ON sec.id=se.section_id
             JOIN subjects sub ON sub.id=se.subject_id LEFT JOIN teachers t ON t.id=se.teacher_id
             LEFT JOIN rooms r ON r.id=se.room_id WHERE se.id";
    }elseif($type==='Special'){
        return "SELECT sc.id, 'Special' source_type, CONCAT(sc.special_type,' Special Class') schedule_type, sc.status,
                    sc.academic_year, sc.semester, sc.class_date schedule_date,
                    DAYNAME(sc.class_date) day_of_week, sc.start_time, sc.end_time, sc.time_block_id,
                    NULL class_type, sc.room_id, r.room_code, COALESCE(sec.code,'Selected Students') section_code,
                    CASE WHEN sc.assignment_mode='Students' THEN (SELECT COUNT(*) FROM special_class_students x WHERE x.special_class_id=sc.id)
                         ELSE COALESCE(sec.current_students,0) END participant_count,
                    sub.code subject_code, sub.name subject_name, t.full_name faculty_name, sc.title
             FROM special_classes sc LEFT JOIN sections sec ON sec.id=sc.section_id
             LEFT JOIN subjects sub ON sub.id=sc.subject_id LEFT JOIN teachers t ON t.id=sc.teacher_id
             LEFT JOIN rooms r ON r.id=sc.room_id WHERE sc.id";
    }else{
        return "SELECT ex.id, 'Exam' source_type, CONCAT(ex.exam_type,' Exam') schedule_type, ex.status,
                    ex.academic_year, ex.semester, ex.exam_date schedule_date,
                    DAYNAME(ex.exam_date) day_of_week, ex.start_time, ex.end_time, NULL time_block_id,
                    'Exam' class_type, ex.room_id, r.room_code, sec.code section_code,
                    sec.current_students participant_count, sub.code subject_code, sub.name subject_name,
                    t.full_name faculty_name, CONCAT(sub.code,' · ',ex.exam_type,' Exam') title
             FROM exam_schedules ex JOIN sections sec ON sec.id=ex.section_id
             JOIN subjects sub ON sub.id=ex.subject_id LEFT JOIN teachers t ON t.id=ex.proctor_id
             LEFT JOIN rooms r ON r.id=ex.room_id WHERE ex.id";
    }
}
function raRequestRow(array $row): array {
    $row['id']=(int)$row['id'];$row['participant_count']=(int)$row['participant_count'];
    $row['room_id']=$row['room_id']!==null?(int)$row['room_id']:null;
    $row['start_time']=raShortTime($row['start_time']);$row['end_time']=raShortTime($row['end_time']);
    return $row;
}
function raRequest(PDO $pdo,string $type,int $id,bool $lock=false): ?array {
    $stmt=$pdo->prepare(raRequestSql($type).'=:id LIMIT 1'.($lock?' FOR UPDATE':''));$stmt->execute(['id'=>$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row?raRequestRow($row):null;
}
function raRequests(PDO $pdo,array $filters=[]): array {
    $rows=[];
    $queries=[
        "SELECT id,'Regular' source_type FROM schedule_entries WHERE status IN ('Draft','Validated') AND start_time IS NOT NULL AND end_time IS NOT NULL",
        "SELECT id,'Special' source_type FROM special_classes WHERE status IN ('Draft','For Scheduling','Scheduled','Validated','Ready to Publish')",
        "SELECT id,'Exam' source_type FROM exam_schedules WHERE status IN ('Draft','Validated') AND start_time IS NOT NULL AND end_time IS NOT NULL"
    ];
    foreach($queries as $sql){
        $keys=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);if(!$keys)continue;
        // One query per source type instead of one per request (each round trip is slow on a remote database).
        $ids=array_map(fn($k)=>(int)$k['id'],$keys);$byId=[];
        $st=$pdo->prepare(raRequestSql($keys[0]['source_type']).' IN ('.implode(',',array_fill(0,count($ids),'?')).')');$st->execute($ids);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$byId[(int)$row['id']]=raRequestRow($row);
        foreach($ids as $id){
            $r=$byId[$id]??null;if(!$r)continue;
            if(!empty($filters['academic_year'])&&$r['academic_year']!==$filters['academic_year'])continue;
            if(!empty($filters['semester'])&&$r['semester']!==$filters['semester'])continue;
            if(!empty($filters['source_type'])&&$r['source_type']!==$filters['source_type'])continue;
            $q=strtolower(trim((string)($filters['search']??'')));
            if($q!==''&&!str_contains(strtolower(implode(' ',[$r['title'],$r['section_code'],$r['faculty_name'],$r['room_code']])), $q))continue;
            $rows[]=$r;
        }
    }
    usort($rows,fn($a,$b)=>$b['id']<=>$a['id']);
    return $rows;
}
function raCriteria(array $input,?array $request=null): array {
    $date=raDate((string)($input['date']??$request['schedule_date']??date('Y-m-d')));
    $start=raTime((string)($request['start_time']??$input['start']??''));
    $end=raTime((string)($request['end_time']??$input['end']??''));
    if($start>=$end)throw new InvalidArgumentException('Start time must be earlier than end time.');
    return [
        'date'=>$date,'day'=>raDay($date),'start'=>substr($start,0,5),'end'=>substr($end,0,5),
        'academic_year'=>trim((string)($request['academic_year']??$input['academic_year']??'')),
        'semester'=>trim((string)($request['semester']??$input['semester']??'')),
        'building'=>trim((string)($input['building']??'')),
        'room_type'=>trim((string)($input['room_type']??'')),
        'capacity'=>max(0,(int)($input['capacity']??$request['participant_count']??0)),
        'facilities'=>raCsvFacilities($input['facilities']??[]),
    ];
}
function raOccupancy(PDO $pdo,array $c,?string $ignoreType=null,int $ignoreId=0): array {
    $all=[];$common=['start'=>$c['start'].':00','end'=>$c['end'].':00'];
    $sql="SELECT se.id,se.room_id,'Regular' source_type,se.status,sec.code section_code,sub.code subject_code,
                 sub.name subject_name,t.full_name faculty_name,se.start_time,se.end_time,se.day_of_week,NULL schedule_date
          FROM schedule_entries se JOIN sections sec ON sec.id=se.section_id JOIN subjects sub ON sub.id=se.subject_id
          LEFT JOIN teachers t ON t.id=se.teacher_id WHERE se.status<>'Cancelled' AND se.day_of_week=:day
          AND se.start_time<:end AND se.end_time>:start";
    $p=$common+['day'=>$c['day']];
    if($c['academic_year']!==''){$sql.=" AND se.academic_year=:ay";$p['ay']=$c['academic_year'];}
    if($c['semester']!==''){$sql.=" AND se.semester=:sem";$p['sem']=$c['semester'];}
    $st=$pdo->prepare($sql);$st->execute($p);$all=array_merge($all,$st->fetchAll(PDO::FETCH_ASSOC));

    $sql="SELECT sc.id,sc.room_id,'Special' source_type,sc.status,COALESCE(sec.code,'Selected Students') section_code,
                 sub.code subject_code,sub.name subject_name,t.full_name faculty_name,sc.start_time,sc.end_time,
                 DAYNAME(sc.class_date) day_of_week,sc.class_date schedule_date
          FROM special_classes sc LEFT JOIN sections sec ON sec.id=sc.section_id LEFT JOIN subjects sub ON sub.id=sc.subject_id
          LEFT JOIN teachers t ON t.id=sc.teacher_id WHERE sc.status<>'Cancelled' AND sc.class_date=:date
          AND sc.start_time<:end AND sc.end_time>:start";
    $p=$common+['date'=>$c['date']];if($c['academic_year']!==''){$sql.=" AND sc.academic_year=:ay";$p['ay']=$c['academic_year'];}
    if($c['semester']!==''){$sql.=" AND sc.semester=:sem";$p['sem']=$c['semester'];}
    $st=$pdo->prepare($sql);$st->execute($p);$all=array_merge($all,$st->fetchAll(PDO::FETCH_ASSOC));

    $sql="SELECT ex.id,ex.room_id,'Exam' source_type,ex.status,sec.code section_code,sub.code subject_code,
                 sub.name subject_name,t.full_name faculty_name,ex.start_time,ex.end_time,
                 DAYNAME(ex.exam_date) day_of_week,ex.exam_date schedule_date
          FROM exam_schedules ex JOIN sections sec ON sec.id=ex.section_id JOIN subjects sub ON sub.id=ex.subject_id
          LEFT JOIN teachers t ON t.id=ex.proctor_id WHERE ex.status<>'Cancelled' AND ex.exam_date=:date
          AND ex.start_time<:end AND ex.end_time>:start";
    $p=$common+['date'=>$c['date']];if($c['academic_year']!==''){$sql.=" AND ex.academic_year=:ay";$p['ay']=$c['academic_year'];}
    if($c['semester']!==''){$sql.=" AND ex.semester=:sem";$p['sem']=$c['semester'];}
    $st=$pdo->prepare($sql);$st->execute($p);$all=array_merge($all,$st->fetchAll(PDO::FETCH_ASSOC));
    $out=[];foreach($all as $r){if(!$r['room_id'])continue;if($r['source_type']===$ignoreType&&(int)$r['id']===$ignoreId)continue;
        $r['id']=(int)$r['id'];$r['room_id']=(int)$r['room_id'];$r['start_time']=raShortTime($r['start_time']);$r['end_time']=raShortTime($r['end_time']);$out[]=$r;}
    return $out;
}
function raState(array $room,array $occupants): array {
    if($room['status']==='Maintenance')return ['Maintenance','Room is under maintenance.'];
    if($room['status']==='Reserved')return ['Reserved','Room is administratively reserved.'];
    if($room['status']!=='Available')return ['Unavailable','Room is not active for scheduling.'];
    if($occupants){
        foreach($occupants as $o)if(!in_array($o['status'],['Draft','For Scheduling'],true))return ['Occupied','An active schedule overlaps the selected time.'];
        return ['Reserved','A draft schedule currently holds this room.'];
    }
    return ['Available','No schedule overlaps the selected time.'];
}
function raChecks(array $room,array $c,array $facilities,array $occupants,?array $request=null): array {
    $missing=array_values(array_diff($c['facilities'],$facilities));
    $checks=[
        ['code'=>'active','label'=>'Room is active','passed'=>$room['status']==='Available','detail'=>$room['status']==='Available'?'Room is active.':'Room status is '.$room['status'].'.'],
        ['code'=>'maintenance','label'=>'Not under maintenance','passed'=>$room['status']!=='Maintenance','detail'=>$room['status']==='Maintenance'?'Room is under maintenance.':'No maintenance restriction.'],
        ['code'=>'type','label'=>'Correct room type','passed'=>$c['room_type']===''||$room['room_type']===$c['room_type'],'detail'=>$c['room_type']===''?'No room type was required.':($room['room_type']===$c['room_type']?'Room type matches.':'Requires '.$c['room_type'].'; this is '.$room['room_type'].'.')],
        ['code'=>'capacity','label'=>'Enough capacity','passed'=>(int)$room['capacity']>=$c['capacity'],'detail'=>(int)$room['capacity']>=$c['capacity']?$room['capacity'].' seats for '.$c['capacity'].' participant(s).':'Only '.$room['capacity'].' seats for '.$c['capacity'].' participant(s).'],
        ['code'=>'facilities','label'=>'Required facilities','passed'=>count($missing)===0,'detail'=>count($missing)===0?'All configured requirements are present.':'Missing: '.implode(', ',$missing).'.'],
        ['code'=>'overlap','label'=>'No schedule overlap','passed'=>count($occupants)===0,'detail'=>count($occupants)===0?'No overlapping room use.':count($occupants).' overlapping schedule(s) found.'],
    ];
    if($request&&$request['source_type']==='Regular'&&$request['day_of_week']&&$request['day_of_week']!==$c['day'])
        $checks[]=['code'=>'day','label'=>'Date matches recurring class day','passed'=>false,'detail'=>'This class is scheduled on '.$request['day_of_week'].', not '.$c['day'].'.'];
    $valid=!array_filter($checks,fn($x)=>!$x['passed']);
    return ['valid'=>$valid,'checks'=>$checks,'missing_facilities'=>$missing,'occupants'=>$occupants];
}
function raResultRows(PDO $pdo,array $c,?array $request=null): array {
    $facMap=raFacilityMap($pdo);$occ=raOccupancy($pdo,$c,$request['source_type']??null,$request['id']??0);$byRoom=[];
    foreach($occ as $o)$byRoom[$o['room_id']][]=$o;
    $rows=[];foreach(raRoomRows($pdo) as $room){$id=(int)$room['id'];$room['id']=$id;$room['capacity']=(int)$room['capacity'];$room['facilities']=$facMap[$id]??[];
        $roomOcc=$byRoom[$id]??[];[$state,$detail]=raState($room,$roomOcc);$validation=raChecks($room,$c,$room['facilities'],$roomOcc,$request);
        $room['state']=$state;$room['detail']=$detail;$room['occupied_by']=$roomOcc;$room['compatible']=$validation['valid'];$room['validation']=$validation;
        $matches=($c['building']===''||$room['building']===$c['building'])&&($c['room_type']===''||$room['room_type']===$c['room_type'])&&$room['capacity']>=$c['capacity']&&!array_diff($c['facilities'],$room['facilities']);
        if($matches)$rows[]=$room;
    }
    usort($rows,function($a,$b){$rank=['Available'=>0,'Reserved'=>1,'Occupied'=>2,'Maintenance'=>3,'Unavailable'=>4];return($rank[$a['state']]<=>$rank[$b['state']])?:strcmp($a['room_code'],$b['room_code']);});
    return $rows;
}
function raOptions(PDO $pdo): array {
    $terms=$pdo->query("SELECT DISTINCT academic_year,semester FROM (SELECT academic_year,semester FROM schedule_entries UNION SELECT academic_year,semester FROM special_classes UNION SELECT academic_year,semester FROM exam_schedules) x WHERE academic_year IS NOT NULL ORDER BY academic_year DESC,semester")->fetchAll(PDO::FETCH_ASSOC);
    return [
        'buildings'=>$pdo->query("SELECT DISTINCT building FROM rooms WHERE building IS NOT NULL AND building<>'' ORDER BY building")->fetchAll(PDO::FETCH_COLUMN),
        'room_types'=>$pdo->query("SELECT DISTINCT room_type FROM rooms ORDER BY room_type")->fetchAll(PDO::FETCH_COLUMN),
        'facilities'=>$pdo->query("SELECT DISTINCT facility_name FROM room_facilities WHERE status='Active' ORDER BY facility_name")->fetchAll(PDO::FETCH_COLUMN),
        'time_blocks'=>$pdo->query("SELECT id,code,label,start_time,end_time,block_type FROM time_blocks WHERE is_active=1 ORDER BY sort_order,start_time")->fetchAll(PDO::FETCH_ASSOC),
        'terms'=>$terms,
    ];
}

try{
    $pdo=getDatabaseConnection();$method=$_SERVER['REQUEST_METHOD']??'GET';$action=trim((string)($_GET['action']??'dashboard'));
    if($method==='GET'){
        if($action==='dashboard'){
            $date=isset($_GET['date'])?raDate((string)$_GET['date']):date('Y-m-d');
            $c=raCriteria(['date'=>$date,'start'=>'00:00','end'=>'23:59','academic_year'=>$_GET['academic_year']??'','semester'=>$_GET['semester']??'']);
            $rows=raResultRows($pdo,$c);$summary=['total'=>count($rows),'available'=>0,'occupied'=>0,'reserved'=>0,'maintenance'=>0];
            foreach($rows as $r){$key=strtolower($r['state']);if(isset($summary[$key]))$summary[$key]++;}
            $history=$pdo->query("SELECT h.id,h.source_type,h.source_record_id,h.assigned_at,n.room_code assigned_room,p.room_code previous_room,
                COALESCE(u.full_name,u.username,'System') assigned_by FROM room_assignment_history h JOIN rooms n ON n.id=h.assigned_room_id
                LEFT JOIN rooms p ON p.id=h.previous_room_id LEFT JOIN users u ON u.id=h.assigned_by ORDER BY h.assigned_at DESC,h.id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
            raJson(['ok'=>true,'summary'=>$summary,'options'=>raOptions($pdo),'requests'=>array_slice(raRequests($pdo),0,100),'history'=>$history,'as_of'=>$date]);
        }
        if($action==='requests'){
            $filters=['academic_year'=>$_GET['academic_year']??'','semester'=>$_GET['semester']??'','source_type'=>$_GET['source_type']??'','search'=>$_GET['search']??''];
            raJson(['ok'=>true,'requests'=>raRequests($pdo,$filters)]);
        }
        if($action==='search'||$action==='room'){
            $type=trim((string)($_GET['source_type']??''));$id=(int)($_GET['record_id']??0);$request=null;
            if($type!==''&&$id>0){$type=raSource($type);$request=raRequest($pdo,$type,$id);if(!$request)raJson(['ok'=>false,'error'=>'Scheduling request not found.'],404);}
            $c=raCriteria($_GET,$request);$rows=raResultRows($pdo,$c,$request);
            if($action==='room'){
                $roomId=(int)($_GET['room_id']??0);$room=null;foreach($rows as $candidate)if($candidate['id']===$roomId)$room=$candidate;
                if(!$room)raJson(['ok'=>false,'error'=>'Room not found.'],404);
                $dayCriteria=$c;$dayCriteria['start']='00:00';$dayCriteria['end']='23:59';
                $daily=array_values(array_filter(raOccupancy($pdo,$dayCriteria,$request['source_type']??null,$request['id']??0),fn($o)=>$o['room_id']===$roomId));
                raJson(['ok'=>true,'room'=>$room,'criteria'=>$c,'request'=>$request,'daily_schedule'=>$daily]);
            }
            $counts=['total'=>count($rows),'available'=>0,'occupied'=>0,'reserved'=>0,'maintenance'=>0,'compatible'=>0];foreach($rows as $r){$k=strtolower($r['state']);if(isset($counts[$k]))$counts[$k]++;if($r['compatible'])$counts['compatible']++;}
            raJson(['ok'=>true,'criteria'=>$c,'request'=>$request,'results'=>$rows,'summary'=>$counts]);
        }
        if($action==='history'){
            $stmt=$pdo->query("SELECT h.*,n.room_code assigned_room,p.room_code previous_room,COALESCE(u.full_name,u.username,'System') assigned_by_name
                FROM room_assignment_history h JOIN rooms n ON n.id=h.assigned_room_id LEFT JOIN rooms p ON p.id=h.previous_room_id
                LEFT JOIN users u ON u.id=h.assigned_by ORDER BY h.assigned_at DESC,h.id DESC LIMIT 100");
            raJson(['ok'=>true,'history'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        raJson(['ok'=>false,'error'=>'Unknown room availability action.'],400);
    }
    if($method!=='POST')raJson(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=raBody();$action=trim((string)($body['action']??''));
    $type=raSource((string)($body['source_type']??''));$recordId=(int)($body['record_id']??0);$roomId=(int)($body['room_id']??0);
    $request=raRequest($pdo,$type,$recordId);if(!$request)raJson(['ok'=>false,'error'=>'Scheduling request not found.'],404);
    $criteria=raCriteria($body,$request);$rows=raResultRows($pdo,$criteria,$request);$room=null;foreach($rows as $candidate)if($candidate['id']===$roomId)$room=$candidate;
    if(!$room)raJson(['ok'=>false,'error'=>'Room not found.'],404);
    if($action==='validate')raJson(['ok'=>true,'request'=>$request,'criteria'=>$criteria,'room'=>$room,'validation'=>$room['validation']]);
    if($action!=='assign')raJson(['ok'=>false,'error'=>'Unknown room availability action.'],400);

    $pdo->beginTransaction();
    $lockedRequest=raRequest($pdo,$type,$recordId,true);if(!$lockedRequest)throw new RuntimeException('The scheduling request no longer exists.');
    if($lockedRequest['status']==='Published')throw new RuntimeException('Published schedules cannot be changed here. Return them to an editable workflow first.');
    $lock=$pdo->prepare('SELECT id FROM rooms WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$roomId]);if(!$lock->fetchColumn())throw new RuntimeException('The selected room no longer exists.');
    $criteria=raCriteria($body,$lockedRequest);$rows=raResultRows($pdo,$criteria,$lockedRequest);$room=null;foreach($rows as $candidate)if($candidate['id']===$roomId)$room=$candidate;
    if(!$room||!$room['validation']['valid']){
        $failed=$room?array_values(array_map(fn($x)=>$x['detail'],array_filter($room['validation']['checks'],fn($x)=>!$x['passed']))):['Room not found.'];
        throw new RuntimeException('Final availability check failed: '.implode(' ',$failed));
    }
    $previousRoom=$lockedRequest['room_id'];
    if($type==='Regular'){$stmt=$pdo->prepare("UPDATE schedule_entries SET room_id=:room,status='Draft',updated_at=CURRENT_TIMESTAMP WHERE id=:id");}
    elseif($type==='Special'){$stmt=$pdo->prepare("UPDATE special_classes SET room_id=:room,status='Scheduled',validated_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id");}
    else{$stmt=$pdo->prepare("UPDATE exam_schedules SET room_id=:room,status='Draft',updated_at=CURRENT_TIMESTAMP WHERE id=:id");}
    $stmt->execute(['room'=>$roomId,'id'=>$recordId]);
    $hist=$pdo->prepare("INSERT INTO room_assignment_history(source_type,source_record_id,previous_room_id,assigned_room_id,schedule_snapshot_json,validation_json,assigned_by)
        VALUES(:type,:record,:previous,:assigned,:snapshot,:validation,:user)");
    $hist->execute(['type'=>$type,'record'=>$recordId,'previous'=>$previousRoom,'assigned'=>$roomId,
        'snapshot'=>json_encode($lockedRequest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'validation'=>json_encode($room['validation'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'user'=>function_exists('getCurrentUserId')?getCurrentUserId():null]);
    $historyId=(int)$pdo->lastInsertId();$pdo->commit();
    if(function_exists('logActivity'))logActivity('Assign room',"Assigned {$room['room_code']} to {$type} schedule #{$recordId} after final room validation.",'scheduling');
    $saved=raRequest($pdo,$type,$recordId);
    raJson(['ok'=>true,'message'=>'Room assigned successfully. The schedule is ready for full conflict validation.','assignment'=>$saved,'room'=>$room,'criteria'=>$criteria,'history_id'=>$historyId]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    error_log('Room Availability API error: '.$e->getMessage());
    $safe=($e instanceof InvalidArgumentException||$e instanceof RuntimeException)?$e->getMessage():'Room availability request failed. Please try again.';
    raJson(['ok'=>false,'error'=>$safe],$e instanceof InvalidArgumentException?422:500);
}
