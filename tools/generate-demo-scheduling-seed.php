<?php
// Generates the Class Schedule demo data applied by database/migrate.php when SMS2_SEED_DEMO=1:
//   database/demo-scheduling-seed.sql        core data for AY 2026-2027, 1st Semester
//   database/demo-scheduling-seed-extra.sql  histories, a cloning source term, notifications
// Usage: php tools/generate-demo-scheduling-seed.php
//
// The data passes the app's own Conflict Checker rules (modules/scheduling/conflict-rules.php)
// except for one deliberate Draft room clash. Rows reference each other by codes, never ids.
// Both files may already be applied on production under their migration keys, so a change that
// alters their output must ship as a new file with a new migration key, not as an in-place edit.
declare(strict_types=1);

const AY = '2026-2027';
const SEM = '1st Semester';

$q = static fn (?string $v): string => $v === null ? 'NULL' : "'" . str_replace("'", "''", $v) . "'";

$rooms = [
    ['MB-101', 'Main Building', 'Lecture', 45, 'Available'],
    ['MB-102', 'Main Building', 'Lecture', 45, 'Available'],
    ['MB-103', 'Main Building', 'Lecture', 45, 'Available'],
    ['MB-201', 'Main Building', 'Lecture', 50, 'Available'],
    ['MB-202', 'Main Building', 'Lecture', 50, 'Available'],
    ['CL-301', 'Computer Laboratory Building', 'Laboratory', 45, 'Available'],
    ['CL-302', 'Computer Laboratory Building', 'Laboratory', 45, 'Available'],
    ['CL-303', 'Computer Laboratory Building', 'Laboratory', 35, 'Maintenance'],
    ['AVR-1', 'Main Building', 'Special', 150, 'Available'],
    ['GYM', 'Sports Complex', 'Special', 200, 'Available'],
];
$roomByCode = [];
foreach ($rooms as $r) {
    $roomByCode[$r[0]] = $r;
}
$facilities = [
    'MB-101' => ['Projector', 'Air-conditioning', 'Whiteboard'],
    'MB-102' => ['Smart TV', 'Air-conditioning', 'Whiteboard'],
    'MB-103' => ['Projector', 'Whiteboard'],
    'MB-201' => ['Projector', 'Air-conditioning', 'Sound System'],
    'MB-202' => ['Smart TV', 'Air-conditioning'],
    'CL-301' => ['45 Desktop Computers', 'Projector', 'Air-conditioning', 'Network Switch'],
    'CL-302' => ['45 Desktop Computers', 'Smart TV', 'Air-conditioning'],
    'CL-303' => ['35 Desktop Computers', 'Projector'],
    'AVR-1'  => ['Sound System', 'Projector', 'Stage', 'Air-conditioning'],
    'GYM'    => ['Sound System', 'Bleachers'],
];

$blocks = [
    ['CB-01', '7:00 AM - 10:00 AM', '07:00:00', '10:00:00', 'Class', 1],
    ['CB-02', '10:00 AM - 1:00 PM', '10:00:00', '13:00:00', 'Class', 2],
    ['CB-03', '1:00 PM - 4:00 PM', '13:00:00', '16:00:00', 'Class', 3],
    ['CB-04', '4:00 PM - 7:00 PM', '16:00:00', '19:00:00', 'Class', 4],
    ['EX-01', 'Exam 8:00 AM - 10:00 AM', '08:00:00', '10:00:00', 'Exam', 5],
    ['EX-02', 'Exam 10:30 AM - 12:30 PM', '10:30:00', '12:30:00', 'Exam', 6],
    ['EX-03', 'Exam 1:30 PM - 3:30 PM', '13:30:00', '15:30:00', 'Exam', 7],
    ['EX-04', 'Exam 4:00 PM - 6:00 PM', '16:00:00', '18:00:00', 'Exam', 8],
];
$classBlocks = ['CB-01', 'CB-02', 'CB-03', 'CB-04'];
$examBlocks = ['EX-01', 'EX-02', 'EX-03', 'EX-04'];
$blockTimes = [];
foreach ($blocks as $b) {
    $blockTimes[$b[0]] = [$b[2], $b[3]];
}

$teachers = [
    ['BCP-F-1001', 'Dr. Roberto M. Santos', 'College of Computer Studies', 'rsantos@bestlink.edu.ph'],
    ['BCP-F-1002', 'Dr. Jobert Valentino', 'College of Computer Studies', 'jobertvalentino@bestlink.edu.ph'],
    ['BCP-F-1003', 'Dr. Jonathan Estrada', 'College of Computer Studies', 'jonathanestrada@bestlink.edu.ph'],
    ['BCP-F-1004', 'Dr. Michelle Guevarra', 'College of Computer Studies', 'michelleguevarra@bestlink.edu.ph'],
    ['BCP-F-1005', 'Engr. Mark Anthony Dela Cruz', 'College of Computer Studies', 'markdelacruz@bestlink.edu.ph'],
    ['BCP-F-1006', 'Prof. Kristine Joy Ramos', 'College of Computer Studies', 'kristineramos@bestlink.edu.ph'],
    ['BCP-F-1007', 'Prof. Paolo Miguel Reyes', 'College of Computer Studies', 'paoloreyes@bestlink.edu.ph'],
    ['BCP-F-1012', 'Prof. Carmela Bautista', 'College of Computer Studies', 'carmelabautista@bestlink.edu.ph'],
    ['BCP-F-1008', 'Prof. Angela Marie Cruz', 'General Education Department', 'angelacruz@bestlink.edu.ph'],
    ['BCP-F-1009', 'Prof. Ramon Villanueva', 'General Education Department', 'ramonvillanueva@bestlink.edu.ph'],
    ['BCP-F-1010', 'Prof. Liza Mendoza', 'General Education Department', 'lizamendoza@bestlink.edu.ph'],
    ['BCP-F-1013', 'Prof. Jerome Castillo', 'General Education Department', 'jeromecastillo@bestlink.edu.ph'],
    ['BCP-F-1011', 'Coach Daniel Aquino', 'Physical Education Department', 'danielaquino@bestlink.edu.ph'],
];
const MAX_LOAD = 30;
$ccs = ['BCP-F-1001', 'BCP-F-1002', 'BCP-F-1003', 'BCP-F-1004', 'BCP-F-1005', 'BCP-F-1006', 'BCP-F-1007', 'BCP-F-1012'];
$ge = ['BCP-F-1008', 'BCP-F-1009', 'BCP-F-1010', 'BCP-F-1013'];
// Friday afternoon is research consultation time for Dr. Santos (an Unavailable window).
$unavailable = ['BCP-F-1001' => ['Friday', '13:00:00', '19:00:00']];

