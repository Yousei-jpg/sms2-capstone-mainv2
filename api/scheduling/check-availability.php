<?php
declare(strict_types=1);

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST for availability checks.'
    ]);
    exit;
}

function normalizeTime(?string $value): ?array
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    // Accept either "08:00 - 10:00" or a single time value.
    if (preg_match('/^\s*(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})\s*$/', $value, $m)) {
        return [$m[1], $m[2]];
    }

    return [$value, null];
}

function normalizeRow(array $row, int $index): array
{
    $time = normalizeTime(isset($row['time']) ? (string)$row['time'] : null);

    $startTime = $time[0] ?? null;
    $endTime = $time[1] ?? null;

    if ($startTime !== null && preg_match('/^\d{1,2}:\d{2}$/', $startTime)) {
        if (strlen($startTime) === 4) {
            $startTime = '0' . $startTime;
        }
        $startTime .= ':00';
    }

    if ($endTime !== null && preg_match('/^\d{1,2}:\d{2}$/', $endTime)) {
        if (strlen($endTime) === 4) {
            $endTime = '0' . $endTime;
        }
        $endTime .= ':00';
    }

    return [
        'index' => $index,
        'subject_code' => trim((string)($row['subject_code'] ?? $row['subject'] ?? '')),
        'teacher' => trim((string)($row['teacher'] ?? '')),
        'room' => trim((string)($row['room'] ?? '')),
        'day' => trim((string)($row['day'] ?? '')),
        'start_time' => $startTime,
        'end_time' => $endTime,
        'class_type' => trim((string)($row['class_type'] ?? $row['type'] ?? 'Lecture')),
        'existing_id' => isset($row['existing_id']) && is_numeric($row['existing_id'])
            ? (int)$row['existing_id']
            : null,
    ];
}

