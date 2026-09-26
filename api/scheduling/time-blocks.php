<?php
declare(strict_types=1);

/** Time Block Generator API - approved periods shared by scheduling modules. */
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

const TB_TYPES = ['Class','Break','Exam'];

function tbOut(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function tbBody():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];$data=json_decode($raw,true);return is_array($data)?$data:[];}
function tbUserId():?int{if(function_exists('getCurrentUserId')){$id=getCurrentUserId();return $id?(int)$id:null;}return !empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;}
function tbTime(string $value,string $field):string{$value=trim($value);if(!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/',$value,$m))throw new InvalidArgumentException("Please enter a valid {$field} (HH:MM).");$h=(int)$m[1];$i=(int)$m[2];if($h>23||$i>59)throw new InvalidArgumentException("Please enter a valid {$field} (HH:MM).");return sprintf('%02d:%02d:00',$h,$i);}
function tbMinutes(string $time):int{[$h,$m]=array_map('intval',explode(':',$time));return $h*60+$m;}
function tbFromMinutes(int $minutes):string{return sprintf('%02d:%02d:00',intdiv($minutes,60),$minutes%60);}
function tbShort(string $time):string{return substr($time,0,5);}

function tbFields(array $p):array{
    $code=strtoupper(trim((string)($p['code']??'')));
    if($code===''||!preg_match('/^[A-Z0-9_-]{1,20}$/',$code))throw new InvalidArgumentException('Block code is required and may contain only letters, numbers, hyphens, and underscores (maximum 20).');
    $start=tbTime((string)($p['start_time']??''),'start time');$end=tbTime((string)($p['end_time']??''),'end time');
    if($start>=$end)throw new InvalidArgumentException('Start time must be earlier than end time.');
    $duration=tbMinutes($end)-tbMinutes($start);if($duration<15||$duration>360)throw new InvalidArgumentException('Duration must be between 15 minutes and 6 hours.');
    $opStart=isset($p['operating_start'])&&$p['operating_start']!==''?tbTime((string)$p['operating_start'],'operating start time'):'00:00:00';
    $opEnd=isset($p['operating_end'])&&$p['operating_end']!==''?tbTime((string)$p['operating_end'],'operating end time'):'23:59:00';
    if($opStart>=$opEnd)throw new InvalidArgumentException('Configured operating start must be earlier than operating end.');
    if($start<$opStart||$end>$opEnd)throw new InvalidArgumentException('The block is outside the configured operating hours.');
    $type=trim((string)($p['block_type']??'Class'));if(!in_array($type,TB_TYPES,true))throw new InvalidArgumentException('Select a valid block type.');
    $label=trim((string)($p['label']??''));if($label==='')$label=tbShort($start).' - '.tbShort($end);if(mb_strlen($label)>60)throw new InvalidArgumentException('Label must be 60 characters or fewer.');
    return ['code'=>$code,'label'=>$label,'start_time'=>$start,'end_time'=>$end,'block_type'=>$type,'sort_order'=>max(0,(int)($p['sort_order']??0)),'is_active'=>!empty($p['is_active'])?1:0,'duration_minutes'=>$duration];
}

function tbPool(string $type):string{return $type==='Exam'?"block_type='Exam'":"block_type<>'Exam'";}
function tbAssertCodeFree(PDO $pdo,string $code,?int $ignore=null):void{$sql='SELECT id FROM time_blocks WHERE code=:code';$params=['code'=>$code];if($ignore){$sql.=' AND id<>:id';$params['id']=$ignore;}$st=$pdo->prepare($sql.' LIMIT 1');$st->execute($params);if($st->fetch())throw new RuntimeException("A time block with code {$code} already exists.");}
function tbFindOverlap(PDO $pdo,string $start,string $end,string $type,?int $ignore=null):?array{$sql='SELECT id,code,label,start_time,end_time FROM time_blocks WHERE is_active=1 AND '.tbPool($type).' AND start_time<:end_time AND end_time>:start_time';$params=['start_time'=>$start,'end_time'=>$end];if($ignore){$sql.=' AND id<>:id';$params['id']=$ignore;}$st=$pdo->prepare($sql.' ORDER BY start_time LIMIT 1');$st->execute($params);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null;}
function tbAssertNoOverlap(PDO $pdo,array $f,?int $ignore=null):void{if((int)$f['is_active']!==1)return;$c=tbFindOverlap($pdo,$f['start_time'],$f['end_time'],$f['block_type'],$ignore);if($c)throw new RuntimeException("This period overlaps active block {$c['code']} ({$c['label']}). Adjust the time or deactivate the conflicting block first.");}

function tbUsage(PDO $pdo,int $id,?array $block=null):array{
    if(!$block){$st=$pdo->prepare('SELECT * FROM time_blocks WHERE id=?');$st->execute([$id]);$block=$st->fetch(PDO::FETCH_ASSOC)?:[];}
    $a=$pdo->prepare('SELECT COUNT(*) FROM schedule_entries WHERE time_block_id=?');$a->execute([$id]);$regular=(int)$a->fetchColumn();
    $b=$pdo->prepare('SELECT COUNT(*) FROM special_classes WHERE time_block_id=?');$b->execute([$id]);$special=(int)$b->fetchColumn();$exam=0;
    if(($block['block_type']??'')==='Exam'){$c=$pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE status<>'Cancelled' AND start_time=? AND end_time=?");$c->execute([$block['start_time'],$block['end_time']]);$exam=(int)$c->fetchColumn();}
    return ['regular'=>$regular,'special'=>$special,'exam'=>$exam,'total'=>$regular+$special+$exam];
}
function tbHistory(PDO $pdo,?int $blockId,?int $setId,string $action,string $detail,array $snapshot=[]):void{$st=$pdo->prepare('INSERT INTO time_block_history(time_block_id,time_block_set_id,action,detail,snapshot_json,changed_by) VALUES(?,?,?,?,?,?)');$st->execute([$blockId,$setId,$action,$detail,$snapshot?json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,tbUserId()]);}

function tbDashboard(PDO $pdo):array{
    $rows=$pdo->query("SELECT tb.*,(SELECT COUNT(*) FROM schedule_entries se WHERE se.time_block_id=tb.id) regular_usage,(SELECT COUNT(*) FROM special_classes sc WHERE sc.time_block_id=tb.id) special_usage,CASE WHEN tb.block_type='Exam' THEN (SELECT COUNT(*) FROM exam_schedules es WHERE es.status<>'Cancelled' AND es.start_time=tb.start_time AND es.end_time=tb.end_time) ELSE 0 END exam_usage,tbs.name set_name,tbs.reference_no set_reference FROM time_blocks tb LEFT JOIN time_block_set_items tsi ON tsi.time_block_id=tb.id LEFT JOIN time_block_sets tbs ON tbs.id=tsi.time_block_set_id ORDER BY tb.sort_order,tb.start_time,tb.id")->fetchAll(PDO::FETCH_ASSOC);
    $sum=['total'=>count($rows),'active'=>0,'inactive'=>0,'regular'=>0,'custom'=>0,'exam'=>0,'break'=>0,'in_use'=>0];
    foreach($rows as &$r){$r['usage_count']=(int)$r['regular_usage']+(int)$r['special_usage']+(int)$r['exam_usage'];$r['duration_minutes']=tbMinutes($r['end_time'])-tbMinutes($r['start_time']);$r['category']=$r['block_type']==='Exam'?'Exam-specific':($r['block_type']==='Break'?'Break':($r['set_name']?'Regular':'Custom'));$sum[(int)$r['is_active']===1?'active':'inactive']++;if($r['category']==='Regular')$sum['regular']++;elseif($r['category']==='Custom')$sum['custom']++;elseif($r['category']==='Exam-specific')$sum['exam']++;else$sum['break']++;if($r['usage_count']>0)$sum['in_use']++;}unset($r);
    $sets=$pdo->query("SELECT s.*,COALESCE(u.full_name,u.username,'System') created_by_name FROM time_block_sets s LEFT JOIN users u ON u.id=s.created_by ORDER BY s.created_at DESC,s.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $history=$pdo->query("SELECT h.*,tb.code block_code,tbs.reference_no set_reference,COALESCE(u.full_name,u.username,'System') changed_by_name FROM time_block_history h LEFT JOIN time_blocks tb ON tb.id=h.time_block_id LEFT JOIN time_block_sets tbs ON tbs.id=h.time_block_set_id LEFT JOIN users u ON u.id=h.changed_by ORDER BY h.created_at DESC,h.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
    return ['blocks'=>$rows,'summary'=>$sum,'sets'=>$sets,'history'=>$history];
}

function tbGenerate(PDO $pdo,array $p):array{
    $name=trim((string)($p['set_name']??''));if($name==='')throw new InvalidArgumentException('Time block set name is required.');if(mb_strlen($name)>100)throw new InvalidArgumentException('Set name must be 100 characters or fewer.');
    $start=tbTime((string)($p['start_time']??''),'range start');$end=tbTime((string)($p['end_time']??''),'range end');if($start>=$end)throw new InvalidArgumentException('Range start must be earlier than range end.');
    $interval=(int)($p['interval_minutes']??0);if($interval<15||$interval>360)throw new InvalidArgumentException('Interval must be between 15 and 360 minutes.');
    $type=trim((string)($p['block_type']??'Class'));if(!in_array($type,['Class','Exam'],true))throw new InvalidArgumentException('Generated sets may be Regular Class or Exam-specific.');
    $includeBreak=!empty($p['include_break']);$bs=$includeBreak?tbTime((string)($p['break_start']??''),'break start'):null;$be=$includeBreak?tbTime((string)($p['break_end']??''),'break end'):null;
    if($includeBreak&&($bs>=$be||$bs<$start||$be>$end))throw new InvalidArgumentException('The break must have a valid duration and stay inside the configured range.');
    $errors=[];$warnings=[];$blocks=[];$cursor=tbMinutes($start);$finish=tbMinutes($end);$bsm=$bs?tbMinutes($bs):null;$bem=$be?tbMinutes($be):null;
    while($cursor<$finish){
        if($includeBreak&&$cursor===$bsm){$blocks[]=['code'=>'BRK-'.str_replace(':','',tbShort($bs)).'-'.str_replace(':','',tbShort($be)),'label'=>trim((string)($p['break_label']??''))?:'Scheduled Break','start_time'=>$bs,'end_time'=>$be,'block_type'=>'Break','duration_minutes'=>$bem-$bsm];$cursor=$bem;continue;}
        $next=$cursor+$interval;if($includeBreak&&$cursor<$bsm&&$next>$bsm){$errors[]='The break starts inside a generated block. Align the break with the selected interval.';break;}if($includeBreak&&$cursor>$bsm&&$cursor<$bem){$cursor=$bem;continue;}if($next>$finish){$remainder=$finish-$cursor;$warnings[]='The final '.$remainder.' minutes do not complete a full interval; no partial block will be generated.';break;}
        $s=tbFromMinutes($cursor);$e=tbFromMinutes($next);$prefix=$type==='Exam'?'EXM':'CLS';$blocks[]=['code'=>$prefix.'-'.str_replace(':','',tbShort($s)).'-'.str_replace(':','',tbShort($e)),'label'=>($type==='Exam'?'Exam ':'Regular ').tbShort($s).' - '.tbShort($e),'start_time'=>$s,'end_time'=>$e,'block_type'=>$type,'duration_minutes'=>$interval];$cursor=$next;
    }
    if(!$blocks)$errors[]='No complete time blocks can be generated from these settings.';
    $codeSt=$pdo->prepare('SELECT code FROM time_blocks WHERE code=? LIMIT 1');$exactSt=$pdo->prepare('SELECT id,code,label,is_active FROM time_blocks WHERE block_type=? AND start_time=? AND end_time=? LIMIT 1');
    foreach($blocks as &$b){$b['status']='Ready';$b['issues']=[];$exactSt->execute([$b['block_type'],$b['start_time'],$b['end_time']]);$exact=$exactSt->fetch(PDO::FETCH_ASSOC);if($exact&&(int)$exact['is_active']===1){$b['existing_id']=(int)$exact['id'];$b['reused']=true;}elseif($exact){$b['issues'][]="Matching block {$exact['code']} ({$exact['label']}) exists but is inactive; reactivate it or choose another period.";}else{$codeSt->execute([$b['code']]);if($codeSt->fetchColumn())$b['issues'][]="Code {$b['code']} already exists.";$overlap=tbFindOverlap($pdo,$b['start_time'],$b['end_time'],$b['block_type']);if($overlap)$b['issues'][]="Overlaps {$overlap['code']} ({$overlap['label']}).";}if($b['issues']){$b['status']='Needs adjustment';foreach($b['issues'] as $issue)$errors[]=$b['code'].': '.$issue;}}unset($b);
    if(!$includeBreak)$warnings[]='No break or exclusion is configured for this set.';
    return ['valid'=>!$errors,'errors'=>array_values(array_unique($errors)),'warnings'=>$warnings,'blocks'=>$blocks,'config'=>['set_name'=>$name,'start_time'=>$start,'end_time'=>$end,'interval_minutes'=>$interval,'include_break'=>$includeBreak,'break_start'=>$bs,'break_end'=>$be,'break_label'=>trim((string)($p['break_label']??''))?:'Scheduled Break','block_type'=>$type,'activate'=>!array_key_exists('activate',$p)||!empty($p['activate'])]];
}

try{
    $pdo=getDatabaseConnection();$method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){$action=trim((string)($_GET['action']??'dashboard'));$data=tbDashboard($pdo);if($action==='history')tbOut(['ok'=>true,'history'=>$data['history']]);tbOut(['ok'=>true]+$data+['consumers'=>[
        ['key'=>'section','name'=>'Section Assignment','icon'=>'fa-users-rectangle','use'=>'Uses approved class periods when organizing sections.'],
        ['key'=>'teacher','name'=>'Teacher Schedule Mapping','icon'=>'fa-chalkboard-user','use'=>'Offers active class blocks for faculty assignments.'],
        ['key'=>'room','name'=>'Room Availability','icon'=>'fa-building','use'=>'Checks room occupancy against exact block times.'],
        ['key'=>'special','name'=>'Special Class Scheduler','icon'=>'fa-star','use'=>'Uses active regular or custom class blocks.'],
        ['key'=>'exam','name'=>'Exam Timetable Generator','icon'=>'fa-file-circle-check','use'=>'Uses approved exam-specific block periods.'],
        ['key'=>'conflict','name'=>'Conflict Checker','icon'=>'fa-shield-halved','use'=>'Rejects invalid or inactive linked time blocks.']]]);}
    if($method!=='POST')tbOut(['ok'=>false,'error'=>'Method not allowed.'],405);
    $p=tbBody();$action=trim((string)($p['action']??''));
    if($action==='validate_single'){$f=tbFields($p);$id=!empty($p['id'])?(int)$p['id']:null;tbAssertCodeFree($pdo,$f['code'],$id);tbAssertNoOverlap($pdo,$f,$id);tbOut(['ok'=>true,'valid'=>true,'duration_minutes'=>$f['duration_minutes'],'checks'=>['Required fields complete','Start is earlier than end','Duration is valid','Within configured operating hours','No duplicate code','No active overlap']]);}
    if($action==='preview_set')tbOut(['ok'=>true,'preview'=>tbGenerate($pdo,$p)]);
    if($action==='create'||$action==='update'){
        $id=$action==='update'?(int)($p['id']??0):null;if($action==='update'&&!$id)throw new InvalidArgumentException('A valid time block id is required.');$before=null;
        if($id){$st=$pdo->prepare('SELECT * FROM time_blocks WHERE id=?');$st->execute([$id]);$before=$st->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Time block not found.');}
        $f=tbFields($p);tbAssertCodeFree($pdo,$f['code'],$id);tbAssertNoOverlap($pdo,$f,$id);unset($f['duration_minutes']);if($f['sort_order']===0)$f['sort_order']=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM time_blocks')->fetchColumn();
        $pdo->beginTransaction();if($id){$st=$pdo->prepare('UPDATE time_blocks SET code=:code,label=:label,start_time=:start_time,end_time=:end_time,block_type=:block_type,sort_order=:sort_order,is_active=:is_active WHERE id=:id');$st->execute($f+['id'=>$id]);}else{$st=$pdo->prepare('INSERT INTO time_blocks(code,label,start_time,end_time,block_type,sort_order,is_active,created_by) VALUES(:code,:label,:start_time,:end_time,:block_type,:sort_order,:is_active,:created_by)');$st->execute($f+['created_by'=>tbUserId()]);$id=(int)$pdo->lastInsertId();}
        tbHistory($pdo,$id,null,$action==='create'?'Created':'Updated',($action==='create'?'Created ':'Updated ').$f['code'].'.',['before'=>$before,'after'=>$f]);$pdo->commit();logActivity($action==='create'?'Create time block':'Update time block',$f['code'].' '.tbShort($f['start_time']).'-'.tbShort($f['end_time']),'scheduling');tbOut(['ok'=>true,'id'=>$id,'message'=>"Time block {$f['code']} ".($action==='create'?'created':'updated').' after validation.']);
    }
    if($action==='save_set'){
        $pdo->beginTransaction();$pdo->query('SELECT id FROM time_blocks FOR UPDATE');$preview=tbGenerate($pdo,$p);if(!$preview['valid'])throw new RuntimeException('Save stopped: '.implode(' ',array_slice($preview['errors'],0,3)));$c=$preview['config'];$status=$c['activate']?'Active':'Inactive';
        $st=$pdo->prepare('INSERT INTO time_block_sets(reference_no,name,start_time,end_time,interval_minutes,break_start,break_end,block_type,status,settings_json,block_count,created_by) VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?)');$st->execute([$c['set_name'],$c['start_time'],$c['end_time'],$c['interval_minutes'],$c['break_start'],$c['break_end'],$c['block_type'],$status,json_encode($c,JSON_UNESCAPED_SLASHES),count($preview['blocks']),tbUserId()]);$setId=(int)$pdo->lastInsertId();$ref='TBS-'.str_pad((string)$setId,6,'0',STR_PAD_LEFT);$pdo->prepare('UPDATE time_block_sets SET reference_no=? WHERE id=?')->execute([$ref,$setId]);
        $sort=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM time_blocks')->fetchColumn();$insert=$pdo->prepare('INSERT INTO time_blocks(code,label,start_time,end_time,block_type,sort_order,is_active,created_by) VALUES(?,?,?,?,?,?,?,?)');$link=$pdo->prepare('INSERT INTO time_block_set_items(time_block_set_id,time_block_id,sequence_no) VALUES(?,?,?)');
        foreach($preview['blocks'] as $i=>$b){if(!empty($b['existing_id'])){$blockId=(int)$b['existing_id'];$historyAction='Linked existing';$historyDetail="Linked existing {$b['code']} to {$ref}.";}else{$insert->execute([$b['code'],$b['label'],$b['start_time'],$b['end_time'],$b['block_type'],$sort+1,$c['activate']?1:0,tbUserId()]);$blockId=(int)$pdo->lastInsertId();$sort++;$historyAction='Generated';$historyDetail="Generated {$b['code']} in {$ref}.";}$link->execute([$setId,$blockId,$i+1]);tbHistory($pdo,$blockId,$setId,$historyAction,$historyDetail,$b);}tbHistory($pdo,null,$setId,'Set saved',"Saved {$ref} with ".count($preview['blocks']).' validated blocks.',$c);$pdo->commit();logActivity('Generate time blocks',"{$ref}: ".count($preview['blocks'])." blocks saved as {$status}.",'scheduling');tbOut(['ok'=>true,'message'=>count($preview['blocks']).' time blocks created or reused successfully.','set'=>['id'=>$setId,'reference_no'=>$ref,'name'=>$c['set_name'],'status'=>$status,'block_count'=>count($preview['blocks'])],'blocks'=>$preview['blocks']]);
    }
    if($action==='toggle'){
        $id=(int)($p['id']??0);if(!$id)throw new InvalidArgumentException('A valid time block id is required.');$st=$pdo->prepare('SELECT * FROM time_blocks WHERE id=?');$st->execute([$id]);$b=$st->fetch(PDO::FETCH_ASSOC);if(!$b)throw new RuntimeException('Time block not found.');$new=(int)$b['is_active']===1?0:1;if($new===1)tbAssertNoOverlap($pdo,$b+['is_active'=>1],$id);$usage=tbUsage($pdo,$id,$b);
        $pdo->beginTransaction();$pdo->prepare('UPDATE time_blocks SET is_active=? WHERE id=?')->execute([$new,$id]);tbHistory($pdo,$id,null,$new?'Activated':'Deactivated',"{$b['code']} ".($new?'activated.':'deactivated.').($usage['total']?" It is referenced by {$usage['total']} schedule record(s).":''),$b+['new_state'=>$new,'usage'=>$usage]);$pdo->commit();logActivity($new?'Activate time block':'Deactivate time block',$b['code'],'scheduling');tbOut(['ok'=>true,'id'=>$id,'is_active'=>$new,'usage'=>$usage,'message'=>"Time block {$b['code']} ".($new?'activated.':'deactivated and retained for history.')]);
    }
    if($action==='delete'){
        $id=(int)($p['id']??0);if(!$id)throw new InvalidArgumentException('A valid time block id is required.');$st=$pdo->prepare('SELECT * FROM time_blocks WHERE id=?');$st->execute([$id]);$b=$st->fetch(PDO::FETCH_ASSOC);if(!$b)throw new RuntimeException('Time block not found.');$usage=tbUsage($pdo,$id,$b);if($usage['total']>0)throw new RuntimeException("{$b['code']} is used by {$usage['total']} schedule record(s). Deactivate it instead of deleting it.");$pdo->beginTransaction();tbHistory($pdo,$id,null,'Deleted',"Deleted unused block {$b['code']}.",$b);$pdo->prepare('DELETE FROM time_blocks WHERE id=?')->execute([$id]);$pdo->commit();logActivity('Delete time block',$b['code'],'scheduling');tbOut(['ok'=>true,'message'=>"Unused time block {$b['code']} deleted. Its audit entry was retained."]);
    }
    if($action==='reorder'){$order=$p['order']??[];if(!is_array($order)||!$order)throw new InvalidArgumentException('An ordered list of ids is required.');$pdo->beginTransaction();$st=$pdo->prepare('UPDATE time_blocks SET sort_order=? WHERE id=?');foreach(array_values($order) as $i=>$id)$st->execute([$i+1,(int)$id]);tbHistory($pdo,null,null,'Reordered','Updated time block display order.',['order'=>$order]);$pdo->commit();tbOut(['ok'=>true,'message'=>'Display order updated.']);}
    throw new InvalidArgumentException('Unknown action.');
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('Time Blocks API error: '.$e->getMessage());$safe=($e instanceof InvalidArgumentException||$e instanceof RuntimeException)?$e->getMessage():'Time block operation failed. Please contact the administrator.';tbOut(['ok'=>false,'error'=>$safe],$e instanceof InvalidArgumentException?422:409);}
