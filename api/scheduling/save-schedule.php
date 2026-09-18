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
function parseTimeRange(?string $time): array
{
    $time = trim((string)$time);
    if ($time === '') {
        return [null, null];
    }

    $parts = preg_split('/\s*-\s*/', $time);
    if (!$parts || count($parts) !== 2) {
        throw new InvalidArgumentException("Invalid time range: {$time}");
    }

    $normalize = static function (string $value): string {
        $value = trim($value);
        if (!preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            throw new InvalidArgumentException("Invalid time value: {$value}");
        }

        [$h, $m] = array_map('intval', explode(':', $value));
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new InvalidArgumentException("Invalid time value: {$value}");
        }

        return sprintf('%02d:%02d:00', $h, $m);
    };

    $start = $normalize($parts[0]);
    $end = $normalize($parts[1]);

    if ($start >= $end) {
        throw new InvalidArgumentException('Start time must be earlier than end time.');
    }

    return [$start, $end];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode([
            'ok' => false,
            'error' => 'Method not allowed. Use POST to save schedules.'
        ]);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '', true);

    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Invalid JSON request body.'
        ]);
        exit;
    }

    $sectionCode = trim((string)($payload['section_code'] ?? ''));
    $entries = $payload['entries'] ?? [];

    if ($sectionCode === '' || !is_array($entries)) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'section_code and entries are required.'
        ]);
        exit;
    }

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    // Qualification is data-driven. Deployments without a qualification map
    // keep the rule unconfigured rather than inheriting a made-up policy.
    $qualificationColumns = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM teacher_subject_qualifications')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $qualificationColumns[(string)$column['Field']] = true;
        }
    } catch (Throwable $ignored) {
        $qualificationColumns = [];
    }
    $hasQualificationMap = isset($qualificationColumns['teacher_id'], $qualificationColumns['subject_id']);
    if ($hasQualificationMap) {
        $qualificationCountSql = 'SELECT COUNT(*) FROM teacher_subject_qualifications';
        if (isset($qualificationColumns['status'])) {
            $qualificationCountSql .= " WHERE status = 'Active'";
        }
        $hasQualificationMap = (int)$pdo->query($qualificationCountSql)->fetchColumn() > 0;
    }

    $sectionStmt = $pdo->prepare(
       "SELECT id, academic_year, semester, current_students FROM sections WHERE code = :code AND status = 'Active' LIMIT 1"
    );
    $sectionStmt->execute(['code' => $sectionCode]);
    $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$section) {
        throw new RuntimeException('Section not found or inactive.');
    }

    $sectionId = (int)$section['id'];
    $academicYear = $section['academic_year'] ?? null;
    $semester     = $section['semester'] ?? null;
    $createdBy    = function_exists('getCurrentUserId') ? getCurrentUserId() : null;

    $subjectStmt = $pdo->prepare(
        "SELECT id, units FROM subjects WHERE code = :code AND status = 'Active' LIMIT 1"
    );
    $teacherStmt = $pdo->prepare(
        "SELECT id FROM teachers WHERE full_name = :name AND status = 'Active' LIMIT 1"
    );
    $roomStmt = $pdo->prepare(
        "SELECT id, capacity FROM rooms WHERE room_code = :code AND status = 'Available' LIMIT 1"
    );
    $timeBlockStmt = $pdo->prepare(
        "SELECT id FROM time_blocks
         WHERE start_time = :start_time AND end_time = :end_time
           AND block_type = 'Class' AND is_active = 1
         LIMIT 1"
    );
    $sectionSubjectStmt = $pdo->prepare(
        "SELECT 1 FROM section_subjects
         WHERE section_id = :section_id AND subject_id = :subject_id AND status = 'Assigned'
         LIMIT 1"
    );
    $qualificationStmt = $hasQualificationMap
        ? $pdo->prepare(
            "SELECT 1 FROM teacher_subject_qualifications
             WHERE teacher_id = :teacher_id AND subject_id = :subject_id" .
            (isset($qualificationColumns['status']) ? " AND status = 'Active'" : '') . " LIMIT 1"
        )
        : null;
    /* Final server-side recheck. The browser check is advisory; these queries
       run in the same transaction as the write so stale screens cannot claim
       a teacher or room that was just assigned by another scheduler. */
    $loadCheckStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(sub.units), 0)
         FROM schedule_entries se
         JOIN subjects sub ON sub.id = se.subject_id
         WHERE se.teacher_id = :teacher_id
           AND se.status IN ('Draft', 'Validated', 'Published')
           AND se.id <> :ignore_id
           AND NOT (se.section_id = :section_id AND se.subject_id = :subject_id)
           AND se.academic_year <=> :academic_year
           AND se.semester <=> :semester"
    );
    $slotCheckStmt = $pdo->prepare(
        "SELECT se.id, sec.code AS section_code, sub.code AS subject_code,
                se.teacher_id, se.room_id
         FROM schedule_entries se
         JOIN sections sec ON sec.id = se.section_id
         JOIN subjects sub ON sub.id = se.subject_id
         WHERE se.status IN ('Draft', 'Validated', 'Published')
           AND se.day_of_week = :day
           AND se.start_time < :end_time
           AND se.end_time > :start_time
           AND se.id <> :ignore_id
           AND NOT (se.section_id = :section_id AND se.subject_id = :subject_id)
           AND se.academic_year <=> :academic_year
           AND se.semester <=> :semester"
    );

    // IMPORTANT:
    // Find an existing schedule entry regardless of whether it is Draft,
    // Validated, or Published. This makes editing an existing schedule an
    // UPDATE instead of creating a duplicate INSERT.
    //
    // If the front end sends entry_id, that exact row is preferred.
    $existingByIdStmt = $pdo->prepare(
        "SELECT id, section_id, subject_id, status
         FROM schedule_entries
         WHERE id = :id
         LIMIT 1"
    );

    $existingBySubjectStmt = $pdo->prepare(
        "SELECT id, status
         FROM schedule_entries
         WHERE section_id = :section_id
           AND subject_id = :subject_id
           AND status IN ('Draft', 'Validated')
         ORDER BY
           CASE status
             WHEN 'Draft' THEN 0
             WHEN 'Validated' THEN 1
             WHEN 'Published' THEN 2
             ELSE 3
           END,
           id DESC
         LIMIT 1"
    );

    $updateStmt = $pdo->prepare(
        "UPDATE schedule_entries
         SET teacher_id = :teacher_id,
             room_id = :room_id,
             time_block_id = :time_block_id,
             day_of_week = :day_of_week,
             start_time = :start_time,
             end_time = :end_time,
             class_type = :class_type,
             status = 'Draft',
             remarks = :remarks,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );

    $insertStmt = $pdo->prepare(
        "INSERT INTO schedule_entries
            (section_id, subject_id, teacher_id, room_id, time_block_id, day_of_week,
            start_time, end_time, class_type, status, remarks,
            academic_year, semester, created_by)
         VALUES
            (:section_id, :subject_id, :teacher_id, :room_id, :time_block_id, :day_of_week,
             :start_time, :end_time, :class_type, 'Draft', :remarks,
             :academic_year, :semester, :created_by)"  
    );

    $savedCount = 0;
    $updatedCount = 0;
    $insertedCount = 0;
    $skipped = [];
    $savedIds = [];

    if (count($entries) === 0) {
        throw new InvalidArgumentException('At least one complete schedule entry is required.');
    }

    foreach ($entries as $index => $entry) {
        if (!is_array($entry)) {
            throw new InvalidArgumentException('Schedule row ' . ((int)$index + 1) . ' is invalid.');
        }

        $entryId = isset($entry['id']) && is_numeric($entry['id'])
            ? (int)$entry['id']
            : null;

        $subjectCode = trim((string)($entry['subject_code'] ?? ''));
        $teacherName = trim((string)($entry['teacher'] ?? ''));
        $roomCode = trim((string)($entry['room'] ?? ''));
        $day = trim((string)($entry['day'] ?? ''));
        $classType = trim((string)($entry['class_type'] ?? 'Lecture'));
        $time = trim((string)($entry['time'] ?? ''));

        if ($subjectCode === '' || $teacherName === '' || $roomCode === '' || $day === '' || $time === '') {
            throw new InvalidArgumentException(
                'Schedule row ' . ((int)$index + 1) . ' is incomplete. Teacher, room, day, and time are required.'
            );
        }

        [$startTime, $endTime] = parseTimeRange($time);
        $timeBlockStmt->execute(['start_time' => $startTime, 'end_time' => $endTime]);
        $timeBlockId = $timeBlockStmt->fetchColumn();
        if ($timeBlockId === false) {
            throw new RuntimeException('The selected time is not an active class time block.');
        }

        if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
            throw new InvalidArgumentException("Invalid day: {$day}");
        }

        if (!in_array($classType, ['Lecture', 'Laboratory', 'Online', 'Exam'], true)) {
            throw new InvalidArgumentException("Invalid class type: {$classType}");
        }

        $subjectStmt->execute(['code' => $subjectCode]);
        $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);
        if (!$subject) {
            throw new RuntimeException("Subject {$subjectCode} not found.");
        }
        $subjectId = (int)$subject['id'];

        $sectionSubjectStmt->execute(['section_id' => $sectionId, 'subject_id' => $subjectId]);
        if (!$sectionSubjectStmt->fetchColumn()) {
            throw new RuntimeException("Subject {$subjectCode} is not assigned to section {$sectionCode}.");
        }

        $teacherStmt->execute(['name' => $teacherName]);
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) {
            throw new RuntimeException("Teacher {$teacherName} not found.");
        }
        if ($qualificationStmt instanceof PDOStatement) {
            $qualificationStmt->execute(['teacher_id' => (int)$teacher['id'], 'subject_id' => $subjectId]);
            if (!$qualificationStmt->fetchColumn()) {
                throw new RuntimeException("{$teacherName} is not qualified for subject {$subjectCode}.");
            }
        }

        $roomStmt->execute(['code' => $roomCode]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new RuntimeException("Room {$roomCode} not found or unavailable.");
        }
        if ((int)$room['capacity'] < (int)($section['current_students'] ?? 0)) {
            throw new RuntimeException("Room {$roomCode} does not have enough capacity for this section.");
        }

        $existing = null;

        // Prefer a concrete entry id from the UI when it belongs to this section.
        if ($entryId !== null) {
            $existingByIdStmt->execute(['id' => $entryId]);
            $candidate = $existingByIdStmt->fetch(PDO::FETCH_ASSOC);
            if ($candidate && $candidate['status'] !== 'Published'
                && (int)$candidate['section_id'] === $sectionId
                && (int)$candidate['subject_id'] === $subjectId) {
                $existing = $candidate;
            }
        }

        // Otherwise find the current schedule for this section + subject.
        if (!$existing) {
            $existingBySubjectStmt->execute([
                'section_id' => $sectionId,
                'subject_id' => $subjectId
            ]);
            $existing = $existingBySubjectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $ignoreId = $existing ? (int)$existing['id'] : 0;
        $loadCheckStmt->execute([
            'teacher_id' => (int)$teacher['id'],
            'ignore_id' => $ignoreId,
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'academic_year' => $academicYear,
            'semester' => $semester,
        ]);
        $projectedLoad = (float)$loadCheckStmt->fetchColumn() + (float)$subject['units'];
        // A zero limit means that the institution has not configured a limit.
        // This deliberately does not invent a policy where none exists.
        $teacherLoadStmt = $pdo->prepare('SELECT max_load_units FROM teachers WHERE id = :id');
        $teacherLoadStmt->execute(['id' => (int)$teacher['id']]);
        $maxLoad = (float)$teacherLoadStmt->fetchColumn();
        if ($maxLoad > 0 && $projectedLoad > $maxLoad) {
            throw new RuntimeException("{$teacherName} would exceed the {$maxLoad}-unit teaching-load limit.");
        }

        $slotCheckStmt->execute([
            'day' => $day, 'start_time' => $startTime, 'end_time' => $endTime,
            'ignore_id' => $ignoreId,
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'academic_year' => $academicYear,
            'semester' => $semester,
        ]);
        foreach ($slotCheckStmt->fetchAll(PDO::FETCH_ASSOC) as $occupied) {
            if ((int)$occupied['teacher_id'] === (int)$teacher['id']) {
                throw new RuntimeException("{$teacherName} is no longer available for the selected time.");
            }
            if ((int)$occupied['room_id'] === (int)$room['id']) {
                throw new RuntimeException("Room {$roomCode} is no longer available for the selected time.");
            }
            if ($occupied['section_code'] === $sectionCode) {
                throw new RuntimeException("Section {$sectionCode} already has {$occupied['subject_code']} at the selected time.");
            }
        }

        $params = [
            'teacher_id' => (int)$teacher['id'],
            'room_id' => (int)$room['id'],
            'time_block_id' => (int)$timeBlockId,
            'day_of_week' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'class_type' => $classType,
            'remarks' => 'Saved from Teacher Schedule Mapping.'
        ];

        if ($existing) {
            $params['id'] = (int)$existing['id'];
            $updateStmt->execute($params);
            $updatedCount++;
            $savedIds[] = (int)$existing['id'];
        } else {
           $insertStmt->execute($params + [
                'section_id'    => $sectionId,
                'subject_id'    => $subjectId,
                'academic_year' => $academicYear,
                'semester'      => $semester,
                'created_by'    => $createdBy,
            ]);
            $insertedCount++;
            $savedIds[] = (int)$pdo->lastInsertId();
        }

        $savedCount++;
    }

    $pdo->commit();

    logActivity(
        'Save schedule',
        "Teacher Schedule Mapping saved {$savedCount} schedule entr" . ($savedCount === 1 ? 'y' : 'ies') . " for {$sectionCode}.",
        'scheduling'
    );

    echo json_encode([
        'ok' => true,
        'section_code' => $sectionCode,
        'saved_count' => $savedCount,
        'updated_count' => $updatedCount,
        'inserted_count' => $insertedCount,
        'saved_ids' => $savedIds,
        'skipped' => $skipped,
        'message' => 'Schedule saved successfully without creating duplicate section-subject records.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $safeMessage = (!($e instanceof PDOException)
        && ($e instanceof InvalidArgumentException || $e instanceof RuntimeException))
        ? $e->getMessage()
        : 'Save schedule failed. Please try again or contact the administrator.';

    http_response_code(($e instanceof InvalidArgumentException) ? 422 : 500);
    echo json_encode(
        ['ok' => false, 'error' => $safeMessage],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
