<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/audit.php';
require_once ROOT_PATH . '/modules/scheduling/provider/bootstrap.php';
require_once ROOT_PATH . '/modules/scheduling/section-assignment-state.php';
header('Content-Type: application/json; charset=utf-8');
function satReply(array $data, int $status = 200): never {
    http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
if (!isAuthenticated()) satReply(['ok'=>false,'error'=>'Authentication required.'],401);
if (!userCanAccessModule('scheduling')) satReply(['ok'=>false,'error'=>'Scheduling access required.'],403);
$pdo = getDatabaseConnection();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sections = scheduling_provider('enrollment')->getSections();
        $assigned = $pdo->query("SELECT ss.section_id, s.code FROM section_subjects ss JOIN subjects s ON s.id=ss.subject_id WHERE ss.status='Assigned'")->fetchAll(PDO::FETCH_ASSOC);
        $usage = $pdo->query("SELECT section_id, subject_id, teacher_id, room_id, day_of_week, start_time, end_time, status FROM schedule_entries WHERE status<>'Cancelled'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sections as &$section) {
            $section['subjects']=array_values(array_column(array_filter($assigned, fn($a)=>(int)$a['section_id']===(int)$section['id']),'code'));
            $section['requirements']=array_values(array_filter($usage,fn($a)=>(int)$a['section_id']===(int)$section['id']));
            // Readiness is derived from persisted assignments, separate from the official section status.
            $section['scheduling_status']=sectionAssignmentStatus($section['subjects'], (bool)$section['requirements']);
            $section['revision']=sectionAssignmentRevision($section, $section['subjects']);
        }
        unset($section);
        $history=$pdo->query("SELECT created_at, user_name, action, detail FROM activity_logs WHERE module_key='scheduling' AND (action IN ('Save section','Update section','Create section') OR detail LIKE 'Section assignment:%') ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        satReply(['ok'=>true,'sections'=>$sections,'subjects'=>scheduling_provider('curriculum')->getSubjects(),'max_units'=>scheduling_provider('curriculum')->getMaxUnitsPerSection(),'history'=>$history]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') satReply(['ok'=>false,'error'=>'Method not allowed.'],405);
    $raw=file_get_contents('php://input');
    $p=$raw ? json_decode($raw,true) : $_POST;
    if (!is_array($p)) throw new InvalidArgumentException('Invalid request.');
    $action=$p['action']??'save';
    if (!in_array($action,['save','validate'],true)) throw new InvalidArgumentException('Invalid action.');
    $id=filter_var($p['id']??null,FILTER_VALIDATE_INT);
    if (!$id || $id<1) throw new InvalidArgumentException('Select an existing section first.');
    $pdo->beginTransaction();
    $st=$pdo->prepare("SELECT * FROM sections WHERE id=? FOR UPDATE"); $st->execute([$id]);
    $section=$st->fetch(PDO::FETCH_ASSOC);
    if (!$section || $section['status']!=='Active') throw new InvalidArgumentException('Select an existing active section.');
    foreach (['academic_year','semester','program','year_level'] as $field) {
        if (!isset($p[$field]) || (string)$p[$field] !== (string)$section[$field]) throw new InvalidArgumentException('The selected academic period, program, or year level does not match this section. Reload the section.');
    }
    foreach (['code','name','max_students','advisor_name'] as $field) {
        if (isset($p[$field]) && (string)$p[$field] !== (string)($section[$field]??'')) throw new InvalidArgumentException('Official section information is read-only. Correct it in the source module.');
    }
    if (!$section['code'] || !$section['name'] || (int)$section['max_students']<1 || (int)$section['max_students']<(int)$section['current_students']) throw new InvalidArgumentException('Official section details or capacity are incomplete. Correct them in the source module.');
    $registrar=scheduling_provider('registrar');
    if (!in_array($section['semester'],$registrar->getSemesters(),true) || !in_array($section['academic_year'],$registrar->getAcademicYears(),true)) throw new InvalidArgumentException('The section academic period is not valid.');
    $st=$pdo->prepare("SELECT sub.code FROM section_subjects ss JOIN subjects sub ON sub.id=ss.subject_id WHERE ss.section_id=? AND ss.status='Assigned' FOR UPDATE");
    $st->execute([$id]);
    $previousCodes=$st->fetchAll(PDO::FETCH_COLUMN);
    $revision=$p['revision']??'';
    if (!is_string($revision) || !hash_equals(sectionAssignmentRevision($section,$previousCodes),$revision)) {
        throw new DomainException('This section or its subject assignments changed. Refresh, review the latest selection, and validate again.');
    }
    $codes=$p['subjects']??[];
    if (!is_array($codes) || !$codes) throw new InvalidArgumentException('Confirm at least one subject.');
    foreach ($codes as &$code) {
        if (!is_string($code) || trim($code)==='') throw new InvalidArgumentException('Invalid subject selection.');
        $code=strtoupper(trim($code));
    }
    unset($code);
    if (count($codes)!==count(array_unique($codes))) throw new InvalidArgumentException('Duplicate subject assignments are not allowed.');
    $curriculum=scheduling_provider('curriculum');
    $eligible=$curriculum->getEligibleSubjects($section['program'],(int)$section['year_level'],$section['semester']);
    $byCode=array_column($eligible,null,'code'); $ids=[]; $units=0;
    foreach ($codes as $code) {
        if (!isset($byCode[$code])) throw new InvalidArgumentException($code.' is not active or does not belong to this section curriculum.');
        $ids[]=(int)$byCode[$code]['id']; $units+=(float)$byCode[$code]['units'];
    }
    $max=$curriculum->getMaxUnitsPerSection();
    if ($max>0 && $units>$max) throw new InvalidArgumentException('Selected units exceed the configured curriculum limit of '.$max.'.');
    $st=$pdo->prepare("SELECT subject_id FROM schedule_entries WHERE section_id=? AND status<>'Cancelled' FOR UPDATE"); $st->execute([$id]);
    $usedIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
    if (array_diff($usedIds,$ids)) throw new InvalidArgumentException('A subject already has a schedule. Update or cancel its schedule before removing it.');
    $checks=['Section exists and is active','Academic period matches','Program and year level match','Official section details are complete','Subjects belong to the curriculum','No duplicate subject assignments','Unit limit and existing schedules checked'];
    if ($action==='validate') {
        $pdo->rollBack();
        satReply(['ok'=>true,'checks'=>$checks,'total_units'=>$units,'message'=>'Requirements are valid. You can save this assignment.']);
    }
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("UPDATE section_subjects SET status='Dropped' WHERE section_id=? AND status='Assigned' AND subject_id NOT IN ($marks)"); $st->execute(array_merge([$id],$ids));
    $st=$pdo->prepare("INSERT INTO section_subjects (section_id,subject_id,status,created_by) VALUES (?,?,'Assigned',?) ON DUPLICATE KEY UPDATE status='Assigned'");
    foreach ($ids as $subjectId) $st->execute([$id,$subjectId,getCurrentUserId()]);
    $status=sectionAssignmentStatus($codes,(bool)$usedIds);
    // Audit succeeds in the same transaction as the assignment, or neither saves.
    $detail='Section assignment: '.$section['code'].' ['.$id.'] - '.$status.'; subjects: '.implode(', ',$codes);
    $audit=$pdo->prepare("INSERT INTO activity_logs (user_id,user_name,role_key,action,module_key,detail,ip_address,user_agent) VALUES (?,?,?,'Save section','scheduling',?,?,?)");
    $audit->execute([getCurrentUserId(),getCurrentUserName(),getCurrentUserRoleKey(),substr($detail,0,500),smsClientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
    $pdo->commit();
    satReply(['ok'=>true,'section'=>$id,'status'=>$status,'revision'=>sectionAssignmentRevision($section,$codes),'message'=>'Section-subject assignment saved. Status: '.$status.'.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $stale=$e instanceof DomainException;
    $expected=$e instanceof InvalidArgumentException || $stale;
    if (!$expected) error_log('Section assignment: '.$e->getMessage());
    satReply(['ok'=>false,'error'=>$expected?$e->getMessage():'Unable to save the assignment. Please try again.'],$stale?409:($expected?422:500));
}
