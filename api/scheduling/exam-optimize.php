<?php
declare(strict_types=1);

/**
 * SMS 2 - Examination Optimisation API  (Phase 10, Part B)
 *
 * Bridges the web application and the Google OR-Tools CP-SAT solver.
 *
 * The PHP side gathers the scheduling problem from the database, hands it to a
 * Python process as JSON, and writes the returned assignments back to
 * exam_schedules. No constraint logic lives here — the model is defined in
 * scripts/exam_optimizer.py.
 *
 * GET  ?action=preview&...   -> what would be optimised, without running
 * POST {action:'optimize'}   -> build the problem, solve, and save the result
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

/*
 * The interpreter used to run the solver. Change this if Python is not on the
 * PATH of the account Apache runs under — an absolute path always works, e.g.
 * 'C:\\Users\\You\\AppData\\Local\\Programs\\Python\\Python314\\python.exe'
 */
if (!defined('SMS_PYTHON_BIN')) {
    define('SMS_PYTHON_BIN', 'python');
}

define('SMS_OPTIMIZER_SCRIPT', ROOT_PATH . '/scripts/exam_optimizer.py');

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

const OPT_TYPES = ['Prelim', 'Midterm', 'Semi-Final', 'Final', 'Special'];

function optJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);

    return is_array($decoded) ? $decoded : [];
}

function optShortTime(?string $value): string
{
    $value = trim((string)$value);

    return $value === '' ? '' : substr($value, 0, 5);
}

function optValidDate(string $date, string $label): string
{
    $date = trim($date);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException($label . ' must be in YYYY-MM-DD format.');
    }

    $parts = array_map('intval', explode('-', $date));
    if (!checkdate($parts[1], $parts[2], $parts[0])) {
        throw new InvalidArgumentException($label . ' is not a valid calendar date.');
    }

    return $date;
}

function optNormalizeTime(string $value, string $label): string
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
 * Expands a date range and a set of daily periods into numbered candidate
 * slots. Sundays are excluded; examinations are not held on them.
 */
function optBuildSlots(string $fromDate, string $toDate, array $periods): array
{
    $slots  = [];
    $index  = 0;
    $cursor = strtotime($fromDate);
    $end    = strtotime($toDate);

    if ($cursor > $end) {
        throw new InvalidArgumentException('The start date must not be after the end date.');
    }

    if (($end - $cursor) > (86400 * 60)) {
        throw new InvalidArgumentException('The examination period may not exceed 60 days.');
    }

    while ($cursor <= $end) {
        $weekday = date('l', $cursor);

        if ($weekday !== 'Sunday') {
            foreach ($periods as $period) {
                $slots[] = [
                    'index'   => $index++,
                    'date'    => date('Y-m-d', $cursor),
                    'weekday' => $weekday,
                    'start'   => $period['start'],
                    'end'     => $period['end'],
                ];
            }
        }

        $cursor = strtotime('+1 day', $cursor);
    }

    return $slots;
}

/**
 * Runs the Python optimiser and returns its decoded result.
 * Any failure is reported as a RuntimeException with a readable message.
 */