// code, name, units, type, lec hrs, lab hrs, program, year, qualified teachers
$subjects = [
    ['IT101', 'Introduction to Computing', 3, 'Major', 2, 3, 'BSIT', 1, ['BCP-F-1006', 'BCP-F-1012', 'BCP-F-1005']],
    ['IT102', 'Computer Programming 1', 3, 'Major', 2, 3, 'BSIT', 1, ['BCP-F-1005', 'BCP-F-1007', 'BCP-F-1006']],
    ['CS101', 'Introduction to Computer Science', 3, 'Major', 2, 3, 'BSCS', 1, ['BCP-F-1003', 'BCP-F-1004', 'BCP-F-1006']],
    ['CS102', 'Fundamentals of Programming', 3, 'Major', 2, 3, 'BSCS', 1, ['BCP-F-1007', 'BCP-F-1005', 'BCP-F-1012']],
    ['GE101', 'Understanding the Self', 3, 'GE', 3, 0, 'ALL', 1, $ge],
    ['GE102', 'Readings in Philippine History', 3, 'GE', 3, 0, 'ALL', 1, $ge],
    ['GE103', 'Mathematics in the Modern World', 3, 'GE', 3, 0, 'ALL', 1, $ge],
    ['PE101', 'PATHFit 1: Movement Competency Training', 2, 'PE', 2, 0, 'ALL', 1, ['BCP-F-1011']],
    ['NSTP1', 'National Service Training Program 1', 3, 'Minor', 3, 0, 'ALL', 1, $ge],
    ['IT201', 'Data Structures and Algorithms', 3, 'Major', 2, 3, 'BSIT', 2, ['BCP-F-1003', 'BCP-F-1005', 'BCP-F-1007']],
    ['IT202', 'Object-Oriented Programming', 3, 'Major', 2, 3, 'BSIT', 2, ['BCP-F-1007', 'BCP-F-1005', 'BCP-F-1012']],
    ['IT203', 'Networking 1', 3, 'Major', 2, 3, 'BSIT', 2, ['BCP-F-1012', 'BCP-F-1006', 'BCP-F-1002']],
    ['CS201', 'Discrete Structures', 3, 'Major', 3, 0, 'BSCS', 2, ['BCP-F-1004', 'BCP-F-1003']],
    ['CS202', 'Algorithms and Complexity', 3, 'Major', 3, 0, 'BSCS', 2, ['BCP-F-1003', 'BCP-F-1004']],
    ['GE104', 'Purposive Communication', 3, 'GE', 3, 0, 'ALL', 2, $ge],
    ['PE201', 'PATHFit 3: Individual and Dual Sports', 2, 'PE', 2, 0, 'ALL', 2, ['BCP-F-1011']],
    ['IT301', 'Information Assurance and Security 1', 3, 'Major', 2, 3, 'BSIT', 3, ['BCP-F-1002', 'BCP-F-1012']],
    ['IT302', 'Systems Integration and Architecture', 3, 'Major', 3, 0, 'BSIT', 3, ['BCP-F-1004', 'BCP-F-1002', 'BCP-F-1001']],
    ['IT303', 'Application Development and Emerging Technologies', 3, 'Major', 2, 3, 'BSIT', 3, ['BCP-F-1005', 'BCP-F-1007', 'BCP-F-1006']],
    ['IT304', 'Capstone Project and Research 1', 3, 'Major', 3, 0, 'BSIT', 3, ['BCP-F-1001', 'BCP-F-1002']],
    ['GE105', 'Ethics', 3, 'GE', 3, 0, 'ALL', 3, $ge],
    ['IT401', 'Capstone Project and Research 2', 3, 'Major', 3, 0, 'BSIT', 4, ['BCP-F-1001', 'BCP-F-1003']],
    ['IT402', 'Systems Administration and Maintenance', 3, 'Major', 2, 3, 'BSIT', 4, ['BCP-F-1012', 'BCP-F-1006', 'BCP-F-1002']],
    ['IT403', 'Social and Professional Issues', 3, 'Major', 3, 0, 'BSIT', 4, ['BCP-F-1004', 'BCP-F-1002', 'BCP-F-1001']],
    ['IT-EL1', 'IT Elective 1: Mobile Application Development', 3, 'Elective', 2, 3, 'BSIT', 4, ['BCP-F-1007', 'BCP-F-1006', 'BCP-F-1005']],
];
$subjectByCode = [];
foreach ($subjects as $s) {
    $subjectByCode[$s[0]] = $s;
}

$sections = [
    ['BSIT-1A', 'BSIT 1-A', 'BSIT', 1, 45, 42, 'BCP-F-1006', ['IT101', 'IT102', 'GE101', 'GE102', 'GE103', 'PE101', 'NSTP1']],
    ['BSIT-1B', 'BSIT 1-B', 'BSIT', 1, 45, 39, 'BCP-F-1007', ['IT101', 'IT102', 'GE101', 'GE102', 'GE103', 'PE101', 'NSTP1']],
    ['BSCS-1A', 'BSCS 1-A', 'BSCS', 1, 40, 36, 'BCP-F-1003', ['CS101', 'CS102', 'GE101', 'GE102', 'GE103', 'PE101', 'NSTP1']],
    ['BSIT-2A', 'BSIT 2-A', 'BSIT', 2, 45, 41, 'BCP-F-1012', ['IT201', 'IT202', 'IT203', 'GE104', 'PE201']],
    ['BSCS-2A', 'BSCS 2-A', 'BSCS', 2, 40, 33, 'BCP-F-1004', ['CS201', 'CS202', 'IT202', 'GE104', 'PE201']],
    ['BSIT-3A', 'BSIT 3-A', 'BSIT', 3, 45, 38, 'BCP-F-1002', ['IT301', 'IT302', 'IT303', 'IT304', 'GE105']],
    ['BSIT-4A', 'BSIT 4-A', 'BSIT', 4, 40, 35, 'BCP-F-1001', ['IT401', 'IT402', 'IT403', 'IT-EL1']],
];
$sectionByCode = [];
foreach ($sections as $s) {
    $sectionByCode[$s[0]] = $s;
}

// ---------- placement engine mirroring ccOverlap ----------
$placed = [];   // each: [date|null, weekday, start, end, section, teacher, room]
$load = [];
$weekday = static fn (string $date): string => date('l', strtotime($date));
$clash = static function (?string $date, string $day, string $st, string $en, ?string $sec, ?string $t, ?string $room) use (&$placed): bool {
    foreach ($placed as $p) {
        if ($date !== null && $p[0] !== null && $date !== $p[0]) {
            continue;
        }
        if ($day !== $p[1]) {
            continue;
        }
        if (!($st < $p[3] && $en > $p[2])) {
            continue;
        }
        if (($sec !== null && $sec === $p[4]) || ($t !== null && $t === $p[5]) || ($room !== null && $room === $p[6])) {
            return true;
        }
    }
    return false;
};
$teacherOk = static function (string $t, string $day, string $st, string $en) use ($unavailable): bool {
    if (!isset($unavailable[$t])) {
        return true;
    }
    [$uDay, $uSt, $uEn] = $unavailable[$t];
    return !($uDay === $day && $uSt < $en && $uEn > $st);
};
// Candidate teachers ordered by lightest load, so the work is shared.
$byLoad = static function (array $list) use (&$load): array {
    usort($list, static fn ($a, $b) => ($load[$a] ?? 0) <=> ($load[$b] ?? 0));
    return $list;
};

$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$entries = [];
$classTeacher = [];
$slotCursor = 0;
foreach ($sections as $sec) {
    $students = $sec[5];
    foreach ($sec[7] as $subCode) {
        $sub = $subjectByCode[$subCode];
        $units = (float) $sub[2];
        if ($sub[3] === 'PE') {
            $roomPool = ['GYM'];
        } elseif ($subCode === 'NSTP1') {
            $roomPool = ['AVR-1'];
        } else {
            $type = $sub[5] > 0 ? 'Laboratory' : 'Lecture';
            $roomPool = array_keys(array_filter($roomByCode, static fn ($r) => $r[2] === $type && $r[4] === 'Available' && $r[3] >= $students));
        }
        $done = false;
        // Rotate the starting slot so sections spread across the week.
        $slots = [];
        foreach ($weekdays as $d) {
            foreach ($classBlocks as $b) {
                $slots[] = [$d, $b];
            }
        }
        $slots = array_merge(array_slice($slots, $slotCursor % count($slots)), array_slice($slots, 0, $slotCursor % count($slots)));
        $slotCursor += 5;
        foreach ($slots as [$day, $blk]) {
            [$st, $en] = $blockTimes[$blk];
            foreach ($byLoad($sub[8]) as $t) {
                // Class units plus the same units again for proctoring the midterm.
                $extra = $sub[3] === 'PE' ? $units : 2 * $units;
                if (($load[$t] ?? 0) + $extra > MAX_LOAD - 3 || !$teacherOk($t, $day, $st, $en)) {
                    continue;
                }
                foreach ($roomPool as $room) {
                    if ($clash(null, $day, $st, $en, $sec[0], $t, $room)) {
                        continue;
                    }
                    $placed[] = [null, $day, $st, $en, $sec[0], $t, $room];
                    $load[$t] = ($load[$t] ?? 0) + $units;
                    $classTeacher[$sec[0] . '|' . $subCode] = $t;
                    $entries[] = [$sec[0], $subCode, $t, $room, $blk, $day, $sub[5] > 0 ? 'Laboratory' : 'Lecture'];
                    $done = true;
                    break 3;
                }
            }
        }
        if (!$done) {
            fwrite(STDERR, "Could not place class {$sec[0]}/$subCode\n");
            exit(1);
        }
    }
}