function overlaps(string $startA, string $endA, string $startB, string $endB): bool
{
    return $startA < $endB && $endA > $startB;
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);

    if (!is_array($payload)) {
        throw new RuntimeException('Request body must contain valid JSON.');
    }

    $sectionCode = trim((string)($payload['section_code'] ?? ''));
    $draftRows = $payload['entries'] ?? [];

    if ($sectionCode === '') {
        throw new RuntimeException('section_code is required.');
    }

    if (!is_array($draftRows)) {
        throw new RuntimeException('entries must be an array.');
    }

    $pdo = getDatabaseConnection();

    $sectionStmt = $pdo->prepare(
        "SELECT id, code, name, max_students, current_students, academic_year, semester
         FROM sections
         WHERE code = :code
         LIMIT 1"
    );
    $sectionStmt->execute(['code' => $sectionCode]);
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

    $rows = [];
    foreach ($draftRows as $index => $draftRow) {
        if (!is_array($draftRow)) {
            continue;
        }
        $rows[] = normalizeRow($draftRow, (int)$index);
    }

    // Lookup valid teachers, rooms, and subjects once.
    $teacherStmt = $pdo->query(
        "SELECT id, full_name, max_load_units
         FROM teachers
         WHERE status = 'Active'"
    );
    $teacherMap = [];
    foreach ($teacherStmt->fetchAll(PDO::FETCH_ASSOC) as $teacher) {
        $teacherMap[mb_strtolower(trim($teacher['full_name']))] = [
            'id' => (int)$teacher['id'],
            'name' => $teacher['full_name'],
            'max_load' => (float)$teacher['max_load_units']
        ];
    }

    $roomStmt = $pdo->query(
        "SELECT id, room_code, capacity, status
         FROM rooms"
    );
    $roomMap = [];
    foreach ($roomStmt->fetchAll(PDO::FETCH_ASSOC) as $room) {
        $roomMap[mb_strtolower(trim($room['room_code']))] = [
            'id' => (int)$room['id'],
            'code' => $room['room_code'],
            'capacity' => (int)$room['capacity'],
            'status' => $room['status']
        ];
    }

    $subjectStmt = $pdo->query(
        "SELECT id, code, units
         FROM subjects
         WHERE status = 'Active'"
    );
    $subjectMap = [];
    foreach ($subjectStmt->fetchAll(PDO::FETCH_ASSOC) as $subject) {
        $subjectMap[mb_strtolower(trim($subject['code']))] = [
            'id' => (int)$subject['id'],
            'code' => $subject['code'],
            'units' => (float)$subject['units']
        ];
    }

    // Subjects arrive from Section Assignment and remain read-only here.
    $assignedSubjectStmt = $pdo->prepare(
        "SELECT subject_id FROM section_subjects
         WHERE section_id = :section_id AND status = 'Assigned'"
    );
    $assignedSubjectStmt->execute(['section_id' => (int)$section['id']]);
    $assignedSubjectIds = array_fill_keys(
        array_map('intval', array_column($assignedSubjectStmt->fetchAll(PDO::FETCH_ASSOC), 'subject_id')),
        true
    );

    // Qualification rules are optional configuration. If the installation has
    // a mapping table, enforce it; otherwise do not infer qualifications.
    $qualificationMap = null;
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM teacher_subject_qualifications')->fetchAll(PDO::FETCH_ASSOC);
        $fields = array_fill_keys(array_column($columns, 'Field'), true);
        if (isset($fields['teacher_id'], $fields['subject_id'])) {
            $sql = 'SELECT teacher_id, subject_id FROM teacher_subject_qualifications';
            if (isset($fields['status'])) {
                $sql .= " WHERE status = 'Active'";
            }
            $qualificationMap = [];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $qualification) {
                $qualificationMap[(int)$qualification['teacher_id'] . ':' . (int)$qualification['subject_id']] = true;
            }
            if (!$qualificationMap) {
                $qualificationMap = null;
            }
        }
    } catch (Throwable $ignored) {
        $qualificationMap = null;
    }

    // Existing teaching load from OTHER sections (this section is being replaced).
    $loadStmt = $pdo->prepare(
        "SELECT se.teacher_id, COALESCE(SUM(sub.units), 0) AS load_units
         FROM schedule_entries se
         JOIN subjects sub ON sub.id = se.subject_id
         WHERE se.teacher_id IS NOT NULL
           AND se.section_id <> :section_id
           AND se.status IN ('Draft', 'Validated', 'Published')
           AND se.academic_year <=> :academic_year
           AND se.semester <=> :semester
         GROUP BY se.teacher_id"
    );
    $loadStmt->execute([
        'section_id' => (int)$section['id'],
        'academic_year' => $section['academic_year'],
        'semester' => $section['semester'],
    ]);

    $existingLoad = [];
    foreach ($loadStmt->fetchAll(PDO::FETCH_ASSOC) as $loadRow) {
        $existingLoad[(int)$loadRow['teacher_id']] = (float)$loadRow['load_units'];
    }
    $draftLoad = [];
    $results = [];
    $conflictCount = 0;
    $availableCount = 0;
    $pendingCount = 0;

    // Persisted schedules used for conflict detection.
    // The current section+subject is excluded because its saved row represents
    // the schedule being edited, not a conflict with another schedule.
    $existingScheduleStmt = $pdo->prepare(
        "SELECT
            se.id,
            se.section_id,
            se.subject_id,
            s.code AS section_code,
            sub.code AS subject_code,
            se.teacher_id,
            t.full_name AS teacher_name,
            se.room_id,
            r.room_code,
            se.day_of_week,
            se.start_time,
            se.end_time,
            se.class_type,
            se.status
         FROM schedule_entries se
         JOIN sections s ON s.id = se.section_id
         JOIN subjects sub ON sub.id = se.subject_id
         LEFT JOIN teachers t ON t.id = se.teacher_id
         LEFT JOIN rooms r ON r.id = se.room_id
         WHERE se.status IN ('Draft','Validated','Published')
           AND se.day_of_week IS NOT NULL
           AND se.start_time IS NOT NULL
           AND se.end_time IS NOT NULL
           AND se.academic_year <=> :academic_year
           AND se.semester <=> :semester
           AND se.id <> COALESCE(:ignore_id, 0)
           AND NOT (se.section_id = :current_section_id AND se.subject_id = :current_subject_id)"
    );

    foreach ($rows as $row) {
        $itemConflicts = [];

        if ($row['subject_code'] === '' || $row['teacher'] === '' || $row['room'] === '' || $row['day'] === '' || !$row['start_time'] || !$row['end_time']) {
            $pendingCount++;
            $results[] = [
                'index' => $row['index'],
                'subject_code' => $row['subject_code'],
                'result' => 'Pending',
                'conflicts' => [],
                'message' => 'Teacher, room, day, and time are required.'
            ];
            continue;
        }

        $subject = $subjectMap[mb_strtolower($row['subject_code'])] ?? null;
        $teacher = $teacherMap[mb_strtolower($row['teacher'])] ?? null;
        $room = $roomMap[mb_strtolower($row['room'])] ?? null;

        if (!$subject) {
            $itemConflicts[] = [
                'type' => 'Availability',
                'message' => 'Subject does not exist or is inactive.'
            ];
        }
        elseif (!isset($assignedSubjectIds[$subject['id']])) {
            $itemConflicts[] = [
                'type' => 'Section',
                'message' => $subject['code'] . ' is not assigned to section ' . $section['code'] . '.'
            ];
        }

        if (!$teacher) {
            $itemConflicts[] = [
                'type' => 'Teacher',
                'message' => 'Selected teacher was not found or is inactive.'
            ];
        }
        elseif (is_array($qualificationMap) && $subject
            && !isset($qualificationMap[$teacher['id'] . ':' . $subject['id']])) {
            $itemConflicts[] = [
                'type' => 'Teacher',
                'message' => $teacher['name'] . ' is not qualified for ' . $subject['code'] . '.'
            ];
        }

        if (!$room) {
            $itemConflicts[] = [
                'type' => 'Room',
                'message' => 'Selected room was not found.'
            ];
        } elseif ($room['status'] !== 'Available') {
            $itemConflicts[] = [
                'type' => 'Availability',
                'message' => $room['code'] . ' is currently ' . $room['status'] . '.'
            ];
        } elseif ($room['capacity'] < (int)$section['current_students']) {
            $itemConflicts[] = [
                'type' => 'Room',
                'message' => $room['code'] . ' has capacity for ' . $room['capacity'] .
                    ', below the section enrollment of ' . (int)$section['current_students'] . '.'
            ];
        }

        // Validate time ordering server-side.
        // Teaching load check against max_load_units.
        if ($teacher && $subject) {
            $tid = $teacher['id'];
            $draftLoad[$tid] = ($draftLoad[$tid] ?? 0) + $subject['units'];
            $totalLoad = ($existingLoad[$tid] ?? 0) + $draftLoad[$tid];

            if ($teacher['max_load'] > 0 && $totalLoad > $teacher['max_load']) {
                $itemConflicts[] = [
                    'type' => 'Availability',
                    'message' => $teacher['name'] . ' would carry ' . $totalLoad .
                        ' units, over the ' . $teacher['max_load'] . '-unit limit.'
                ];
            }
        }
        if ($row['start_time'] >= $row['end_time']) {
            $itemConflicts[] = [
                'type' => 'Availability',
                'message' => 'Start time must be earlier than end time.'
            ];
        }

        // Check against persisted schedule entries.
        if (!$itemConflicts) {
            $existingScheduleStmt->execute([
                'ignore_id' => $row['existing_id'],
                'current_section_id' => (int)$section['id'],
                'current_subject_id' => $subject ? $subject['id'] : 0,
                'academic_year' => $section['academic_year'],
                'semester' => $section['semester'],
            ]);
            $existingRows = $existingScheduleStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($existingRows as $existing) {
                if ((string)$existing['day_of_week'] !== $row['day']) {
                    continue;
                }

                if (!overlaps(
                    $row['start_time'],
                    $row['end_time'],
                    (string)$existing['start_time'],
                    (string)$existing['end_time']
                )) {
                    continue;
                }

                // Teacher conflict.
                if ($teacher && (int)$existing['teacher_id'] === $teacher['id']) {
                    $itemConflicts[] = [
                        'type' => 'Teacher',
                        'message' => $teacher['name'] . ' is already assigned to ' .
                            $existing['section_code'] . ' (' . $existing['subject_code'] . ') on ' .
                            $row['day'] . ' ' . substr((string)$existing['start_time'], 0, 5) . '-' .
                            substr((string)$existing['end_time'], 0, 5) . '.'
                    ];
                }

                // Room conflict.
                if ($room && (int)$existing['room_id'] === $room['id']) {
                    $itemConflicts[] = [
                        'type' => 'Room',
                        'message' => $room['code'] . ' is already occupied by ' .
                            $existing['section_code'] . ' (' . $existing['subject_code'] . ') on ' .
                            $row['day'] . ' ' . substr((string)$existing['start_time'], 0, 5) . '-' .
                            substr((string)$existing['end_time'], 0, 5) . '.'
                    ];
                }

                // Section conflict.
                if ((int)$existing['section_id'] === (int)$section['id']) {
                    $itemConflicts[] = [
                        'type' => 'Section',
                        'message' => $section['code'] . ' already has ' .
                            $existing['subject_code'] . ' scheduled during this time.'
                    ];
                }
            }
        }

        // Check conflicts between rows in the current draft itself.
        foreach ($rows as $other) {
            if ($other['index'] >= $row['index']) {
                continue;
            }

            if ($other['day'] === '' || !$other['start_time'] || !$other['end_time']) {
                continue;
            }

            if ($other['day'] !== $row['day']) {
                continue;
            }

            if (!overlaps(
                $row['start_time'],
                $row['end_time'],
                $other['start_time'],
                $other['end_time']
            )) {
                continue;
            }

            if ($row['teacher'] !== '' && strcasecmp($row['teacher'], $other['teacher']) === 0) {
                $itemConflicts[] = [
                    'type' => 'Teacher',
                    'message' => 'Teacher conflict inside the current draft: ' . $row['teacher'] . ' is assigned to two subjects at the same time.'
                ];
            }

            if ($row['room'] !== '' && strcasecmp($row['room'], $other['room']) === 0) {
                $itemConflicts[] = [
                    'type' => 'Room',
                    'message' => 'Room conflict inside the current draft: ' . $row['room'] . ' is assigned to two subjects at the same time.'
                ];
            }

            if ($row['day'] !== '' && $other['day'] !== '' && strcasecmp($row['day'], $other['day']) === 0) {
                $itemConflicts[] = [
                    'type' => 'Section',
                    'message' => 'Section conflict inside the current draft: two subjects overlap on the same section.'
                ];
            }
        }

        // Deduplicate conflicts by type + message.
        $unique = [];
        foreach ($itemConflicts as $conflict) {
            $key = $conflict['type'] . '|' . $conflict['message'];
            $unique[$key] = $conflict;
        }
        $itemConflicts = array_values($unique);

        if ($itemConflicts) {
            $conflictCount += count($itemConflicts);
            $results[] = [
                'index' => $row['index'],
                'subject_code' => $row['subject_code'],
                'result' => 'Conflict',
                'conflicts' => $itemConflicts,
                'message' => count($itemConflicts) . ' conflict(s) found.'
            ];
        } else {
            $availableCount++;
            $results[] = [
                'index' => $row['index'],
                'subject_code' => $row['subject_code'],
                'result' => 'Available',
                'conflicts' => [],
                'message' => 'No teacher, room, or section conflict found.'
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'section' => [
            'id' => (int)$section['id'],
            'code' => $section['code'],
            'name' => $section['name']
        ],
        'summary' => [
            'available' => $availableCount,
            'conflicts' => $conflictCount,
            'pending' => $pendingCount
        ],
        'results' => $results,
        'qualification_configured' => is_array($qualificationMap),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Check Availability API error: ' . $e->getMessage());
    $safeMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)
        ? $e->getMessage()
        : 'Availability check failed. Please contact the administrator.';
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $safeMessage
    ]);
}
