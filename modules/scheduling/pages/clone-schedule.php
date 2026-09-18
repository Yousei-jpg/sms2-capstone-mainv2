<?php
declare(strict_types=1);

/**
 * SMS 2 - Schedule Cloning Tool API  (Phase 6)
 *
 * Copies the schedule of one section into another section.
 *
 * Cloned rows are always inserted as Draft. Publishing stays a separate,
 * deliberate action handled by publish-schedule.php, and conflict detection
 * stays in conflict-check.php. This file does not re-implement either.
 *
 * GET  ?action=options                              -> sections, teachers, rooms
 * GET  ?action=preview&source_code=..&target_code=.. -> rows to copy + target state
 * POST {action:'clone', ...}                         -> perform the copy
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

function scJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);

    return is_array($decoded) ? $decoded : [];
}

/** "07:30" -> "07:30:00", validating the range. Mirrors save-schedule.php. */
function scParseTimeRange(?string $time): array
{
    $time = trim((string)$time);
    if ($time === '') {
        throw new InvalidArgumentException('Time range is required.');
    }

    $parts = preg_split('/\s*-\s*/', $time);
    if (!$parts || count($parts) !== 2) {
        throw new InvalidArgumentException("Invalid time range: {$time}");
    }

    $normalize = static function (string $value): string {
        $value = trim($value);
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
            throw new InvalidArgumentException("Invalid time value: {$value}");
        }

        $bits = array_map('intval', explode(':', $value));
        $h = $bits[0];
        $m = $bits[1];

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new InvalidArgumentException("Invalid time value: {$value}");
        }

        return sprintf('%02d:%02d:00', $h, $m);
    };

    $start = $normalize($parts[0]);
    $end   = $normalize($parts[1]);

    if ($start >= $end) {
        throw new InvalidArgumentException('Start time must be earlier than end time.');
    }

    return [$start, $end];
}

function scShortTime(?string $value): string
{
    $value = trim((string)$value);

    return $value === '' ? '' : substr($value, 0, 5);
}