// Midterms (12-17 Oct 2026), proctored by the class teacher.
$examDays = ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17'];
$exams = [];
foreach ($sections as $sec) {
    $roomPool = array_keys(array_filter($roomByCode, static fn ($r) => $r[2] === 'Lecture' && $r[4] === 'Available' && $r[3] >= $sec[5]));
    foreach ($sec[7] as $subCode) {
        $sub = $subjectByCode[$subCode];
        if ($sub[3] === 'PE') {
            continue;
        }
        $t = $classTeacher[$sec[0] . '|' . $subCode];
        $done = false;
        foreach ($examDays as $date) {
            $day = $weekday($date);
            foreach ($examBlocks as $blk) {
                [$st, $en] = $blockTimes[$blk];
                if (!$teacherOk($t, $day, $st, $en)) {
                    continue;
                }
                foreach ($roomPool as $room) {
                    if ($clash($date, $day, $st, $en, $sec[0], $t, $room)) {
                        continue;
                    }
                    $placed[] = [$date, $day, $st, $en, $sec[0], $t, $room];
                    $load[$t] = ($load[$t] ?? 0) + $sub[2];
                    $exams[] = [$sec[0], $subCode, $t, $room, $blk, $date];
                    $done = true;
                    break 3;
                }
            }
        }
        if (!$done) {
            fwrite(STDERR, "Could not place exam {$sec[0]}/$subCode\n");
            exit(1);
        }
    }
}

// Special classes, taught by the class teacher, placed on free dated slots.
$specialDefs = [
    ['SC-2026-0001', 'Make-up Class: Computer Programming 1', 'Makeup', 'BSIT-1A', 'IT102', ['2026-10-03', '2026-10-05', '2026-10-06'], 'Laboratory', 'Published', 'Replaces the class suspended on 29 September 2026 due to weather'],
    ['SC-2026-0002', 'Midterm Review: Data Structures and Algorithms', 'Review', 'BSIT-2A', 'IT201', ['2026-10-10', '2026-10-09', '2026-10-08'], 'Lecture', 'Scheduled', 'Optional review session before the midterm examinations'],
    ['SC-2026-0003', 'Remedial Class: Mathematics in the Modern World', 'Remedial', 'BSCS-1A', 'GE103', ['2026-10-24', '2026-10-22', '2026-10-21'], 'Lecture', 'Draft', 'For students below 75 in the prelim examination'],
    ['SC-2026-0004', 'Capstone Consultation Seminar', 'Seminar', 'BSIT-4A', 'IT401', ['2026-10-31', '2026-10-29', '2026-10-28'], 'Special', 'Validated', 'Title defense preparation for all capstone groups'],
];
$specials = [];
foreach ($specialDefs as [$ref, $title, $type, $secCode, $subCode, $dates, $roomType, $status, $remarks]) {
    $sec = $sectionByCode[$secCode];
    $t = $classTeacher[$secCode . '|' . $subCode];
    $roomPool = array_keys(array_filter($roomByCode, static fn ($r) => $r[2] === $roomType && $r[4] === 'Available' && $r[3] >= $sec[5]));
    $done = false;
    foreach ($dates as $date) {
        $day = $weekday($date);
        foreach ($classBlocks as $blk) {
            [$st, $en] = $blockTimes[$blk];
            if (!$teacherOk($t, $day, $st, $en) || ($load[$t] ?? 0) + $subjectByCode[$subCode][2] > MAX_LOAD) {
                continue;
            }
            foreach ($roomPool as $room) {
                if ($clash($date, $day, $st, $en, $secCode, $t, $room)) {
                    continue;
                }
                $placed[] = [$date, $day, $st, $en, $secCode, $t, $room];
                $load[$t] = ($load[$t] ?? 0) + $subjectByCode[$subCode][2];
                $specials[] = [$ref, $title, $type, $sec[2], $secCode, $subCode, $t, $room, $blk, $date, $status, $remarks];
                $done = true;
                break 3;
            }
        }
    }
    if (!$done) {
        fwrite(STDERR, "Could not place special class $ref\n");
        exit(1);
    }
}

// Deliberate Draft clash: BSIT-4A / IT403 in a room that is already taken, at a time when
// the section and teacher are free, so the Conflict Checker reports only a room conflict.
$demoClash = null;
foreach ($entries as $e) {
    [$eSec, , , $eRoom, $eBlk, $eDay] = $e;
    if ($eSec === 'BSIT-4A' || $roomByCode[$eRoom][2] !== 'Lecture' || $roomByCode[$eRoom][3] < 35) {
        continue;
    }
    [$st, $en] = $blockTimes[$eBlk];
    foreach (['BCP-F-1004', 'BCP-F-1002'] as $t) {
        if ($teacherOk($t, $eDay, $st, $en) && !$clash(null, $eDay, $st, $en, 'BSIT-4A', $t, null) && ($load[$t] ?? 0) + 3 <= MAX_LOAD) {
            $demoClash = ['BSIT-4A', 'IT403', $t, $eRoom, $eBlk, $eDay];
            $load[$t] = ($load[$t] ?? 0) + 3;
            break 2;
        }
    }
}
if ($demoClash === null) {
    fwrite(STDERR, "Could not place the demo clash\n");
    exit(1);
}

