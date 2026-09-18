<?php
declare(strict_types=1);

/**
 * SMS 2 - Exam Timetable Generator API  (Phase 10, Part A)
 *
 * Creates and schedules examinations. Exams are generated unscheduled from a
 * section's assigned subjects, then given a date, time, room, and proctor —
 * either manually here, or by the optimiser in Part B.
 *
 * Conflict detection compares each exam against three sources:
 *   1. exam_schedules   other exams on the same date
 *   2. schedule_entries recurring classes falling on that weekday
 *   3. special_classes  dated sessions on the same date
 *
 * Conflicts are reported rather than blocked at save time; an exam may not be
 * published while unresolved proctor, room, or section conflicts remain.
 *
 * GET  ?action=options                      -> sections, teachers, rooms, types
 * GET  ?action=list[&filters]               -> exam rows
 * GET  ?action=check&...                    -> conflict preview without saving
 * POST {action:'generate', ...}             -> create unscheduled exams for a section
 * POST {action:'save', ...}                 -> set date/time/room/proctor on one exam
 * POST {action:'update_status', ...}        -> publish or cancel one exam
 * POST {action:'delete', ...}               -> remove one exam
 */

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';

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

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

const EXM_TYPES    = ['Prelim', 'Midterm', 'Semi-Final', 'Final', 'Special'];
const EXM_STATUSES = ['Draft', 'Validated', 'Published', 'Cancelled'];

function exmJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);

    return is_array($decoded) ? $decoded : [];
}

function exmShortTime(?string $value): string
{
    $value = trim((string)$value);

    return $value === '' ? '' : substr($value, 0, 5);
}

function exmDateToWeekday(string $date): string
{
    $date = trim($date);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Exam date must be in YYYY-MM-DD format.');
    }

    $parts = array_map('intval', explode('-', $date));
    if (!checkdate($parts[1], $parts[2], $parts[0])) {
        throw new InvalidArgumentException('Exam date is not a valid calendar date.');
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Exam date could not be interpreted.');
    }

    return date('l', $timestamp);
}

function exmNormalizeTime(string $value, string $label): string
{
    $value = trim($value);

    if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
        throw new InvalidArgumentException($label . ' must be a valid time.');
    }

    $bits = array_map('intval', explode(':', $value));
    if ($bits[0] < 0 || $bits[0] > 23 || $bits[1] < 0 || $bits[1] > 59) {
        throw new InvalidArgumentException($label . ' must be a valid time.');
    }

    return sprintf('%02d:%02d:00', $bits[0], $bits[1]);
}

/**
 * Finds proctor, room, and section clashes for a proposed examination.
 * Returns a list of findings; an empty list means no conflicts.
 */