function optRunSolver(array $problem): array
{
    if (!is_file(SMS_OPTIMIZER_SCRIPT)) {
        throw new RuntimeException(
            'The optimiser script was not found. It should be at scripts/exam_optimizer.py.'
        );
    }

    if (!function_exists('shell_exec')) {
        throw new RuntimeException(
            'PHP is not permitted to run external programs, so the optimiser cannot be started.'
        );
    }

    $inputFile = tempnam(sys_get_temp_dir(), 'sms2opt');

    if ($inputFile === false) {
        throw new RuntimeException('A temporary file could not be created for the optimiser.');
    }

    file_put_contents(
        $inputFile,
        json_encode($problem, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    $command = escapeshellarg(SMS_PYTHON_BIN) . ' ' .
               escapeshellarg(SMS_OPTIMIZER_SCRIPT) . ' ' .
               escapeshellarg($inputFile) . ' 2>&1';

    $raw = shell_exec($command);

    @unlink($inputFile);

    if ($raw === null || trim((string)$raw) === '') {
        throw new RuntimeException(
            'The optimiser produced no output. Confirm that Python is installed and reachable ' .
            'by the web server.'
        );
    }

    $decoded = json_decode(trim((string)$raw), true);

    if (!is_array($decoded) || !isset($decoded['status'])) {
        throw new RuntimeException(
            'The optimiser returned an unreadable result. Check that OR-Tools is installed ' .
            'for the interpreter used by PHP.'
        );
    }

    return $decoded;
}

/* ------------------------------------------------------------------ */
/* Request handling                                                    */
/* ------------------------------------------------------------------ */

try {
    $pdo = getDatabaseConnection();

    /* ---------------------------- GET ---------------------------- */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = trim((string)($_GET['action'] ?? ''));

        if ($action !== 'preview') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
            exit;
        }

        $sectionId = (int)($_GET['section_id'] ?? 0);
        $examType  = trim((string)($_GET['exam_type'] ?? ''));

        $sql = "SELECT COUNT(*) AS pending
                  FROM exam_schedules ex
                 WHERE ex.status = 'Draft'
                   AND ex.exam_date IS NULL";

        $params = [];

        if ($sectionId > 0) {
            $sql .= " AND ex.section_id = :section_id";
            $params['section_id'] = $sectionId;
        }

        if ($examType !== '' && in_array($examType, OPT_TYPES, true)) {
            $sql .= " AND ex.exam_type = :exam_type";
            $params['exam_type'] = $examType;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $rooms = (int)$pdo->query(
            "SELECT COUNT(*) FROM rooms WHERE status = 'Available'"
        )->fetchColumn();

        echo json_encode([
            'ok'      => true,
            'pending' => (int)($row['pending'] ?? 0),
            'rooms'   => $rooms,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---------------------------- POST --------------------------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $payload = optJsonBody();

    if (trim((string)($payload['action'] ?? '')) !== 'optimize') {
        throw new InvalidArgumentException('Unknown action.');
    }

    $sectionId = (int)($payload['section_id'] ?? 0);
    $examType  = trim((string)($payload['exam_type'] ?? ''));
    $fromDate  = optValidDate((string)($payload['from_date'] ?? ''), 'Start date');
    $toDate    = optValidDate((string)($payload['to_date'] ?? ''), 'End date');
    $maxPerDay = (int)($payload['max_per_day'] ?? 2);

    if ($maxPerDay < 1 || $maxPerDay > 6) {
        $maxPerDay = 2;
    }

    /* Daily examination periods. */
    $rawPeriods = $payload['periods'] ?? [];

    if (!is_array($rawPeriods) || count($rawPeriods) === 0) {
        throw new InvalidArgumentException('At least one examination period is required.');
    }

    if (count($rawPeriods) > 6) {
        throw new InvalidArgumentException('At most six examination periods may be defined.');
    }

    $periods = [];
    foreach ($rawPeriods as $period) {
        if (!is_array($period)) {
            continue;
        }

        $start = optNormalizeTime((string)($period['start'] ?? ''), 'Period start time');
        $end   = optNormalizeTime((string)($period['end'] ?? ''), 'Period end time');

        if ($start >= $end) {
            throw new InvalidArgumentException('Each period must start before it ends.');
        }

        $periods[] = ['start' => $start, 'end' => $end];
    }

    if (count($periods) === 0) {
        throw new InvalidArgumentException('At least one valid examination period is required.');
    }

    /* ---- Gather the examinations awaiting a schedule ---- */
    $sql = "SELECT ex.id, ex.section_id, ex.subject_id,
                   sub.code AS subject_code,
                   sec.code AS section_code,
                   sec.current_students,
                   ex.proctor_id
              FROM exam_schedules ex
              JOIN sections sec ON sec.id = ex.section_id
              JOIN subjects sub ON sub.id = ex.subject_id
             WHERE ex.status = 'Draft'
               AND ex.exam_date IS NULL";

    $params = [];

    if ($sectionId > 0) {
        $sql .= " AND ex.section_id = :section_id";
        $params['section_id'] = $sectionId;
    }

    if ($examType !== '' && in_array($examType, OPT_TYPES, true)) {
        $sql .= " AND ex.exam_type = :exam_type";
        $params['exam_type'] = $examType;
    }

    $sql .= " ORDER BY sec.code, sub.code LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($pending) === 0) {
        throw new RuntimeException(
            'There are no examinations awaiting a schedule. Create them first, or widen the filter.'
        );
    }

    /*
     * Where no proctor has been chosen, the teacher who handles that subject
     * for that section is used. It keeps the optimiser's proctor constraint
     * meaningful and matches what a scheduler would do by hand.
     */
    $teacherStmt = $pdo->prepare(
        "SELECT teacher_id
           FROM schedule_entries
          WHERE section_id = :section_id
            AND subject_id = :subject_id
            AND teacher_id IS NOT NULL
          LIMIT 1"
    );

    $exams = [];
    foreach ($pending as $row) {
        $proctorId = $row['proctor_id'] !== null ? (int)$row['proctor_id'] : 0;

        if ($proctorId === 0) {
            $teacherStmt->execute([
                'section_id' => (int)$row['section_id'],
                'subject_id' => (int)$row['subject_id'],
            ]);
            $found = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $proctorId = (int)$found['teacher_id'];
            }
        }

        $exams[] = [
            'id'           => (int)$row['id'],
            'section_id'   => (int)$row['section_id'],
            'subject_code' => $row['subject_code'],
            'section_code' => $row['section_code'],
            'students'     => (int)($row['current_students'] ?? 0),
            'proctor_id'   => $proctorId > 0 ? $proctorId : null,
        ];
    }

    /* ---- Candidate slots ---- */
    $slots = optBuildSlots($fromDate, $toDate, $periods);

    if (count($slots) === 0) {
        throw new RuntimeException(
            'The chosen date range contains no usable days. Examinations are not scheduled on Sundays.'
        );
    }

    /* ---- Rooms ---- */
    $rooms = $pdo->query(
        "SELECT id, room_code AS code, capacity
           FROM rooms
          WHERE status = 'Available'
          ORDER BY capacity DESC, room_code"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (count($rooms) === 0) {
        throw new RuntimeException('No rooms are marked Available, so no examination can be placed.');
    }

    foreach ($rooms as &$room) {
        $room['id']       = (int)$room['id'];
        $room['capacity'] = (int)$room['capacity'];
    }
    unset($room);

    /*
     * ---- Existing commitments ----
     *
     * The optimiser only places the examinations that are still awaiting a
     * schedule. Everything already on the timetable has to be described to it,
     * or it will happily reuse a room or a time that is taken.
     *
     * Three sources are gathered:
     *   - published recurring classes, matched by weekday
     *   - examinations that already have a date
     *   - published special sessions on those dates
     *
     * Each commitment produces either a blocked (exam, slot) pair — when the
     * section or proctor is busy — or an occupied (slot, room) pair.
     */

    /* Recurring classes, keyed by weekday. */
    $classes = $pdo->query(
        "SELECT section_id, teacher_id, room_id, day_of_week, start_time, end_time
           FROM schedule_entries
          WHERE status = 'Published'"
    )->fetchAll(PDO::FETCH_ASSOC);

    /* Examinations already scheduled, and special sessions, keyed by date. */
    $scheduledStmt = $pdo->prepare(
        "SELECT section_id, proctor_id AS teacher_id, room_id,
                exam_date AS on_date, start_time, end_time
           FROM exam_schedules
          WHERE status <> 'Cancelled'
            AND exam_date IS NOT NULL
            AND exam_date BETWEEN :from_date AND :to_date"
    );
    $scheduledStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
    $dated = $scheduledStmt->fetchAll(PDO::FETCH_ASSOC);

    $specialStmt = $pdo->prepare(
        "SELECT section_id, teacher_id, room_id,
                class_date AS on_date, start_time, end_time
           FROM special_classes
          WHERE status <> 'Cancelled'
            AND class_date BETWEEN :from_date AND :to_date"
    );
    $specialStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
    $dated = array_merge($dated, $specialStmt->fetchAll(PDO::FETCH_ASSOC));

    /**
     * Collects the commitments that overlap a given slot, from both the
     * weekday-based and date-based sources.
     */
    $commitmentsForSlot = static function (array $slot) use ($classes, $dated): array {
        $found = [];

        foreach ($classes as $class) {
            if ($class['day_of_week'] !== $slot['weekday']) {
                continue;
            }

            if ($class['start_time'] < $slot['end'] && $class['end_time'] > $slot['start']) {
                $found[] = $class;
            }
        }

        foreach ($dated as $item) {
            if ($item['on_date'] !== $slot['date']) {
                continue;
            }

            if ($item['start_time'] < $slot['end'] && $item['end_time'] > $slot['start']) {
                $found[] = $item;
            }
        }

        return $found;
    };

    $blocked  = [];
    $occupied = [];
    $seenRoom = [];

    foreach ($slots as $slot) {
        $commitments = $commitmentsForSlot($slot);

        if (count($commitments) === 0) {
            continue;
        }

        /* Rooms taken during this slot cannot host an examination. */
        foreach ($commitments as $commitment) {
            if ($commitment['room_id'] === null) {
                continue;
            }

            $key = $slot['index'] . ':' . (int)$commitment['room_id'];

            if (!isset($seenRoom[$key])) {
                $seenRoom[$key] = true;
                $occupied[] = [$slot['index'], (int)$commitment['room_id']];
            }
        }

        /* Sections and proctors already busy cannot take an examination. */
        foreach ($exams as $exam) {
            foreach ($commitments as $commitment) {
                $sameSection = $commitment['section_id'] !== null
                    && (int)$commitment['section_id'] === $exam['section_id'];

                $sameProctor = $exam['proctor_id'] !== null
                    && $commitment['teacher_id'] !== null
                    && (int)$commitment['teacher_id'] === $exam['proctor_id'];

                if ($sameSection || $sameProctor) {
                    $blocked[] = [$exam['id'], $slot['index']];
                    break;
                }
            }
        }
    }

    /* ---- Solve ---- */
    $problem = [
        'exams'                   => $exams,
        'slots'                   => $slots,
        'rooms'                   => $rooms,
        'blocked'                 => $blocked,
        'occupied'                => $occupied,
        'max_per_section_per_day' => $maxPerDay,
        'time_limit_seconds'      => 15,
    ];

    $result = optRunSolver($problem);

    if ($result['status'] === 'ERROR' || $result['status'] === 'INFEASIBLE') {
        throw new RuntimeException((string)($result['message'] ?? 'The optimiser could not find a timetable.'));
    }

    $assignments = $result['assignments'] ?? [];

    if (!is_array($assignments) || count($assignments) === 0) {
        throw new RuntimeException('The optimiser returned no assignments.');
    }

    /* ---- Persist ---- */
    $slotByIndex = [];
    foreach ($slots as $slot) {
        $slotByIndex[$slot['index']] = $slot;
    }

    $proctorByExam = [];
    foreach ($exams as $exam) {
        $proctorByExam[$exam['id']] = $exam['proctor_id'];
    }

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare(
        "UPDATE exam_schedules
            SET exam_date  = :exam_date,
                start_time = :start_time,
                end_time   = :end_time,
                room_id    = :room_id,
                proctor_id = COALESCE(proctor_id, :proctor_id),
                source     = 'Optimizer',
                status     = 'Draft',
                remarks    = :remarks
          WHERE id = :id
            AND status = 'Draft'"
    );

    $applied = 0;

    foreach ($assignments as $assignment) {
        if (!is_array($assignment)) {
            continue;
        }

        $examId    = (int)($assignment['exam_id'] ?? 0);
        $slotIndex = (int)($assignment['slot_index'] ?? -1);
        $roomId    = (int)($assignment['room_id'] ?? 0);

        if ($examId <= 0 || $roomId <= 0 || !isset($slotByIndex[$slotIndex])) {
            continue;
        }

        $slot = $slotByIndex[$slotIndex];

        $updateStmt->execute([
            'exam_date'  => $slot['date'],
            'start_time' => $slot['start'],
            'end_time'   => $slot['end'],
            'room_id'    => $roomId,
            'proctor_id' => $proctorByExam[$examId] ?? null,
            'remarks'    => 'Scheduled by Google OR-Tools (CP-SAT)',
            'id'         => $examId,
        ]);

        $applied += $updateStmt->rowCount();
    }

    $pdo->commit();

    $stats = $result['stats'] ?? [];

    echo json_encode([
        'ok'        => true,
        'status'    => $result['status'],
        'applied'   => $applied,
        'slots'     => count($slots),
        'rooms'     => count($rooms),
        'blocked'   => count($blocked),
        'occupied'  => count($occupied),
        'wall_time' => $stats['wall_time'] ?? null,
        'message'   => $applied . ' examination(s) scheduled by the optimiser (' .
                       strtolower((string)$result['status']) . ' solution, ' .
                       ($stats['wall_time'] ?? '?') . 's). Review and publish them.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        : 'The optimisation could not be completed. Please try again or contact the administrator.';

    http_response_code(($e instanceof InvalidArgumentException) ? 422 : 500);
    echo json_encode(
        ['ok' => false, 'error' => $safeMessage],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
