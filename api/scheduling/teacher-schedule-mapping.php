<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/scheduling/teacher-mapping-service.php';

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
try {
    $pdo = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode([
            'ok' => false,
            'error' => 'Method not allowed. Step 1 supports GET only.'
        ]);
        exit;
    }

    $action = trim((string)($_GET['action'] ?? 'data'));
    if ($action === 'history') {
        $historySection = trim((string)($_GET['section_code'] ?? ''));
        $sql = "SELECT id, user_name, role_key, action, detail, created_at
                FROM activity_logs
                WHERE module_key = 'scheduling' AND action = 'Save schedule'";
        $params = [];
        if ($historySection !== '') {
            $sql .= ' AND detail LIKE :section_detail';
            $params['section_detail'] = '%for ' . $historySection . '.%';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 100';
        $historyStmt = $pdo->prepare($sql);
        $historyStmt->execute($params);
        echo json_encode([
            'ok' => true,
            'section_code' => $historySection !== '' ? $historySection : null,
            'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action !== 'data') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown Teacher Schedule Mapping action.']);
        exit;
    }

    $sectionCode = trim((string)($_GET['section_code'] ?? ''));
    $sectionId = (int)($_GET['section_id'] ?? 0);
    $sectionWhere = $sectionId > 0 ? 'id = :selected' : 'code = :selected';
    $sectionParams = ['selected' => $sectionId > 0 ? $sectionId : $sectionCode];
    $selectedTerm = null;
    if ($sectionCode !== '' || $sectionId > 0) {
        $termStmt = $pdo->prepare(
            "SELECT academic_year, semester FROM sections WHERE $sectionWhere LIMIT 1"
        );
        $termStmt->execute($sectionParams);
        $selectedTerm = $termStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $sectionsSql = "
        SELECT
            s.id,
            s.code,
            s.name,
            s.program,
            s.year_level,
            s.semester,
            s.academic_year,
            s.max_students,
            s.current_students,
            s.advisor_name,
            s.status
        FROM sections s
        WHERE s.status = 'Active'
        ORDER BY s.code ASC
    ";
    $sections = $pdo->query($sectionsSql)->fetchAll(PDO::FETCH_ASSOC);

    $subjectsSql = "
        SELECT
            sub.id,
            sub.code,
            sub.name,
            sub.units,
            sub.subject_type,
            sub.program,
            sub.year_level,
            sub.semester,
            sub.status
        FROM subjects sub
        WHERE sub.status = 'Active'
        ORDER BY sub.code ASC
    ";
    $subjects = $pdo->query($subjectsSql)->fetchAll(PDO::FETCH_ASSOC);

    $teachersSql = "
        SELECT
            t.id,
            t.employee_no,
            t.full_name,
            t.department,
            t.email,
            t.max_load_units,
            COALESCE((
                SELECT SUM(sub.units)
                FROM schedule_entries se
                JOIN subjects sub ON sub.id = se.subject_id
                WHERE se.teacher_id = t.id
                  AND se.status IN ('Draft', 'Validated', 'Published')
                  AND (:term_disabled = 1 OR
                       (se.academic_year <=> :academic_year AND se.semester <=> :semester))
            ), 0) AS current_load_units,
            t.status
        FROM teachers t
        ORDER BY t.full_name ASC
    ";
    $teachersStmt = $pdo->prepare($teachersSql);
    $teachersStmt->execute([
        'term_disabled' => $selectedTerm ? 0 : 1,
        'academic_year' => $selectedTerm['academic_year'] ?? null,
        'semester' => $selectedTerm['semester'] ?? null,
    ]);
    $teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);

    $qualificationConfigured = false;
    $qualificationsByTeacher = [];
    try {
        $qualificationRows = $pdo->query(
            "SELECT q.teacher_id, q.subject_id, q.specialization_label,
                    sub.code AS subject_code, sub.name AS subject_name
             FROM teacher_subject_qualifications q
             JOIN subjects sub ON sub.id = q.subject_id
             WHERE q.status = 'Active' AND sub.status = 'Active'
             ORDER BY sub.code ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $qualificationConfigured = count($qualificationRows) > 0;
        foreach ($qualificationRows as $qualification) {
            $teacherId = (int)$qualification['teacher_id'];
            $qualificationsByTeacher[$teacherId][] = [
                'subject_id' => (int)$qualification['subject_id'],
                'subject_code' => $qualification['subject_code'],
                'subject_name' => $qualification['subject_name'],
                'specialization' => trim((string)($qualification['specialization_label'] ?? '')),
            ];
        }
    } catch (Throwable $ignored) {
        // The migration is deployable separately. Until it is applied the UI
        // clearly reports that qualification data is not configured.
    }

    foreach ($teachers as &$teacher) {
        $teacher['qualifications'] = $qualificationsByTeacher[(int)$teacher['id']] ?? [];
        $teacher['photo_url']=null;
        foreach(['jpg','jpeg','png','webp'] as $extension){
            $relative='/images/faculty/'.(int)$teacher['id'].'.'.$extension;
            if(is_file(ROOT_PATH.$relative)){$teacher['photo_url']=BASE_URL.$relative;break;}
        }
    }
    unset($teacher);

    $roomsSql = "
        SELECT
            r.id,
            r.room_code,
            r.building,
            r.room_type,
            r.capacity,
            r.status
        FROM rooms r
        ORDER BY r.room_code ASC
    ";
    $rooms = $pdo->query($roomsSql)->fetchAll(PDO::FETCH_ASSOC);

    $timeBlocksSql = "
        SELECT
            tb.id,
            tb.code,
            tb.label,
            tb.start_time,
            tb.end_time
        FROM time_blocks tb
        WHERE tb.is_active = 1
          AND tb.block_type = 'Class'
        ORDER BY tb.start_time ASC
    ";
    $timeBlocks = $pdo->query($timeBlocksSql)->fetchAll(PDO::FETCH_ASSOC);

    $scheduleSql = "
        SELECT
            se.id,
            se.section_id,
            s.code AS section_code,
            s.name AS section_name,
            se.subject_id,
            sub.code AS subject_code,
            sub.name AS subject_name,
            se.teacher_id,
            t.full_name AS teacher_name,
            se.room_id,
            r.room_code,
            se.day_of_week,
            se.start_time,
            se.end_time,
            se.class_type,
            se.status,
            se.remarks
        FROM schedule_entries se
        JOIN sections s ON s.id = se.section_id
        JOIN subjects sub ON sub.id = se.subject_id
        LEFT JOIN teachers t ON t.id = se.teacher_id
        LEFT JOIN rooms r ON r.id = se.room_id
        WHERE se.status IN ('Draft', 'Validated', 'Published')
          AND (:term_disabled = 1 OR
               (se.academic_year <=> :academic_year AND se.semester <=> :semester))
        ORDER BY s.code ASC, se.day_of_week ASC, se.start_time ASC
    ";
    $scheduleStmt = $pdo->prepare($scheduleSql);
    $scheduleStmt->execute([
        'term_disabled' => $selectedTerm ? 0 : 1,
        'academic_year' => $selectedTerm['academic_year'] ?? null,
        'semester' => $selectedTerm['semester'] ?? null,
    ]);
    $scheduleEntries = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'ok' => true,
        'section_code' => $sectionCode !== '' ? $sectionCode : null,
        'sections' => $sections,
        'subjects' => $subjects,
        'teachers' => $teachers,
        'rooms' => $rooms,
        'time_blocks' => $timeBlocks,
        'schedule_entries' => $scheduleEntries,
        'qualification_configured' => $qualificationConfigured,
    ];
    $allRecords=ccRecords($pdo);
    $response['availability_records']=array_values(array_map('ccPublicRecord',array_filter($allRecords,fn($r)=>!$selectedTerm||ccSameTerm($r,$selectedTerm))));
    $response['teacher_availability']=$pdo->query('SELECT teacher_id,day_of_week,start_time,end_time,availability,academic_year,semester FROM teacher_availability')->fetchAll(PDO::FETCH_ASSOC);
    foreach($response['teachers'] as &$t){
        $t['current_load_units']=0;
        foreach($allRecords as $r)if((int)$r['teacher_id']===(int)$t['id']&&(!$selectedTerm||ccSameTerm($r,$selectedTerm)))$t['current_load_units']+=$r['units'];
    }
    unset($t);

    if ($sectionCode !== '' || $sectionId > 0) {
        $sectionStmt = $pdo->prepare("
            SELECT
                s.id,
                s.code,
                s.name,
                s.program,
                s.year_level,
                s.semester,
                s.academic_year,
                s.max_students,
                s.current_students,
                s.advisor_name,
                s.status
            FROM sections s
            WHERE $sectionWhere
            LIMIT 1
        ");
        $sectionStmt->execute($sectionParams);
        $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);

        if (!$section) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'error' => 'Section not found.',
                'section_code' => $sectionCode
            ]);
            exit;
        }

        $assignmentStmt = $pdo->prepare("
            SELECT
                sub.id,
                sub.code,
                sub.name,
                sub.units,
                sub.subject_type,
                sub.program,
                sub.year_level,
                sub.semester,
                ss.status AS assignment_status
            FROM section_subjects ss
            JOIN subjects sub ON sub.id = ss.subject_id
            WHERE ss.section_id = :section_id
              AND ss.status = 'Assigned'
            ORDER BY sub.code ASC
        ");
        $assignmentStmt->execute(['section_id' => $section['id']]);
        $assignedSubjects = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

        $sectionScheduleStmt = $pdo->prepare("
            SELECT
                se.id,
                se.subject_id,
                sub.code AS subject_code,
                sub.name AS subject_name,
                se.teacher_id,
                t.full_name AS teacher_name,
                se.room_id,
                r.room_code,
                se.day_of_week,
                se.start_time,
                se.end_time,
                se.class_type,
                se.status,
                se.remarks
            FROM schedule_entries se
            JOIN subjects sub ON sub.id = se.subject_id
            LEFT JOIN teachers t ON t.id = se.teacher_id
            LEFT JOIN rooms r ON r.id = se.room_id
            WHERE se.section_id = :section_id
              AND se.status IN ('Draft', 'Validated', 'Published')
            ORDER BY sub.code ASC
        ");
        $sectionScheduleStmt->execute(['section_id' => $section['id']]);
        $sectionSchedules = $sectionScheduleStmt->fetchAll(PDO::FETCH_ASSOC);

        $response['section'] = $section;
        $response['assigned_subjects'] = $assignedSubjects;
        $response['section_schedule'] = $sectionSchedules;
        $st=$pdo->prepare("SELECT * FROM schedule_entries WHERE section_id=? AND status<>'Cancelled'");
        $st->execute([$section['id']]);$revisions=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $entry)$revisions[(int)$entry['id']]=tmRevision($entry);
        foreach($response['section_schedule'] as &$entry)$entry['revision']=$revisions[(int)$entry['id']]??'';
        unset($entry);
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Teacher Schedule Mapping API failed.',
        'details' => $e->getMessage()
    ]);
}