/** Looks up an Active section by code, or throws. */
function scFetchSection(PDO $pdo, string $code, string $label): array
{
    $stmt = $pdo->prepare(
        "SELECT id, code, name, program, year_level, semester, academic_year
           FROM sections
          WHERE code = :code AND status = 'Active'
          LIMIT 1"
    );
    $stmt->execute(['code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException("{$label} section {$code} was not found or is inactive.");
    }

    return $row;
}

/** Counts existing entries in a section, grouped by status. */
function scTargetState(PDO $pdo, int $sectionId): array
{
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS total
           FROM schedule_entries
          WHERE section_id = :section_id
          GROUP BY status"
    );
    $stmt->execute(['section_id' => $sectionId]);

    $state = ['Draft' => 0, 'Validated' => 0, 'Published' => 0, 'total' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)$row['status'];
        $count  = (int)$row['total'];
        if (array_key_exists($status, $state)) {
            $state[$status] = $count;
        }
        $state['total'] += $count;
    }

    return $state;
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
                "SELECT id, code, name, program, year_level, semester, academic_year
                   FROM sections
                  WHERE status = 'Active'
                  ORDER BY academic_year DESC, semester, code"
            )->fetchAll(PDO::FETCH_ASSOC);

            $teachers = $pdo->query(
                "SELECT id, full_name, department
                   FROM teachers
                  WHERE status = 'Active'
                  ORDER BY full_name"
            )->fetchAll(PDO::FETCH_ASSOC);

            $rooms = $pdo->query(
                "SELECT id, room_code, room_type, capacity
                   FROM rooms
                  WHERE status = 'Available'
                  ORDER BY room_code"
            )->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok'       => true,
                'sections' => $sections,
                'teachers' => $teachers,
                'rooms'    => $rooms,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'preview') {
            $sourceCode = trim((string)($_GET['source_code'] ?? ''));
            $targetCode = trim((string)($_GET['target_code'] ?? ''));

            if ($sourceCode === '') {
                throw new InvalidArgumentException('Please select a source section.');
            }

            $source = scFetchSection($pdo, $sourceCode, 'Source');

            $rowsStmt = $pdo->prepare(
                "SELECT se.id,
                        sub.code  AS subject_code,
                        sub.name  AS subject_name,
                        sub.units AS units,
                        t.full_name AS teacher,
                        r.room_code AS room,
                        se.day_of_week,
                        se.start_time,
                        se.end_time,
                        se.class_type,
                        se.status
                   FROM schedule_entries se
                   JOIN subjects sub ON sub.id = se.subject_id
              LEFT JOIN teachers t   ON t.id  = se.teacher_id
              LEFT JOIN rooms    r   ON r.id  = se.room_id
                  WHERE se.section_id = :section_id
               ORDER BY FIELD(se.day_of_week,'Monday','Tuesday','Wednesday',
                              'Thursday','Friday','Saturday'),
                        se.start_time"
            );
            $rowsStmt->execute(['section_id' => (int)$source['id']]);
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            $entries = [];
            foreach ($rows as $row) {
                $entries[] = [
                    'subject_code' => $row['subject_code'],
                    'subject_name' => $row['subject_name'],
                    'units'        => $row['units'],
                    'teacher'      => $row['teacher'] ?? '',
                    'room'         => $row['room'] ?? '',
                    'day'          => $row['day_of_week'],
                    'time'         => scShortTime($row['start_time']) . ' - ' . scShortTime($row['end_time']),
                    'class_type'   => $row['class_type'],
                    'status'       => $row['status'],
                ];
            }

            $target      = null;
            $targetState = null;
            if ($targetCode !== '') {
                $target      = scFetchSection($pdo, $targetCode, 'Target');
                $targetState = scTargetState($pdo, (int)$target['id']);
            }

            echo json_encode([
                'ok'           => true,
                'source'       => $source,
                'target'       => $target,
                'target_state' => $targetState,
                'entries'      => $entries,
                'count'        => count($entries),
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

    $payload = scJsonBody();
    $action  = trim((string)($payload['action'] ?? ''));

    if ($action !== 'clone') {
        throw new InvalidArgumentException('Unknown action. Use clone.');
    }

    $sourceCode = trim((string)($payload['source_code'] ?? ''));
    $targetCode = trim((string)($payload['target_code'] ?? ''));
    $onExisting = trim((string)($payload['on_existing'] ?? 'block'));
    $entries    = $payload['entries'] ?? [];

    if ($sourceCode === '' || $targetCode === '') {
        throw new InvalidArgumentException('Source and target sections are required.');
    }

    if ($sourceCode === $targetCode) {
        throw new InvalidArgumentException('Source and target sections must be different.');
    }

    if (!is_array($entries) || count($entries) === 0) {
        throw new InvalidArgumentException('There is nothing to clone.');
    }

    if (!in_array($onExisting, ['block', 'replace'], true)) {
        $onExisting = 'block';
    }

    $pdo->beginTransaction();

    $source = scFetchSection($pdo, $sourceCode, 'Source');
    $target = scFetchSection($pdo, $targetCode, 'Target');

    $targetId    = (int)$target['id'];
    $targetState = scTargetState($pdo, $targetId);

    /*
     * Published rows are never touched by cloning. A published timetable is
     * already in circulation, so overwriting it silently would be unsafe.
     */
    if ($targetState['Published'] > 0) {
        throw new RuntimeException(
            'Target section ' . $targetCode . ' has ' . $targetState['Published'] .
            ' published schedule entry(ies). Unpublish them first before cloning into this section.'
        );
    }

    if ($targetState['total'] > 0 && $onExisting === 'block') {
        throw new RuntimeException(
            'Target section ' . $targetCode . ' already has ' . $targetState['total'] .
            ' schedule entry(ies). Choose "Replace existing draft entries" to overwrite them.'
        );
    }

    $removed = 0;
    if ($targetState['total'] > 0 && $onExisting === 'replace') {
        $deleteStmt = $pdo->prepare(
            "DELETE FROM schedule_entries
              WHERE section_id = :section_id
                AND status IN ('Draft','Validated')"
        );
        $deleteStmt->execute(['section_id' => $targetId]);
        $removed = $deleteStmt->rowCount();
    }

    $subjectStmt = $pdo->prepare(
        "SELECT id FROM subjects WHERE code = :code AND status = 'Active' LIMIT 1"
    );
    $teacherStmt = $pdo->prepare(
        "SELECT id FROM teachers WHERE full_name = :name AND status = 'Active' LIMIT 1"
    );
    $roomStmt = $pdo->prepare(
        "SELECT id FROM rooms WHERE room_code = :code AND status = 'Available' LIMIT 1"
    );

    /*
     * A cloned section also needs its subject assignments. Teacher Schedule
     * Mapping builds its draft from section_subjects, so a section holding
     * schedule_entries alone would appear to have no subjects at all.
     */
    $assignStmt = $pdo->prepare(
        "INSERT INTO section_subjects (section_id, subject_id, status, remarks, created_by)
         VALUES (:section_id, :subject_id, 'Assigned', :remarks, :created_by)
         ON DUPLICATE KEY UPDATE id = id"
    );

    $insertStmt = $pdo->prepare(
        "INSERT INTO schedule_entries
            (section_id, subject_id, teacher_id, room_id, day_of_week,
             start_time, end_time, class_type, status, remarks,
             academic_year, semester, created_by)
         VALUES
            (:section_id, :subject_id, :teacher_id, :room_id, :day_of_week,
             :start_time, :end_time, :class_type, 'Draft', :remarks,
             :academic_year, :semester, :created_by)"
    );

    $createdBy = function_exists('getCurrentUserId') ? getCurrentUserId() : null;
    $remarks   = 'Cloned from ' . $sourceCode . '.';

    $validDays  = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $validTypes = ['Lecture', 'Laboratory', 'Online', 'Exam'];

    $insertedCount = 0;
    $insertedIds   = [];
    $skipped       = [];
    $seen          = [];

    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            $skipped[] = ['index' => $index, 'reason' => 'Invalid row.'];
            continue;
        }

        $subjectCode = trim((string)($entry['subject_code'] ?? ''));
        $teacherName = trim((string)($entry['teacher'] ?? ''));
        $roomCode    = trim((string)($entry['room'] ?? ''));
        $day         = trim((string)($entry['day'] ?? ''));
        $time        = trim((string)($entry['time'] ?? ''));
        $classType   = trim((string)($entry['class_type'] ?? 'Lecture'));

        if ($subjectCode === '' || $teacherName === '' || $roomCode === '' || $day === '' || $time === '') {
            $skipped[] = [
                'index'        => $index,
                'subject_code' => $subjectCode,
                'reason'       => 'Incomplete row — subject, teacher, room, day and time are all required.',
            ];
            continue;
        }

        /* One subject may only appear once per target section. */
        if (isset($seen[$subjectCode])) {
            $skipped[] = [
                'index'        => $index,
                'subject_code' => $subjectCode,
                'reason'       => 'Duplicate subject in the cloned set.',
            ];
            continue;
        }

        if (!in_array($day, $validDays, true)) {
            throw new InvalidArgumentException("Invalid day: {$day}");
        }

        if (!in_array($classType, $validTypes, true)) {
            $classType = 'Lecture';
        }

        [$startTime, $endTime] = scParseTimeRange($time);

        $subjectStmt->execute(['code' => $subjectCode]);
        $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$subject) {
            throw new RuntimeException("Subject {$subjectCode} was not found or is inactive.");
        }

        $teacherStmt->execute(['name' => $teacherName]);
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) {
            throw new RuntimeException("Teacher {$teacherName} was not found or is inactive.");
        }

        $roomStmt->execute(['code' => $roomCode]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new RuntimeException("Room {$roomCode} was not found or is unavailable.");
        }

        $insertStmt->execute([
            'section_id'    => $targetId,
            'subject_id'    => (int)$subject['id'],
            'teacher_id'    => (int)$teacher['id'],
            'room_id'       => (int)$room['id'],
            'day_of_week'   => $day,
            'start_time'    => $startTime,
            'end_time'      => $endTime,
            'class_type'    => $classType,
            'remarks'       => $remarks,
            'academic_year' => $target['academic_year'],
            'semester'      => $target['semester'],
            'created_by'    => $createdBy,
        ]);

        $assignStmt->execute([
            'section_id' => $targetId,
            'subject_id' => (int)$subject['id'],
            'remarks'    => 'Added by schedule cloning from ' . $sourceCode . '.',
            'created_by' => $createdBy,
        ]);

        $seen[$subjectCode] = true;
        $insertedIds[] = (int)$pdo->lastInsertId();
        $insertedCount++;
    }

    if ($insertedCount === 0) {
        throw new RuntimeException('No rows could be cloned. Review the skipped rows and try again.');
    }

    $pdo->commit();

    echo json_encode([
        'ok'             => true,
        'source_code'    => $sourceCode,
        'target_code'    => $targetCode,
        'inserted_count' => $insertedCount,
        'removed_count'  => $removed,
        'inserted_ids'   => $insertedIds,
        'skipped'        => $skipped,
        'message'        => $insertedCount . ' entry(ies) cloned into ' . $targetCode .
                            ' as Draft. Run the Conflict Checker before publishing.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    /*
     * Validation and business-rule messages are safe to show. Anything else
     * (PDO errors in particular) is replaced with a generic message so that
     * SQL details never reach the browser.
     */
    $safeMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)
        ? $e->getMessage()
        : 'Schedule cloning failed. Please try again or contact the administrator.';

    http_response_code(($e instanceof InvalidArgumentException) ? 422 : 500);
    echo json_encode(
        ['ok' => false, 'error' => $safeMessage],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