// ---------- SQL ----------
$out = [];
$out[] = '-- Demo data for the Class Schedule module (AY ' . AY . ', ' . SEM . ').';
$out[] = '-- Applied by database/migrate.php only when SMS2_SEED_DEMO=1. Rows link by codes,';
$out[] = '-- so the file does not depend on auto-increment ids. It passes the Conflict Checker';
$out[] = '-- rules except for one Draft entry that deliberately double-books a room.';
$out[] = '';
$out[] = 'INSERT IGNORE INTO rooms (room_code, building, room_type, capacity, status) VALUES';
$out[] = implode(",\n", array_map(static fn ($r) => "({$q($r[0])}, {$q($r[1])}, {$q($r[2])}, {$r[3]}, {$q($r[4])})", $rooms)) . ';';
foreach ($facilities as $room => $list) {
    foreach ($list as $f) {
        $out[] = "INSERT INTO room_facilities (room_id, facility_name) SELECT id, {$q($f)} FROM rooms WHERE room_code = {$q($room)};";
    }
}
$out[] = '';
$out[] = 'INSERT IGNORE INTO time_blocks (code, label, start_time, end_time, block_type, sort_order) VALUES';
$out[] = implode(",\n", array_map(static fn ($b) => "({$q($b[0])}, {$q($b[1])}, {$q($b[2])}, {$q($b[3])}, {$q($b[4])}, {$b[5]})", $blocks)) . ';';
$out[] = "INSERT IGNORE INTO time_block_sets (reference_no, name, start_time, end_time, interval_minutes, block_type, status, block_count) VALUES ('TBS-2026-001', 'Regular 3-hour Class Blocks', '07:00:00', '19:00:00', 180, 'Class', 'Active', 4);";
$out[] = "INSERT IGNORE INTO time_block_sets (reference_no, name, start_time, end_time, interval_minutes, block_type, status, block_count) VALUES ('TBS-2026-002', 'Examination Blocks', '08:00:00', '18:00:00', 120, 'Exam', 'Active', 4);";
foreach ($classBlocks as $i => $blk) {
    $out[] = 'INSERT INTO time_block_set_items (time_block_set_id, time_block_id, sequence_no) SELECT s.id, b.id, ' . ($i + 1) . " FROM time_block_sets s, time_blocks b WHERE s.reference_no = 'TBS-2026-001' AND b.code = '$blk';";
}
foreach ($examBlocks as $i => $blk) {
    $out[] = 'INSERT INTO time_block_set_items (time_block_set_id, time_block_id, sequence_no) SELECT s.id, b.id, ' . ($i + 1) . " FROM time_block_sets s, time_blocks b WHERE s.reference_no = 'TBS-2026-002' AND b.code = '$blk';";
}
$out[] = '';
$out[] = 'INSERT IGNORE INTO teachers (employee_no, full_name, department, email, max_load_units) VALUES';
$out[] = implode(",\n", array_map(static fn ($t) => "({$q($t[0])}, {$q($t[1])}, {$q($t[2])}, {$q($t[3])}, " . MAX_LOAD . ')', $teachers)) . ';';
$out[] = '';
$out[] = 'INSERT IGNORE INTO subjects (code, name, units, subject_type, lecture_hours, lab_hours, program, year_level, semester) VALUES';
$out[] = implode(",\n", array_map(static fn ($s) => "({$q($s[0])}, {$q($s[1])}, {$s[2]}, {$q($s[3])}, {$s[4]}, {$s[5]}, {$q($s[6])}, {$s[7]}, " . $q(SEM) . ')', $subjects)) . ';';
foreach ($subjects as $s) {
    foreach ($s[8] as $emp) {
        $out[] = 'INSERT IGNORE INTO teacher_subject_qualifications (teacher_id, subject_id, specialization_label, verified_at) SELECT t.id, s.id, ' . $q($s[1]) . ", NOW() FROM teachers t, subjects s WHERE t.employee_no = '$emp' AND s.code = '{$s[0]}';";
    }
}
$out[] = '';
foreach ($teachers as $t) {
    foreach ($weekdays as $day) {
        $out[] = "INSERT INTO teacher_availability (teacher_id, day_of_week, start_time, end_time, availability, academic_year, semester) SELECT id, '$day', '07:00:00', '19:00:00', 'Available', '" . AY . "', '" . SEM . "' FROM teachers WHERE employee_no = '{$t[0]}';";
    }
}
foreach ($unavailable as $t => [$day, $st, $en]) {
    $out[] = "INSERT INTO teacher_availability (teacher_id, day_of_week, start_time, end_time, availability, academic_year, semester, remarks) SELECT id, '$day', '$st', '$en', 'Unavailable', '" . AY . "', '" . SEM . "', 'Research consultation hours' FROM teachers WHERE employee_no = '$t';";
}
$out[] = '';
$parts = [];
foreach ($sections as $s) {
    $adviser = array_values(array_filter($teachers, static fn ($t) => $t[0] === $s[6]))[0];
    $parts[] = "SELECT {$q($s[0])}, {$q($s[1])}, {$q($s[2])}, {$s[3]}, " . $q(SEM) . ', ' . $q(AY) . ", {$s[4]}, {$s[5]}, {$q($adviser[1])}, (SELECT id FROM teachers WHERE employee_no = '{$s[6]}'), 'Active'";
}
$out[] = 'INSERT IGNORE INTO sections (code, name, program, year_level, semester, academic_year, max_students, current_students, advisor_name, advisor_teacher_id, status)';
$out[] = implode("\nUNION ALL ", $parts) . ';';
$secWhere = static fn (string $code): string => "sec.code = '$code' AND sec.academic_year = '" . AY . "' AND sec.semester = '" . SEM . "'";
foreach ($sections as $s) {
    foreach ($s[7] as $sub) {
        $out[] = "INSERT IGNORE INTO section_subjects (section_id, subject_id) SELECT sec.id, sub.id FROM sections sec, subjects sub WHERE {$secWhere($s[0])} AND sub.code = '$sub';";
    }
}
$out[] = '';
$entrySql = static function (array $e, string $status, ?string $remarks) use ($blockTimes, $secWhere, $q): string {
    [$sec, $sub, $t, $room, $blk, $day, $classType] = $e + [6 => 'Lecture'];
    [$st, $en] = $blockTimes[$blk];
    return 'INSERT INTO schedule_entries (section_id, subject_id, teacher_id, room_id, time_block_id, day_of_week, start_time, end_time, class_type, status, source, academic_year, semester, remarks)'
        . " SELECT sec.id, sub.id, t.id, r.id, b.id, '$day', '$st', '$en', '$classType', '$status', '" . ($status === 'Published' ? 'Optimizer' : 'Manual') . "', '" . AY . "', '" . SEM . "', " . $q($remarks)
        . " FROM sections sec, subjects sub, teachers t, rooms r, time_blocks b WHERE {$secWhere($sec)} AND sub.code = '$sub' AND t.employee_no = '$t' AND r.room_code = '$room' AND b.code = '$blk';";
};
foreach ($entries as $e) {
    $out[] = $entrySql($e, 'Published', null);
}
$out[] = $entrySql($demoClash, 'Draft', 'Demo: proposed extra session that double-books room ' . $demoClash[3]);
$out[] = '';
$out[] = "INSERT IGNORE INTO exam_timetable_batches (reference_no, title, exam_type, academic_year, semester, date_from, date_to, status, solver_engine, published_at) VALUES ('EXB-2026-MID', 'Midterm Examinations - AY 2026-2027, 1st Semester', 'Midterm', '" . AY . "', '" . SEM . "', '2026-10-12', '2026-10-17', 'Published', 'OR-Tools CP-SAT', NOW());";
foreach ($exams as [$sec, $sub, $t, $room, $blk, $date]) {
    [$st, $en] = $blockTimes[$blk];
    $out[] = 'INSERT INTO exam_schedules (batch_id, section_id, subject_id, proctor_id, room_id, time_block_id, exam_type, exam_date, start_time, end_time, status, source, academic_year, semester)'
        . " SELECT bt.id, sec.id, sub.id, t.id, r.id, b.id, 'Midterm', '$date', '$st', '$en', 'Published', 'Optimizer', '" . AY . "', '" . SEM . "'"
        . " FROM exam_timetable_batches bt, sections sec, subjects sub, teachers t, rooms r, time_blocks b WHERE bt.reference_no = 'EXB-2026-MID' AND {$secWhere($sec)} AND sub.code = '$sub' AND t.employee_no = '$t' AND r.room_code = '$room' AND b.code = '$blk';";
}
$out[] = '';
foreach ($specials as [$ref, $title, $type, $program, $sec, $sub, $t, $room, $blk, $date, $status, $remarks]) {
    [$st, $en] = $blockTimes[$blk];
    $out[] = 'INSERT IGNORE INTO special_classes (reference_no, title, special_type, academic_year, semester, program, assignment_mode, section_id, subject_id, teacher_id, room_id, time_block_id, class_date, start_time, end_time, status, remarks)'
        . " SELECT '$ref', {$q($title)}, '$type', '" . AY . "', '" . SEM . "', '$program', 'Section', sec.id, sub.id, t.id, r.id, b.id, '$date', '$st', '$en', '$status', {$q($remarks)}"
        . " FROM sections sec, subjects sub, teachers t, rooms r, time_blocks b WHERE {$secWhere($sec)} AND sub.code = '$sub' AND t.employee_no = '$t' AND r.room_code = '$room' AND b.code = '$blk';";
}
// Substitutes as first shipped (kept byte-identical so the base file matches what may already be applied).
$v1SubDefs = [['BSIT-3A', 'IT301', '2026-10-06', 'Assigned', 'Attending the DICT cybersecurity summit'], ['BSIT-2A', 'IT202', '2026-10-08', 'Pending', 'Sick leave'], ['BSIT-1B', 'GE101', '2026-09-24', 'Completed', 'Family emergency']];
foreach ($v1SubDefs as [$sec, $sub, $date, $status, $reason]) {
    $orig = $classTeacher["$sec|$sub"];
    $alt = array_values(array_diff($subjectByCode[$sub][8], [$orig]))[0] ?? null;
    $substitute = $status === 'Pending' || $alt === null ? 'NULL' : "(SELECT id FROM teachers WHERE employee_no = '$alt')";
    $out[] = 'INSERT IGNORE INTO substitute_assignments (schedule_entry_id, original_teacher_id, substitute_teacher_id, absence_date, reason, status)'
        . " SELECT e.id, e.teacher_id, $substitute, '$date', {$q($reason)}, '$status'"
        . " FROM schedule_entries e JOIN sections sec ON sec.id = e.section_id JOIN subjects sub ON sub.id = e.subject_id WHERE {$secWhere($sec)} AND sub.code = '$sub' AND e.status = 'Published' ORDER BY e.id LIMIT 1;";
}
$base = $out;
$out = [];
$out[] = '-- Extra demo data for every Class Schedule page (histories, a cloning source term,';
$out[] = '-- substitute validation and notifications). Applied after demo-scheduling-seed.sql';
$out[] = '-- only when SMS2_SEED_DEMO=1; it corrects the first seed\'s substitute rows in place.';
$registrar = "(SELECT id FROM users WHERE username = 'registrar' LIMIT 1)";
$teacherName = [];
foreach ($teachers as $t) {
    $teacherName[$t[0]] = $t[1];
}
$json = static fn (array $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$entryBy = [];
foreach ($entries as $e) {
    $entryBy[$e[0] . '|' . $e[1]] = $e;
}

// ---------- Substitute teachers (qualified, free at that time, on the class's weekday) ----------
$out[] = '';
$subDefs = [
    ['BSIT-3A', 'IT301', '2026-10-05', 'Assigned', 'Attending the DICT cybersecurity summit'],
    ['BSIT-2A', 'IT202', '2026-10-05', 'Pending', 'Sick leave (medical certificate submitted)'],
    ['BSIT-1B', 'GE101', '2026-09-21', 'Completed', 'Family emergency'],
];
foreach ($subDefs as $i => [$sec, $sub, $weekStart, $status, $reason]) {
    $e = $entryBy["$sec|$sub"];
    [, , $orig, $room, $blk, $day] = $e;
    [$st, $en] = $blockTimes[$blk];
    $date = $weekStart;
    while ($weekday($date) !== $day) {
        $date = date('Y-m-d', strtotime($date . ' +1 day'));
    }
    $alt = null;
    $pool = array_merge($subjectByCode[$sub][8], $subjectByCode[$sub][3] === 'GE' ? $ge : $ccs);
    foreach ($byLoad(array_values(array_unique(array_diff($pool, [$orig])))) as $cand) {
        if ($teacherOk($cand, $day, $st, $en) && !$clash($date, $day, $st, $en, null, $cand, null)) {
            $alt = $cand;
            break;
        }
    }
    if ($status !== 'Pending' && $alt === null) {
        fwrite(STDERR, "No free substitute for $sec/$sub\n");
        exit(1);
    }
    if ($status !== 'Pending' && !in_array($alt, $subjectByCode[$sub][8], true)) {
        $out[] = 'INSERT IGNORE INTO teacher_subject_qualifications (teacher_id, subject_id, specialization_label, verified_at) SELECT t.id, s.id, ' . $q($subjectByCode[$sub][1]) . ", NOW() FROM teachers t, subjects s WHERE t.employee_no = '$alt' AND s.code = '$sub';";
    }
    // Correct the first seed's row (matched by its original reason) instead of adding a second one.
    [, , , , $v1Reason] = $v1SubDefs[$i];
    $substituteSql = $status === 'Pending' ? 'NULL' : "(SELECT id FROM teachers WHERE employee_no = '$alt')";
    $out[] = 'UPDATE substitute_assignments a JOIN schedule_entries e ON e.id = a.schedule_entry_id JOIN sections sec ON sec.id = e.section_id JOIN subjects sub ON sub.id = e.subject_id'
        . " SET a.absence_date = '$date', a.absence_end_date = '$date', a.duration_label = 'Whole class meeting', a.reason = {$q($reason)}, a.status = '$status', a.substitute_teacher_id = $substituteSql, a.remarks = " . $q($status === 'Pending' ? 'Waiting for a qualified substitute' : null)
        . " WHERE {$secWhere($sec)} AND sub.code = '$sub' AND a.reason = {$q($v1Reason)};";
    $where = "reason = {$q($reason)} AND absence_date = '$date'";
    $out[] = "UPDATE substitute_assignments SET reference_no = CONCAT('SA-', YEAR(absence_date), '-', LPAD(id, 6, '0')) WHERE $where AND reference_no IS NULL;";
    $out[] = "INSERT INTO substitute_assignment_history (substitute_assignment_id, action, from_status, to_status, detail, changed_by, changed_at) SELECT id, 'Absence recorded', NULL, 'Pending', " . $q("Absence of {$teacherName[$orig]} recorded for $sub ($sec) on $date.") . ", $registrar, '" . date('Y-m-d', strtotime("$date -3 days")) . " 09:15:00' FROM substitute_assignments WHERE $where;";
    if ($status !== 'Pending') {
        $checks = [];
        foreach ([['exists', 'Teacher record'], ['active', 'Active faculty'], ['different', 'Different from absent faculty'], ['qualification', 'Qualified for subject'], ['availability', 'Available at class time'], ['conflict', 'No schedule conflict'], ['load', 'Load within limit']] as [$code, $label]) {
            $checks[] = ['code' => $code, 'label' => $label, 'passed' => true, 'level' => 'critical', 'detail' => $label . ' - passed.'];
        }
        $validation = $json(['eligible' => true, 'score' => 100, 'teacher' => ['employee_no' => $alt, 'full_name' => $teacherName[$alt]], 'checks' => $checks, 'conflicts' => []]);
        $assignedAt = date('Y-m-d', strtotime("$date -2 days")) . ' 14:30:00';
        $out[] = "UPDATE substitute_assignments SET validation_json = {$q($validation)}, assigned_at = '$assignedAt', notified_at = '$assignedAt'"
            . ($status === 'Completed' ? ", completed_at = '$date $en'" : '') . " WHERE $where;";
        $out[] = "INSERT INTO substitute_assignment_history (substitute_assignment_id, action, from_status, to_status, detail, snapshot_json, changed_by, changed_at) SELECT id, 'Substitute assigned', 'Pending', 'Assigned', " . $q("{$teacherName[$alt]} assigned after passing all 7 eligibility checks.") . ", validation_json, $registrar, '$assignedAt' FROM substitute_assignments WHERE $where;";
        $out[] = "INSERT IGNORE INTO scheduling_notifications (event_key, recipient_type, recipient_id, title, message, action_url, status, related_type, related_id, created_by, created_at, sent_at) SELECT CONCAT('substitute-assigned-', id), 'Teacher', substitute_teacher_id, 'Substitute teaching assignment', "
            . $q("You are assigned to handle $sub for $sec on $date, " . substr($st, 0, 5) . '-' . substr($en, 0, 5) . " in room $room.") . ", '/modules/scheduling/pages/substitute-assignment-tracker.php', 'Sent', 'substitute_assignment', id, $registrar, '$assignedAt', '$assignedAt' FROM substitute_assignments WHERE $where;";
        $out[] = "INSERT IGNORE INTO scheduling_notifications (event_key, recipient_type, recipient_id, title, message, action_url, status, related_type, related_id, created_by, created_at, sent_at) SELECT CONCAT('substitute-assigned-', a.id), 'Section', e.section_id, 'Substitute teacher for $sub', "
            . $q("{$teacherName[$alt]} will handle $sub on $date because {$teacherName[$orig]} is absent.") . ", NULL, 'Sent', 'substitute_assignment', a.id, $registrar, '$assignedAt', '$assignedAt' FROM substitute_assignments a JOIN schedule_entries e ON e.id = a.schedule_entry_id WHERE a.$where;";
        if ($status === 'Completed') {
            $out[] = "INSERT INTO substitute_assignment_history (substitute_assignment_id, action, from_status, to_status, detail, changed_by, changed_at) SELECT id, 'Marked completed', 'Assigned', 'Completed', 'Substitute class was held as scheduled.', $registrar, '$date $en' FROM substitute_assignments WHERE $where;";
        }
    }
}

// ---------- Exam timetable history, sharing and a clean validation run ----------
$out[] = '';
$examHistory = [
    ['Batch created', null, 'Draft', 'Midterm examination batch created for 12-17 October 2026.', '2026-09-18 10:00:00'],
    ['Timetable generated', 'Draft', 'Generated', 'OR-Tools CP-SAT placed ' . count($exams) . ' examinations without section, proctor or room conflicts.', '2026-09-18 10:04:00'],
    ['Validated', 'Generated', 'Validated', 'Conflict Checker run VAL-20260920093015-a1b2c3 found no conflicts.', '2026-09-20 09:30:15'],
    ['Published', 'Validated', 'Published', 'Timetable published to faculty and students.', '2026-09-21 08:00:00'],
];
foreach ($examHistory as [$action, $from, $to, $detail, $at]) {
    $out[] = "INSERT INTO exam_timetable_history (batch_id, action, from_status, to_status, detail, changed_by, created_at) SELECT id, {$q($action)}, {$q($from)}, {$q($to)}, {$q($detail)}, $registrar, '$at' FROM exam_timetable_batches WHERE reference_no = 'EXB-2026-MID';";
}
foreach ([['Faculty', 'Sent', 'Your proctoring assignments for the midterm examinations are now available.'], ['Students/Sections', 'Sent', 'The midterm examination schedule for your section has been published.'], ['Program Heads', 'Queued', 'Summary of the midterm examination timetable for review.']] as [$aud, $st, $msg]) {
    $out[] = "INSERT INTO exam_timetable_shares (batch_id, audience, delivery_status, message, sent_by, sent_at, created_at) SELECT id, '$aud', '$st', {$q($msg)}, $registrar, " . ($st === 'Sent' ? "'2026-09-21 08:05:00'" : 'NULL') . ", '2026-09-21 08:05:00' FROM exam_timetable_batches WHERE reference_no = 'EXB-2026-MID';";
}
$runId = 'VAL-20260920093015-a1b2c3';
$out[] = "INSERT IGNORE INTO validation_runs (run_id, academic_year, semester, schedule_type, selected_count, findings_count, critical_count, warning_count, resolved_count, status, completed_at, created_by, created_at) VALUES ('$runId', '" . AY . "', '" . SEM . "', 'Exam', " . count($exams) . ", 0, 0, 0, 0, 'Valid', '2026-09-20 09:30:18', $registrar, '2026-09-20 09:30:15');";
$out[] = "INSERT INTO validation_run_records (run_id, record_key, schedule_type, reference_id, before_status, result_status, after_status, created_at) SELECT '$runId', CONCAT('Exam:', ex.id), 'Exam', ex.id, 'Generated', 'Valid', 'Validated', '2026-09-20 09:30:18' FROM exam_schedules ex JOIN exam_timetable_batches bt ON bt.id = ex.batch_id WHERE bt.reference_no = 'EXB-2026-MID';";
$out[] = "UPDATE exam_timetable_batches SET validation_run_id = '$runId', validation_summary_json = {$q($json(['selected' => count($exams), 'total' => 0, 'critical' => 0, 'warnings' => 0]))} WHERE reference_no = 'EXB-2026-MID';";

// ---------- Special class history ----------
$out[] = '';
$flow = ['Draft' => ['Draft'], 'Scheduled' => ['Draft', 'Scheduled'], 'Validated' => ['Draft', 'Scheduled', 'Validated'], 'Published' => ['Draft', 'Scheduled', 'Validated', 'Published']];
foreach ($specials as $i => $sp) {
    [$ref, $title, , , , , , , , $date, $status] = $sp;
    $prev = null;
    foreach ($flow[$status] as $k => $step) {
        $action = ['Draft' => 'Created', 'Scheduled' => 'Scheduled', 'Validated' => 'Validated', 'Published' => 'Published'][$step];
        $detail = ['Draft' => "Special class request \"$title\" created.", 'Scheduled' => 'Faculty, room and time block assigned.', 'Validated' => 'Passed conflict, capacity and faculty load checks.', 'Published' => 'Published to the section and faculty.'][$step];
        $at = date('Y-m-d', strtotime("2026-09-15 +$i days")) . ' ' . sprintf('%02d:00:00', 9 + $k);
        $out[] = "INSERT INTO special_class_history (special_class_id, action, from_status, to_status, detail, actor_user_id, created_at) SELECT id, '$action', {$q($prev)}, '$step', {$q($detail)}, $registrar, '$at' FROM special_classes WHERE reference_no = '$ref';";
        $prev = $step;
    }
    if ($status === 'Validated' || $status === 'Published') {
        $out[] = "UPDATE special_classes SET validated_at = '" . date('Y-m-d', strtotime("2026-09-15 +$i days")) . " 11:00:00'" . ($status === 'Published' ? ", published_at = '" . date('Y-m-d', strtotime("2026-09-15 +$i days")) . " 12:00:00'" : '') . " WHERE reference_no = '$ref';";
    }
}

// ---------- Room reassignment history ----------
$out[] = '';
$roomMoves = 0;
foreach ($entries as $e) {
    [$sec, $sub, $t, $room, $blk, $day] = $e;
    $type = $roomByCode[$room][2];
    $other = array_values(array_filter(array_keys($roomByCode), static fn ($c) => $c !== $room && $roomByCode[$c][2] === $type && $roomByCode[$c][4] === 'Available'))[0] ?? null;
    if ($other === null || $type === 'Special') {
        continue;
    }
    [$st, $en] = $blockTimes[$blk];
    $validation = $json(['valid' => true, 'checks' => array_map(static fn ($c) => ['code' => $c[0], 'label' => $c[1], 'passed' => true, 'detail' => $c[2]], [
        ['active', 'Room is active', "Room $room is active."], ['maintenance', 'Not under maintenance', 'No maintenance is scheduled.'], ['type', 'Correct room type', "$type room matches the class type."],
        ['capacity', 'Enough capacity', "Room $room holds {$roomByCode[$room][3]} for {$sectionByCode[$sec][5]} students."], ['facilities', 'Required facilities', 'All required facilities are present.'], ['overlap', 'No schedule overlap', "No other class uses $room on $day " . substr($st, 0, 5) . '-' . substr($en, 0, 5) . '.'],
    ]), 'missing_facilities' => [], 'occupants' => []]);
    $out[] = 'INSERT INTO room_assignment_history (source_type, source_record_id, previous_room_id, assigned_room_id, schedule_snapshot_json, validation_json, assigned_by, assigned_at)'
        . " SELECT 'Regular', e.id, (SELECT id FROM rooms WHERE room_code = '$other'), e.room_id, JSON_OBJECT('section_code', sec.code, 'subject_code', sub.code, 'day_of_week', e.day_of_week, 'start_time', e.start_time, 'end_time', e.end_time, 'status', 'Draft'), {$q($validation)}, $registrar, '2026-09-1" . (6 + $roomMoves) . " 15:20:00'"
        . " FROM schedule_entries e JOIN sections sec ON sec.id = e.section_id JOIN subjects sub ON sub.id = e.subject_id JOIN rooms r ON r.id = e.room_id WHERE {$secWhere($sec)} AND sub.code = '$sub' AND e.day_of_week = '$day' AND r.room_code = '$room';";
    if (++$roomMoves === 3) {
        break;
    }
}

// ---------- Time block history ----------
$out[] = '';
$out[] = "INSERT INTO time_block_history (time_block_set_id, action, detail, snapshot_json, changed_by, created_at) SELECT id, 'Set generated', 'Generated 4 class blocks from 7:00 AM to 7:00 PM at 180-minute intervals.', {$q($json(['blocks' => $classBlocks]))}, $registrar, '2026-08-20 10:00:00' FROM time_block_sets WHERE reference_no = 'TBS-2026-001';";
$out[] = "INSERT INTO time_block_history (time_block_set_id, action, detail, snapshot_json, changed_by, created_at) SELECT id, 'Set generated', 'Generated 4 examination blocks of 120 minutes each.', {$q($json(['blocks' => $examBlocks]))}, $registrar, '2026-08-20 10:05:00' FROM time_block_sets WHERE reference_no = 'TBS-2026-002';";
foreach (array_merge($classBlocks, $examBlocks) as $i => $blk) {
    $out[] = "INSERT INTO time_block_history (time_block_id, action, detail, changed_by, created_at) SELECT id, 'Block created', " . $q("Time block $blk created (" . substr($blockTimes[$blk][0], 0, 5) . '-' . substr($blockTimes[$blk][1], 0, 5) . ').') . ", $registrar, '2026-08-20 10:" . sprintf('%02d', 1 + $i) . ":00' FROM time_blocks WHERE code = '$blk';";
}

// ---------- Previous term (source for the Schedule Cloning Tool) and a demo clone ----------
const PREV_AY = '2025-2026';
const NEXT_AY = '2026-2027';
const TERM2 = '2nd Semester';
$term2Subjects = [
    ['IT103', 'Computer Programming 2', 3, 'Major', 2, 3, 'BSIT', 1, ['BCP-F-1005', 'BCP-F-1007']],
    ['IT104', 'Discrete Mathematics', 3, 'Major', 3, 0, 'BSIT', 1, ['BCP-F-1004', 'BCP-F-1003']],
    ['GE106', 'The Contemporary World', 3, 'GE', 3, 0, 'ALL', 1, $ge],
    ['GE107', 'Art Appreciation', 3, 'GE', 3, 0, 'ALL', 1, $ge],
    ['PE102', 'PATHFit 2: Exercise-based Fitness Activities', 2, 'PE', 2, 0, 'ALL', 1, ['BCP-F-1011']],
    ['NSTP2', 'National Service Training Program 2', 3, 'Minor', 3, 0, 'ALL', 1, $ge],
    ['IT204', 'Information Management', 3, 'Major', 2, 3, 'BSIT', 2, ['BCP-F-1012', 'BCP-F-1003']],
    ['IT205', 'Quantitative Methods', 3, 'Major', 3, 0, 'BSIT', 2, ['BCP-F-1004', 'BCP-F-1002']],
    ['IT206', 'Integrative Programming and Technologies', 3, 'Major', 2, 3, 'BSIT', 2, ['BCP-F-1007', 'BCP-F-1005']],
    ['IT305', 'Web Systems and Technologies', 3, 'Major', 2, 3, 'BSIT', 3, ['BCP-F-1006', 'BCP-F-1007']],
    ['IT306', 'Human-Computer Interaction', 3, 'Major', 3, 0, 'BSIT', 3, ['BCP-F-1002', 'BCP-F-1004']],
    ['IT307', 'Information Assurance and Security 2', 3, 'Major', 2, 3, 'BSIT', 3, ['BCP-F-1002', 'BCP-F-1012']],
];
foreach ($term2Subjects as $s) {
    $subjectByCode[$s[0]] = $s;
}
$term2Sections = [
    ['BSIT-1A', 'BSIT 1-A', 'BSIT', 1, 45, 44, 'BCP-F-1006', ['IT103', 'IT104', 'GE106', 'GE107', 'PE102', 'NSTP2']],
    ['BSIT-2A', 'BSIT 2-A', 'BSIT', 2, 45, 40, 'BCP-F-1012', ['IT204', 'IT205', 'IT206', 'GE106']],
    ['BSIT-3A', 'BSIT 3-A', 'BSIT', 3, 45, 37, 'BCP-F-1002', ['IT305', 'IT306', 'IT307']],
];
$out[] = '';
$out[] = 'INSERT IGNORE INTO subjects (code, name, units, subject_type, lecture_hours, lab_hours, program, year_level, semester) VALUES';
$out[] = implode(",\n", array_map(static fn ($s) => "({$q($s[0])}, {$q($s[1])}, {$s[2]}, {$q($s[3])}, {$s[4]}, {$s[5]}, {$q($s[6])}, {$s[7]}, '" . TERM2 . "')", $term2Subjects)) . ';';
foreach ($term2Subjects as $s) {
    foreach ($s[8] as $emp) {
        $out[] = 'INSERT IGNORE INTO teacher_subject_qualifications (teacher_id, subject_id, specialization_label, verified_at) SELECT t.id, s.id, ' . $q($s[1]) . ", NOW() FROM teachers t, subjects s WHERE t.employee_no = '$emp' AND s.code = '{$s[0]}';";
    }
}
$termSecWhere = static fn (string $alias, string $code, string $ay): string => "$alias.code = '$code' AND $alias.academic_year = '$ay' AND $alias.semester = '" . TERM2 . "'";
foreach ([PREV_AY, NEXT_AY] as $ay) {
    $parts = [];
    foreach ($term2Sections as $s) {
        $parts[] = "SELECT {$q($s[0])}, {$q($s[1])}, {$q($s[2])}, {$s[3]}, '" . TERM2 . "', '$ay', {$s[4]}, {$s[5]}, {$q($teacherName[$s[6]])}, (SELECT id FROM teachers WHERE employee_no = '{$s[6]}'), " . ($ay === PREV_AY ? "'Inactive'" : "'Active'");
    }
    $out[] = 'INSERT IGNORE INTO sections (code, name, program, year_level, semester, academic_year, max_students, current_students, advisor_name, advisor_teacher_id, status)';
    $out[] = implode("\nUNION ALL ", $parts) . ';';
    foreach ($term2Sections as $s) {
        foreach ($s[7] as $sub) {
            $out[] = "INSERT IGNORE INTO section_subjects (section_id, subject_id, status) SELECT sec.id, sub.id, " . "'Assigned'" . " FROM sections sec, subjects sub WHERE {$termSecWhere('sec', $s[0], $ay)} AND sub.code = '$sub';";
        }
    }
}
foreach ([PREV_AY, NEXT_AY] as $ay) {
    foreach ($weekdays as $day) {
        foreach ($teachers as $t) {
            $out[] = "INSERT INTO teacher_availability (teacher_id, day_of_week, start_time, end_time, availability, academic_year, semester) SELECT id, '$day', '07:00:00', '19:00:00', 'Available', '$ay', '" . TERM2 . "' FROM teachers WHERE employee_no = '{$t[0]}';";
        }
    }
}
// Place the previous term's schedule in its own term (different term = no overlap with 1st semester).
$savedPlaced = $placed;
$savedLoad = $load;
$placed = [];
$load = [];
$prevEntries = [];
$cursor = 3;
foreach ($term2Sections as $sec) {
    foreach ($sec[7] as $subCode) {
        $sub = $subjectByCode[$subCode];
        $roomPool = $sub[3] === 'PE' ? ['GYM'] : ($subCode === 'NSTP2' ? ['AVR-1'] : array_keys(array_filter($roomByCode, static fn ($r) => $r[2] === ($sub[5] > 0 ? 'Laboratory' : 'Lecture') && $r[4] === 'Available' && $r[3] >= $sec[5])));
        $slots = [];
        foreach ($weekdays as $d) {
            foreach ($classBlocks as $b) {
                $slots[] = [$d, $b];
            }
        }
        $slots = array_merge(array_slice($slots, $cursor % count($slots)), array_slice($slots, 0, $cursor % count($slots)));
        $cursor += 7;
        $done = false;
        foreach ($slots as [$day, $blk]) {
            [$st, $en] = $blockTimes[$blk];
            foreach ($byLoad($sub[8]) as $t) {
                if (($load[$t] ?? 0) + $sub[2] > 24 || !$teacherOk($t, $day, $st, $en)) {
                    continue;
                }
                foreach ($roomPool as $room) {
                    if ($clash(null, $day, $st, $en, $sec[0], $t, $room)) {
                        continue;
                    }
                    $placed[] = [null, $day, $st, $en, $sec[0], $t, $room];
                    $load[$t] = ($load[$t] ?? 0) + $sub[2];
                    $prevEntries[] = [$sec[0], $subCode, $t, $room, $blk, $day, $sub[5] > 0 ? 'Laboratory' : 'Lecture'];
                    $done = true;
                    break 3;
                }
            }
        }
        if (!$done) {
            fwrite(STDERR, "Could not place previous-term class {$sec[0]}/$subCode\n");
            exit(1);
        }
    }
}
$placed = $savedPlaced;
$load = $savedLoad;
$termEntrySql = static function (array $e, string $ay, string $status, string $source, ?string $remarks) use ($blockTimes, $termSecWhere, $q): string {
    [$sec, $sub, $t, $room, $blk, $day, $classType] = $e;
    [$st, $en] = $blockTimes[$blk];
    return 'INSERT INTO schedule_entries (section_id, subject_id, teacher_id, room_id, time_block_id, day_of_week, start_time, end_time, class_type, status, source, academic_year, semester, remarks)'
        . " SELECT sec.id, sub.id, t.id, r.id, b.id, '$day', '$st', '$en', '$classType', '$status', '$source', '$ay', '" . TERM2 . "', " . $q($remarks)
        . " FROM sections sec, subjects sub, teachers t, rooms r, time_blocks b WHERE {$termSecWhere('sec', $sec, $ay)} AND sub.code = '$sub' AND t.employee_no = '$t' AND r.room_code = '$room' AND b.code = '$blk';";
};
$out[] = '';
foreach ($prevEntries as $e) {
    $out[] = $termEntrySql($e, PREV_AY, 'Published', 'Manual', null);
}
// A finished clone: BSIT-1A's 2nd semester schedule copied from AY 2025-2026 to AY 2026-2027 as Draft.
$cloneEntries = array_values(array_filter($prevEntries, static fn ($e) => $e[0] === 'BSIT-1A'));
foreach ($cloneEntries as $e) {
    $out[] = $termEntrySql($e, NEXT_AY, 'Draft', 'Cloned', 'Cloned from AY 2025-2026 via CLN-2026-0001');
}
$options = $json(['copy_subjects' => true, 'copy_time_slots' => true, 'copy_faculty' => true, 'copy_rooms' => true, 'copy_class_types' => true]);
$out[] = 'INSERT IGNORE INTO schedule_clone_batches (reference_no, schedule_name, schedule_type, source_section_id, target_section_id, source_academic_year, source_semester, target_academic_year, target_semester, options_json, status, cloned_count, validated_at, created_by, created_at)'
    . " SELECT 'CLN-2026-0001', 'BSIT 1-A 2nd Semester schedule (from AY 2025-2026)', 'Regular', src.id, dst.id, '" . PREV_AY . "', '" . TERM2 . "', '" . NEXT_AY . "', '" . TERM2 . "', {$q($options)}, 'Validated', " . count($cloneEntries) . ", '2026-09-25 16:12:00', $registrar, '2026-09-25 16:05:00'"
    . " FROM sections src, sections dst WHERE {$termSecWhere('src', 'BSIT-1A', PREV_AY)} AND {$termSecWhere('dst', 'BSIT-1A', NEXT_AY)};";
$out[] = 'INSERT IGNORE INTO schedule_clone_batch_entries (clone_batch_id, source_entry_id, cloned_entry_id)'
    . " SELECT b.id, s.id, c.id FROM schedule_clone_batches b"
    . ' JOIN schedule_entries s ON s.section_id = b.source_section_id AND s.academic_year = b.source_academic_year AND s.semester = b.source_semester'
    . ' JOIN schedule_entries c ON c.section_id = b.target_section_id AND c.academic_year = b.target_academic_year AND c.semester = b.target_semester'
    . "  AND c.subject_id = s.subject_id AND c.day_of_week = s.day_of_week AND c.start_time = s.start_time WHERE b.reference_no = 'CLN-2026-0001';";
foreach ([
    ['Clone preview generated', null, 'Draft', 'Previewed ' . count($cloneEntries) . ' classes with subjects, time slots, faculty, rooms and class types copied.', '2026-09-25 16:05:00'],
    ['Draft schedule created', 'Draft', 'Draft', count($cloneEntries) . ' draft classes created for BSIT 1-A, AY 2026-2027 2nd Semester.', '2026-09-25 16:06:00'],
    ['Validated', 'Draft', 'Validated', 'Conflict Checker found no conflicts in the cloned schedule.', '2026-09-25 16:12:00'],
] as [$action, $from, $to, $detail, $at]) {
    $out[] = "INSERT INTO schedule_clone_history (clone_batch_id, action, from_status, to_status, detail, changed_by, changed_at) SELECT id, {$q($action)}, {$q($from)}, {$q($to)}, {$q($detail)}, $registrar, '$at' FROM schedule_clone_batches WHERE reference_no = 'CLN-2026-0001';";
}

// ---------- Teacher Schedule Mapping history (read from activity_logs) ----------
$out[] = '';
$mapIndex = 0;
foreach ($entries as $e) {
    [$sec, $sub, $t, $room, $blk, $day] = $e;
    if (!in_array($sec, ['BSIT-1A', 'BSIT-2A', 'BSIT-3A'], true)) {
        continue;
    }
    [$st, $en] = $blockTimes[$blk];
    $at = date('Y-m-d H:i:s', strtotime('2026-08-24 09:00:00') + $mapIndex * 1500);
    $out[] = "INSERT INTO activity_logs (user_id, user_name, role_key, action, module_key, detail, ip_address, user_agent, created_at) SELECT u.id, u.full_name, u.role_key, 'Save schedule', 'scheduling', CONCAT('Teacher mapping #', e.id, ' for $sec. $sub assigned to ', t.full_name, ' on $day " . substr($st, 0, 5) . '-' . substr($en, 0, 5) . " in $room.'), '127.0.0.1', 'Demo seed', '$at'"
        . " FROM users u, schedule_entries e JOIN sections sec ON sec.id = e.section_id JOIN subjects sub ON sub.id = e.subject_id JOIN teachers t ON t.id = e.teacher_id WHERE u.username = 'registrar' AND {$secWhere($sec)} AND sub.code = '$sub' AND e.day_of_week = '$day' AND e.status = 'Published';";
    $mapIndex++;
}

file_put_contents(dirname(__DIR__) . '/database/demo-scheduling-seed.sql', implode("\n", $base) . "\n");
file_put_contents(dirname(__DIR__) . '/database/demo-scheduling-seed-extra.sql', implode("\n", $out) . "\n");
ksort($load);
printf("classes: %d, exams: %d, special: %d, demo clash in %s on %s %s\nloads (max %d): %s\n",
    count($entries), count($exams), count($specials), $demoClash[3], $demoClash[5], $demoClash[4], MAX_LOAD,
    implode(' ', array_map(static fn ($k, $v) => substr($k, -4) . '=' . $v, array_keys($load), $load)));
