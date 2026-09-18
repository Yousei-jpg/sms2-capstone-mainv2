<?php
declare(strict_types=1);

/**
 * SMS 2 - Slot Availability API
 *
 * Answers a single question: for one day and time interval, which teachers and
 * rooms are free, and what is occupying the ones that are not.
 *
 * This complements check-availability.php rather than replacing it. That
 * endpoint validates a completed draft; this one guides the user while the
 * draft is still being built, so the dropdowns can show status at the moment of
 * selection.
 *
 * GET ?action=slot&day=Monday&start=07:00&end=08:30
 *     [&exclude_entry=123][&academic_year=2026-2027][&semester=1st Semester]
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

const SLOT_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function slotTime(string $value, string $label): string
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

function slotShort(?string $value): string
{
    $value = trim((string)$value);

    return $value === '' ? '' : substr($value, 0, 5);
}

try {
    $pdo = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $day = trim((string)($_GET['day'] ?? ''));

    if (!in_array($day, SLOT_DAYS, true)) {
        throw new InvalidArgumentException('A valid day is required.');
    }

    $start = slotTime((string)($_GET['start'] ?? ''), 'Start time');
    $end   = slotTime((string)($_GET['end'] ?? ''), 'End time');

    if ($start >= $end) {
        throw new InvalidArgumentException('Start time must be earlier than end time.');
    }

    /*
     * The section being edited is excluded so its own saved rows are not
     * reported as clashing with themselves. Genuine same-section overlaps are
     * still caught by the Automated Conflict Checker before publication.
     */
    $excludeSection = trim((string)($_GET['exclude_section'] ?? ''));
    $excludeEntry = filter_var($_GET['exclude_entry'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $academicYear = trim((string)($_GET['academic_year'] ?? ''));
    $semester = trim((string)($_GET['semester'] ?? ''));

    /*
     * One query returns everything occupying this interval. Overlap uses the
     * same test as the Conflict Checker: start < other_end AND end > other_start,
     * so schedules that merely touch are not treated as clashing.
     */
    $sql = "SELECT se.teacher_id, se.room_id,
                   se.start_time, se.end_time,
                   sec.code AS section_code,
                   sub.code AS subject_code
              FROM schedule_entries se
              JOIN sections sec ON sec.id = se.section_id
              JOIN subjects sub ON sub.id = se.subject_id
             WHERE se.day_of_week = :day
               AND se.status IN ('Draft','Validated','Published')
               AND se.start_time < :end_time
               AND se.end_time   > :start_time";

    $params = ['day' => $day, 'end_time' => $end, 'start_time' => $start];

    if ($excludeSection !== '') {
        $sql .= " AND sec.code <> :exclude_section";
        $params['exclude_section'] = $excludeSection;
    }

    if ($excludeEntry !== false && $excludeEntry !== null) {
        $sql .= " AND se.id <> :exclude_entry";
        $params['exclude_entry'] = $excludeEntry;
    }

    if ($academicYear !== '') {
        $sql .= " AND se.academic_year <=> :academic_year";
        $params['academic_year'] = $academicYear;
    }

    if ($semester !== '') {
        $sql .= " AND se.semester <=> :semester";
        $params['semester'] = $semester;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $busy = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $busyTeachers = [];
    $busyRooms    = [];

    foreach ($busy as $row) {
        $detail = [
            'section' => $row['section_code'],
            'subject' => $row['subject_code'],
            'time'    => slotShort($row['start_time']) . ' - ' . slotShort($row['end_time']),
        ];

        if ($row['teacher_id'] !== null && !isset($busyTeachers[(int)$row['teacher_id']])) {
            $busyTeachers[(int)$row['teacher_id']] = $detail;
        }

        if ($row['room_id'] !== null && !isset($busyRooms[(int)$row['room_id']])) {
            $busyRooms[(int)$row['room_id']] = $detail;
        }
    }

    /* Teachers */
    $teachers = [];
    $teacherFree = 0;

    $rows = $pdo->query(
        "SELECT id, full_name FROM teachers WHERE status = 'Active' ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $conflict = $busyTeachers[$id] ?? null;

        if ($conflict === null) {
            $teacherFree++;
        }

        $teachers[] = [
            'id'        => $id,
            'name'      => $row['full_name'],
            'available' => $conflict === null,
            'busy_with' => $conflict ? ($conflict['section'] . ' · ' . $conflict['subject']) : '',
            'busy_time' => $conflict['time'] ?? '',
        ];
    }

    /* Rooms */
    $roomsOut = [];
    $roomFree = 0;

    $rows = $pdo->query(
        "SELECT id, room_code, capacity FROM rooms WHERE status = 'Available' ORDER BY room_code"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $conflict = $busyRooms[$id] ?? null;

        if ($conflict === null) {
            $roomFree++;
        }

        $roomsOut[] = [
            'id'        => $id,
            'name'      => $row['room_code'],
            'capacity'  => (int)$row['capacity'],
            'available' => $conflict === null,
            'busy_with' => $conflict ? ($conflict['section'] . ' · ' . $conflict['subject']) : '',
            'busy_time' => $conflict['time'] ?? '',
        ];
    }

    echo json_encode([
        'ok'       => true,
        'day'      => $day,
        'start'    => slotShort($start),
        'end'      => slotShort($end),
        'teachers' => $teachers,
        'rooms'    => $roomsOut,
        'summary'  => [
            'teachers_available' => $teacherFree,
            'teachers_occupied'  => count($teachers) - $teacherFree,
            'rooms_available'    => $roomFree,
            'rooms_occupied'     => count($roomsOut) - $roomFree,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    /*
     * PDOException extends RuntimeException, so it is excluded explicitly.
     * Only validation and business-rule messages reach the browser.
     */
    $safeMessage = (!($e instanceof PDOException)
        && ($e instanceof InvalidArgumentException || $e instanceof RuntimeException))
        ? $e->getMessage()
        : 'Availability could not be determined. Please try again.';

    http_response_code(($e instanceof InvalidArgumentException) ? 422 : 500);
    echo json_encode(
        ['ok' => false, 'error' => $safeMessage],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
