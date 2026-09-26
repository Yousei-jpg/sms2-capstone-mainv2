<?php
// CLI regression cases; temporary tables isolate writes from the real records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/config.php';
require_once ROOT_PATH.'/config/database.php';
require_once ROOT_PATH.'/config/session.php';
require_once ROOT_PATH.'/modules/scheduling/provider/bootstrap.php';
require_once ROOT_PATH.'/modules/scheduling/section-assignment-state.php';
$pdo=getDatabaseConnection();
$section=$pdo->query("SELECT * FROM sections WHERE status='Active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$section) { fwrite(STDERR,"An active section is required for this regression test.\n"); exit(1); }
$eligible=scheduling_provider('curriculum')->getEligibleSubjects($section['program'],(int)$section['year_level'],$section['semester']);
if (!$eligible) { fwrite(STDERR,"The selected section needs eligible curriculum subjects.\n"); exit(1); }
$codes=array_column($eligible,'code');
$case=$argv[1]??'validate';
$payload=['action'=>'validate','id'=>$section['id'],'program'=>$section['program'],'year_level'=>$section['year_level'],'academic_year'=>$section['academic_year'],'semester'=>$section['semester'],'subjects'=>$codes];
$st=$pdo->prepare("SELECT s.code FROM section_subjects ss JOIN subjects s ON s.id=ss.subject_id WHERE ss.section_id=? AND ss.status='Assigned'");
$st->execute([$section['id']]);
$payload['revision']=sectionAssignmentRevision($section,$st->fetchAll(PDO::FETCH_COLUMN));
$st=$pdo->prepare("SELECT subject_id FROM schedule_entries WHERE section_id=? AND status<>'Cancelled'");
$st->execute([$section['id']]);$scheduledIds=$st->fetchAll(PDO::FETCH_COLUMN);
$expected=true;
switch ($case) {
    case 'stale': $payload['revision']='outdated'; $expected=false; break;
    case 'missing-revision': unset($payload['revision']); $expected=false; break;
    case 'scheduled-removal':
        if (!$scheduledIds || count($codes)<2) { fwrite(STDERR,"Scheduled subjects required for removal test.\n"); exit(1); }
        $remove=array_column($eligible,'code','id')[$scheduledIds[0]];
        $payload['subjects']=array_values(array_diff($codes,[$remove]));$expected=false;break;
    case 'duplicate': $payload['subjects'][]=$codes[0]; $expected=false; break;
    case 'period': $payload['academic_year']='1900-1901'; $expected=false; break;
    case 'missing': unset($payload['id']); $expected=false; break;
    case 'readonly': $payload['name']='Changed official name'; $expected=false; break;
    case 'curriculum': $payload['subjects']=['NOT-A-SUBJECT']; $expected=false; break;
    case 'empty': $payload['subjects']=[]; $expected=false; break;
    case 'save':
    case 'save-ready':
    case 'audit-rollback':
        $payload['action']='save';
        // Shadow the only tables this endpoint writes, preserving their schema.
        $tables=['section_subjects','activity_logs'];
        if ($case==='save-ready') $tables[]='schedule_entries';
        foreach ($tables as $table) {
            $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
            $ddl=preg_replace('/^\s*CONSTRAINT .*$/m','',$ddl);
            $ddl=preg_replace('/,\s*\)/',"\n)",$ddl);
            $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
        }
        $payload['revision']=sectionAssignmentRevision($section,[]);
        if ($case==='audit-rollback') {
            // Only the connection-local temporary audit table is altered.
            $pdo->exec('ALTER TABLE activity_logs DROP COLUMN detail');$expected=false;
        }
        break;
}
$_SESSION['user_id']=1; $_SESSION['user_role_key']='registrar'; $_SESSION['user_name']='Isolated test';
$_SERVER['REQUEST_METHOD']=$case==='load'?'GET':'POST'; $_POST=$payload;
ob_start();
register_shutdown_function(function() use($expected,$case,$pdo,$section,$codes,$scheduledIds) {
    $raw=ob_get_clean(); $result=json_decode($raw,true);
    $pass=is_array($result)&&($result['ok']??null)===$expected;
    if ($pass && $case==='load') {
        $loaded=array_values(array_filter($result['sections']??[],fn($s)=>(int)$s['id']===(int)$section['id']));
        $pass=count($loaded)===1;
        if ($pass) {
            $pass=$loaded[0]['revision']===sectionAssignmentRevision($section,$loaded[0]['subjects']);
            $pass=$pass && $loaded[0]['scheduling_status']===sectionAssignmentStatus($loaded[0]['subjects'],(bool)$scheduledIds);
        }
    }
    if ($pass && in_array($case,['stale','missing-revision'],true)) $pass=http_response_code()===409;
    if ($pass && in_array($case,['save','save-ready','audit-rollback'],true)) {
        $st=$pdo->prepare("SELECT COUNT(*) FROM section_subjects WHERE section_id=? AND status='Assigned'");
        $st->execute([$section['id']]);
        $pass=(int)$st->fetchColumn()===($case==='audit-rollback'?0:count($codes));
        $st=$pdo->prepare("SELECT * FROM sections WHERE id=?");$st->execute([$section['id']]);
        $pass=$pass && $st->fetch(PDO::FETCH_ASSOC)===$section;
        $pass=$pass && (int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()===($case==='audit-rollback'?0:1);
        if ($case!=='audit-rollback') {
            $status=$case==='save-ready'?'Ready for Scheduling':sectionAssignmentStatus($codes,(bool)$scheduledIds);
            $pass=$pass && ($result['status']??'')===$status;
            $pass=$pass && ($result['revision']??'')===sectionAssignmentRevision($section,$codes);
        } else $pass=$pass && http_response_code()===500;
    }
    echo ($pass?'PASS ':'FAIL ').$case.' '.($result['error']??$result['message']??($case==='load'?'Section revisions and statuses checked.':$raw)).PHP_EOL;
    if (!$pass) exit(1);
});
require ROOT_PATH.'/api/scheduling/section-assignment.php';