function exmFindConflicts(
    PDO $pdo,
    string $examDate,
    string $startTime,
    string $endTime,
    ?int $proctorId,
    ?int $roomId,
    ?int $sectionId,
    int $excludeId = 0
): array {
    $findings = [];
    $weekday  = exmDateToWeekday($examDate);

    /* --- 1. Against other examinations on the same date --- */
    $checks = [];
    if ($proctorId !== null) { $checks[] = ['Proctor', 'proctor_id', $proctorId]; }
    if ($roomId    !== null) { $checks[] = ['Room',    'room_id',    $roomId];    }
    if ($sectionId !== null) { $checks[] = ['Section', 'section_id', $sectionId]; }

    foreach ($checks as [$label, $column, $value]) {
        $stmt = $pdo->prepare(
            "SELECT ex.id, ex.exam_type, ex.start_time, ex.end_time,
                    sec.code AS section_code,
                    sub.code AS subject_code
               FROM exam_schedules ex
               JOIN sections sec ON sec.id = ex.section_id
               JOIN subjects sub ON sub.id = ex.subject_id
              WHERE ex.exam_date   = :exam_date
                AND ex.status     <> 'Cancelled'
                AND ex.id         <> :exclude_id
                AND ex.start_time  < :end_time
                AND ex.end_time    > :start_time
                AND ex.{$column}   = :value
              LIMIT 5"
        );
        $stmt->execute([
            'exam_date'  => $examDate,
            'exclude_id' => $excludeId,
            'end_time'   => $endTime,
            'start_time' => $startTime,
            'value'      => $value,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $findings[] = [
                'type'     => $label,
                'severity' => 'Error',
                'against'  => 'Examination',
                'detail'   => $label . ' is already committed to the ' . $row['exam_type'] .
                              ' exam for ' . $row['subject_code'] . ' (' . $row['section_code'] .
                              ') at ' . exmShortTime($row['start_time']) . ' - ' .
                              exmShortTime($row['end_time']) . '.',
                'action'   => 'Move this exam to another slot, or change the ' . strtolower($label) . '.',
            ];
        }
    }

    /* --- 2. Against recurring classes on that weekday --- */
    $classChecks = [];
    if ($proctorId !== null) { $classChecks[] = ['Proctor', 'teacher_id', $proctorId]; }
    if ($roomId    !== null) { $classChecks[] = ['Room',    'room_id',    $roomId];    }
    if ($sectionId !== null) { $classChecks[] = ['Section', 'section_id', $sectionId]; }

    foreach ($classChecks as [$label, $column, $value]) {
        $stmt = $pdo->prepare(
            "SELECT se.id, se.start_time, se.end_time,
                    sec.code AS section_code,
                    sub.code AS subject_code
               FROM schedule_entries se
               JOIN sections sec ON sec.id = se.section_id
               JOIN subjects sub ON sub.id = se.subject_id
              WHERE se.day_of_week = :weekday
                AND se.status IN ('Draft','Validated','Published')
                AND se.start_time  < :end_time
                AND se.end_time    > :start_time
                AND se.{$column}   = :value
              LIMIT 5"
        );
        $stmt->execute([
            'weekday'    => $weekday,
            'end_time'   => $endTime,
            'start_time' => $startTime,
            'value'      => $value,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $findings[] = [
                'type'     => $label,
                'severity' => 'Error',
                'against'  => 'Regular class',
                'detail'   => $label . ' has a regular class — ' . $row['subject_code'] .
                              ' (' . $row['section_code'] . ') on ' . $weekday . ' ' .
                              exmShortTime($row['start_time']) . ' - ' .
                              exmShortTime($row['end_time']) . '.',
                'action'   => 'Choose a slot outside regular class hours, or suspend the class.',
            ];
        }
    }

    /* --- 3. Against special sessions on the same date --- */
    $specialChecks = [];
    if ($proctorId !== null) { $specialChecks[] = ['Proctor', 'teacher_id', $proctorId]; }
    if ($roomId    !== null) { $specialChecks[] = ['Room',    'room_id',    $roomId];    }
    if ($sectionId !== null) { $specialChecks[] = ['Section', 'section_id', $sectionId]; }

    foreach ($specialChecks as [$label, $column, $value]) {
        $stmt = $pdo->prepare(
            "SELECT sc.id, sc.title, sc.start_time, sc.end_time
               FROM special_classes sc
              WHERE sc.class_date  = :exam_date
                AND sc.status     <> 'Cancelled'
                AND sc.start_time  < :end_time
                AND sc.end_time    > :start_time
                AND sc.{$column}   = :value
              LIMIT 5"
        );
        $stmt->execute([
            'exam_date'  => $examDate,
            'end_time'   => $endTime,
            'start_time' => $startTime,
            'value'      => $value,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $findings[] = [
                'type'     => $label,
                'severity' => 'Error',
                'against'  => 'Special class',
                'detail'   => $label . ' is committed to "' . $row['title'] . '" at ' .
                              exmShortTime($row['start_time']) . ' - ' .
                              exmShortTime($row['end_time']) . '.',
                'action'   => 'Move this exam to another slot, or change the ' . strtolower($label) . '.',
            ];
        }
    }

    /* --- 4. Room capacity (advisory) --- */
    if ($roomId !== null && $sectionId !== null) {
        $stmt = $pdo->prepare(
            "SELECT r.room_code, r.capacity, sec.code AS section_code, sec.current_students
               FROM rooms r
               JOIN sections sec ON sec.id = :section_id
              WHERE r.id = :room_id
              LIMIT 1"
        );
        $stmt->execute(['section_id' => $sectionId, 'room_id' => $roomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (int)$row['current_students'] > (int)$row['capacity'] && (int)$row['capacity'] > 0) {
            $findings[] = [
                'type'     => 'Capacity',
                'severity' => 'Warning',
                'against'  => 'Room capacity',
                'detail'   => 'Section ' . $row['section_code'] . ' has ' . $row['current_students'] .
                              ' students but ' . $row['room_code'] . ' seats ' . $row['capacity'] . '.',
                'action'   => 'Assign a larger room, or split the section across two rooms.',
            ];
        }
    }

    return $findings;
}

/* ------------------------------------------------------------------ */
/* Request handling                                                    */
/* ------------------------------------------------------------------ */

try {
    $pdo = getDatabaseConnection();

    /* ---------------------------- GET ---------------------------- */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = trim((string)($_GET['action'] ?? 'options'));

        if ($action === 'options') {
            $sections = $pdo->query(
                "SELECT id, code, name, program, year_level, semester, academic_year, current_students
                   FROM sections WHERE status = 'Active' ORDER BY code"
            )->fetchAll(PDO::FETCH_ASSOC);

            $teachers = $pdo->query(
                "SELECT id, full_name, department
                   FROM teachers WHERE status = 'Active' ORDER BY full_name"
            )->fetchAll(PDO::FETCH_ASSOC);

            $rooms = $pdo->query(
                "SELECT id, room_code, room_type, capacity
                   FROM rooms WHERE status = 'Available' ORDER BY room_code"
            )->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok'       => true,
                'sections' => $sections,
                'teachers' => $teachers,
                'rooms'    => $rooms,
                'types'    => EXM_TYPES,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'check') {
            $examDate  = trim((string)($_GET['exam_date'] ?? ''));
            $startTime = exmNormalizeTime((string)($_GET['start_time'] ?? ''), 'Start time');
            $endTime   = exmNormalizeTime((string)($_GET['end_time'] ?? ''), 'End time');

            if ($startTime >= $endTime) {
                throw new InvalidArgumentException('Start time must be earlier than end time.');
            }

            $findings = exmFindConflicts(
                $pdo,
                $examDate,
                $startTime,
                $endTime,
                (int)($_GET['proctor_id'] ?? 0) ?: null,
                (int)($_GET['room_id'] ?? 0) ?: null,
                (int)($_GET['section_id'] ?? 0) ?: null,
                (int)($_GET['id'] ?? 0)
            );

            echo json_encode([
                'ok'        => true,
                'weekday'   => exmDateToWeekday($examDate),
                'conflicts' => $findings,
                'count'     => count($findings),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'list') {
            $sectionId = (int)($_GET['section_id'] ?? 0);
            $examType  = trim((string)($_GET['exam_type'] ?? ''));
            $status    = trim((string)($_GET['status'] ?? ''));

            $sql = "SELECT ex.id, ex.exam_type, ex.exam_date, ex.start_time, ex.end_time,
                           ex.status, ex.source, ex.remarks,
                           ex.academic_year, ex.semester,
                           sec.id AS section_id, sec.code AS section_code,
                           sec.current_students,
                           sub.id AS subject_id, sub.code AS subject_code, sub.name AS subject_name,
                           sub.units,
                           t.id AS proctor_id, t.full_name AS proctor_name,
                           r.id AS room_id, r.room_code, r.capacity
                      FROM exam_schedules ex
                      JOIN sections sec ON sec.id = ex.section_id
                      JOIN subjects sub ON sub.id = ex.subject_id
                 LEFT JOIN teachers t   ON t.id   = ex.proctor_id
                 LEFT JOIN rooms    r   ON r.id   = ex.room_id
                     WHERE 1 = 1";

            $params = [];

            if ($sectionId > 0) {
                $sql .= " AND ex.section_id = :section_id";
                $params['section_id'] = $sectionId;
            }

            if ($examType !== '' && in_array($examType, EXM_TYPES, true)) {
                $sql .= " AND ex.exam_type = :exam_type";
                $params['exam_type'] = $examType;
            }

            if ($status !== '' && in_array($status, EXM_STATUSES, true)) {
                $sql .= " AND ex.status = :status";
                $params['status'] = $status;
            }

            $sql .= " ORDER BY ex.exam_date IS NULL DESC, ex.exam_date, ex.start_time, sub.code LIMIT 300";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $records = [];
            $unscheduled = 0;

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $isScheduled = !empty($row['exam_date']) && !empty($row['start_time']);
                if (!$isScheduled) {
                    $unscheduled++;
                }

                $records[] = [
                    'id'           => (int)$row['id'],
                    'exam_type'    => $row['exam_type'],
                    'exam_date'    => $row['exam_date'] ?? '',
                    'weekday'      => $row['exam_date'] ? date('l', strtotime($row['exam_date'])) : '',
                    'start_time'   => exmShortTime($row['start_time']),
                    'end_time'     => exmShortTime($row['end_time']),
                    'time'         => $isScheduled
                        ? exmShortTime($row['start_time']) . ' - ' . exmShortTime($row['end_time'])
                        : '',
                    'section_id'   => (int)$row['section_id'],
                    'section_code' => $row['section_code'],
                    'subject_id'   => (int)$row['subject_id'],
                    'subject_code' => $row['subject_code'],
                    'subject_name' => $row['subject_name'],
                    'units'        => $row['units'],
                    'proctor_id'   => $row['proctor_id'] !== null ? (int)$row['proctor_id'] : 0,
                    'proctor_name' => $row['proctor_name'] ?? '',
                    'room_id'      => $row['room_id'] !== null ? (int)$row['room_id'] : 0,
                    'room_code'    => $row['room_code'] ?? '',
                    'status'       => $row['status'],
                    'source'       => $row['source'],
                    'scheduled'    => $isScheduled,
                ];
            }

            echo json_encode([
                'ok'          => true,
                'records'     => $records,
                'count'       => count($records),
                'unscheduled' => $unscheduled,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        exit;
    }

    /* ---------------------------- POST --------------------------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $payload = exmJsonBody();
    $action  = trim((string)($payload['action'] ?? ''));

    /* ---- generate ---- */
    if ($action === 'generate') {
        $sectionId = (int)($payload['section_id'] ?? 0);
        $examType  = trim((string)($payload['exam_type'] ?? 'Final'));

        if ($sectionId <= 0) {
            throw new InvalidArgumentException('Please select a section.');
        }

        if (!in_array($examType, EXM_TYPES, true)) {
            throw new InvalidArgumentException('Invalid examination type.');
        }

        $sectionStmt = $pdo->prepare(
            "SELECT id, code, academic_year, semester
               FROM sections WHERE id = :id AND status = 'Active' LIMIT 1"
        );
        $sectionStmt->execute(['id' => $sectionId]);
        $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);

        if (!$section) {
            throw new RuntimeException('The selected section was not found or is inactive.');
        }

        /* Subjects the section is assigned, from the Section Assignment Tool. */
        $subjectStmt = $pdo->prepare(
            "SELECT sub.id, sub.code
               FROM section_subjects ss
               JOIN subjects sub ON sub.id = ss.subject_id
              WHERE ss.section_id = :section_id
                AND ss.status = 'Assigned'
                AND sub.status = 'Active'
              ORDER BY sub.code"
        );
        $subjectStmt->execute(['section_id' => $sectionId]);
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($subjects) === 0) {
            throw new RuntimeException(
                'Section ' . $section['code'] . ' has no assigned subjects. ' .
                'Use the Section Assignment Tool first.'
            );
        }

        $pdo->beginTransaction();

        $existsStmt = $pdo->prepare(
            "SELECT id FROM exam_schedules
              WHERE section_id = :section_id
                AND subject_id = :subject_id
                AND exam_type  = :exam_type
                AND status    <> 'Cancelled'
              LIMIT 1"
        );

        $insertStmt = $pdo->prepare(
            "INSERT INTO exam_schedules
                (section_id, subject_id, proctor_id, room_id, exam_type,
                 exam_date, start_time, end_time, status, source,
                 academic_year, semester, remarks, created_by)
             VALUES
                (:section_id, :subject_id, NULL, NULL, :exam_type,
                 NULL, NULL, NULL, 'Draft', 'Manual',
                 :academic_year, :semester, :remarks, :created_by)"
        );

        $createdBy = function_exists('getCurrentUserId') ? getCurrentUserId() : null;

        $created = 0;
        $skipped = [];

        foreach ($subjects as $subject) {
            $existsStmt->execute([
                'section_id' => $sectionId,
                'subject_id' => (int)$subject['id'],
                'exam_type'  => $examType,
            ]);

            if ($existsStmt->fetch(PDO::FETCH_ASSOC)) {
                $skipped[] = $subject['code'];
                continue;
            }

            $insertStmt->execute([
                'section_id'    => $sectionId,
                'subject_id'    => (int)$subject['id'],
                'exam_type'     => $examType,
                'academic_year' => $section['academic_year'],
                'semester'      => $section['semester'],
                'remarks'       => 'Awaiting schedule',
                'created_by'    => $createdBy,
            ]);

            $created++;
        }

        $pdo->commit();

        $message = $created . ' examination(s) created for ' . $section['code'] .
                   ' (' . $examType . '), awaiting a date and room.';

        if (count($skipped) > 0) {
            $message .= ' ' . count($skipped) . ' already existed and were left alone.';
        }

        echo json_encode([
            'ok'      => true,
            'created' => $created,
            'skipped' => $skipped,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---- save ---- */
    if ($action === 'save') {
        $id = (int)($payload['id'] ?? 0);

        if ($id <= 0) {
            throw new InvalidArgumentException('A valid examination is required.');
        }

        $stmt = $pdo->prepare(
            "SELECT section_id, status FROM exam_schedules WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            throw new RuntimeException('The examination was not found.');
        }

        if ($existing['status'] === 'Published') {
            throw new RuntimeException(
                'A published examination cannot be edited. Cancel it and create a replacement instead.'
            );
        }

        $examDate  = trim((string)($payload['exam_date'] ?? ''));
        $startTime = exmNormalizeTime((string)($payload['start_time'] ?? ''), 'Start time');
        $endTime   = exmNormalizeTime((string)($payload['end_time'] ?? ''), 'End time');

        exmDateToWeekday($examDate);

        if ($startTime >= $endTime) {
            throw new InvalidArgumentException('Start time must be earlier than end time.');
        }

        $proctorId = (int)($payload['proctor_id'] ?? 0) ?: null;
        $roomId    = (int)($payload['room_id'] ?? 0) ?: null;
        $remarks   = trim((string)($payload['remarks'] ?? ''));

        if (mb_strlen($remarks) > 255) {
            $remarks = mb_substr($remarks, 0, 255);
        }

        $pdo->beginTransaction();

        if ($proctorId !== null) {
            $check = $pdo->prepare("SELECT id FROM teachers WHERE id = :id AND status = 'Active' LIMIT 1");
            $check->execute(['id' => $proctorId]);
            if (!$check->fetch()) {
                throw new RuntimeException('The selected proctor was not found or is inactive.');
            }
        }

        if ($roomId !== null) {
            $check = $pdo->prepare("SELECT id FROM rooms WHERE id = :id AND status = 'Available' LIMIT 1");
            $check->execute(['id' => $roomId]);
            if (!$check->fetch()) {
                throw new RuntimeException('The selected room was not found or is unavailable.');
            }
        }

        $update = $pdo->prepare(
            "UPDATE exam_schedules
                SET exam_date  = :exam_date,
                    start_time = :start_time,
                    end_time   = :end_time,
                    proctor_id = :proctor_id,
                    room_id    = :room_id,
                    remarks    = :remarks,
                    status     = 'Draft'
              WHERE id = :id"
        );
        $update->execute([
            'exam_date'  => $examDate,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'proctor_id' => $proctorId,
            'room_id'    => $roomId,
            'remarks'    => $remarks !== '' ? $remarks : null,
            'id'         => $id,
        ]);

        $conflicts = exmFindConflicts(
            $pdo, $examDate, $startTime, $endTime,
            $proctorId, $roomId, (int)$existing['section_id'], $id
        );

        $pdo->commit();

        $errors = array_filter($conflicts, static fn(array $f): bool => $f['severity'] === 'Error');

        $message = 'Examination scheduled for ' . $examDate . ' ' .
                   exmShortTime($startTime) . ' - ' . exmShortTime($endTime) . '.';

        if (count($errors) > 0) {
            $message .= ' ' . count($errors) . ' conflict(s) detected — resolve before publishing.';
        }

        echo json_encode([
            'ok'        => true,
            'id'        => $id,
            'conflicts' => $conflicts,
            'message'   => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---- update_status ---- */
    if ($action === 'update_status') {
        $id     = (int)($payload['id'] ?? 0);
        $status = trim((string)($payload['status'] ?? ''));

        if ($id <= 0) {
            throw new InvalidArgumentException('A valid examination is required.');
        }

        if (!in_array($status, EXM_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status value.');
        }

        $stmt = $pdo->prepare(
            "SELECT section_id, proctor_id, room_id, exam_date, start_time, end_time
               FROM exam_schedules WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            throw new RuntimeException('The examination was not found.');
        }

        if (in_array($status, ['Validated', 'Published'], true)) {
            if (empty($record['exam_date']) || empty($record['start_time'])) {
                throw new RuntimeException(
                    'This examination has no date or time yet. Schedule it before marking it ' . $status . '.'
                );
            }

            $conflicts = exmFindConflicts(
                $pdo,
                $record['exam_date'],
                $record['start_time'],
                $record['end_time'],
                $record['proctor_id'] !== null ? (int)$record['proctor_id'] : null,
                $record['room_id'] !== null ? (int)$record['room_id'] : null,
                (int)$record['section_id'],
                $id
            );

            $errors = array_filter($conflicts, static fn(array $f): bool => $f['severity'] === 'Error');

            if (count($errors) > 0) {
                throw new RuntimeException(
                    'This examination cannot be marked ' . $status . ' while ' . count($errors) .
                    ' conflict(s) remain. Resolve the conflicts first.'
                );
            }
        }

        $update = $pdo->prepare("UPDATE exam_schedules SET status = :status WHERE id = :id");
        $update->execute(['status' => $status, 'id' => $id]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Examination marked ' . $status . '.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---- delete ---- */
    if ($action === 'delete') {
        $id = (int)($payload['id'] ?? 0);

        if ($id <= 0) {
            throw new InvalidArgumentException('A valid examination is required.');
        }

        $stmt = $pdo->prepare("SELECT status FROM exam_schedules WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            throw new RuntimeException('The examination was not found.');
        }

        if ($record['status'] === 'Published') {
            throw new RuntimeException(
                'A published examination cannot be deleted. Cancel it instead so the record is retained.'
            );
        }

        $delete = $pdo->prepare("DELETE FROM exam_schedules WHERE id = :id");
        $delete->execute(['id' => $id]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Examination removed.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new InvalidArgumentException('Unknown action.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * PDOException extends RuntimeException, so it is excluded explicitly.
     * Only validation and business-rule messages reach the browser.
     */
    $safeMessage = (!($e instanceof PDOException)
        && ($e instanceof InvalidArgumentException || $e instanceof RuntimeException))
        ? $e->getMessage()
        : 'The examination timetable could not be updated. Please try again or contact the administrator.';

    http_response_code(($e instanceof InvalidArgumentException) ? 422 : 500);
    echo json_encode(
        ['ok' => false, 'error' => $safeMessage],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
