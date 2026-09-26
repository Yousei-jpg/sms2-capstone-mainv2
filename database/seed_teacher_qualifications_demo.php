<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Demo-only teacher qualification mappings.
 *
 * The seed resolves teachers and subjects by their stable codes instead of
 * assuming database IDs. It is safe to rerun because the qualification table
 * has a unique teacher/subject key and this script uses an upsert.
 */
$mappings = [
    'T001' => [
        'IT301' => 'Database and Information Systems',
        'CS301' => 'Algorithms and Data Structures',
        'IT201' => 'Object-Oriented Programming',
    ],
    'T002' => [
        'IT301' => 'Database Administration',
        'IT304' => 'Capstone and Systems Research',
        'CS301' => 'Algorithms and Programming',
    ],
    'T003' => [
        'IT302' => 'Web Application Development',
        'IT201' => 'Object-Oriented Programming',
        'IT304' => 'Software Project Development',
    ],
    'T004' => [
        'GE301' => 'Communication and Academic Writing',
        'GE302' => 'Philippine History and Rizal Studies',
        'PE301' => 'Physical Fitness and Wellness',
    ],
    'T005' => [
        'IT303' => 'Computer Networks',
        'CS302' => 'Operating Systems',
        'IT202' => 'Industrial Electronics',
    ],
    'T006' => [
        'IT302' => 'Web and User Interface Development',
        'IT201' => 'Object-Oriented Programming',
        'CS301' => 'Algorithms and Programming',
    ],
    'T007' => [
        'CS301' => 'Algorithms and Data Structures',
        'CS303' => 'Theory of Computation',
        'CS302' => 'Operating Systems',
    ],
    'T008' => [
        'IT301' => 'Database Systems',
        'IT302' => 'Full-Stack Web Development',
        'IT304' => 'Capstone Project Mentoring',
    ],
    'T009' => [
        'IT303' => 'Network Administration',
        'CS302' => 'Systems Administration',
        'IT202' => 'Electronics and Hardware Systems',
    ],
    'T-010' => [
        'CS302' => 'Operating Systems',
        'CS303' => 'Automata and Formal Languages',
        'IT303' => 'Computer Networks',
    ],
    'T-011' => [
        'IT304' => 'Capstone and Research Methods',
        'CS301' => 'Algorithms and Data Structures',
        'IT301' => 'Database Systems',
    ],
    'T-012' => [
        'IT201' => 'Object-Oriented Programming',
        'IT202' => 'Industrial Electronics',
        'IT303' => 'Network Fundamentals',
    ],
    'T-013' => [
        'CS303' => 'Automata and Computability',
        'CS302' => 'Operating Systems',
        'CS301' => 'Algorithms and Data Structures',
    ],
];

$pdo = getDatabaseConnection();
$pdo->beginTransaction();

try {
    $teacherStatement = $pdo->prepare(
        'SELECT id FROM teachers WHERE employee_no = :employee_no AND status = \'Active\' LIMIT 1'
    );
    $subjectStatement = $pdo->prepare(
        'SELECT id FROM subjects WHERE code = :code AND status = \'Active\' LIMIT 1'
    );
    $upsertStatement = $pdo->prepare(
        "INSERT INTO teacher_subject_qualifications
            (teacher_id, subject_id, specialization_label, status)
         VALUES
            (:teacher_id, :subject_id, :specialization_label, 'Active')
         ON DUPLICATE KEY UPDATE
            specialization_label = VALUES(specialization_label),
            status = 'Active'"
    );

    $written = 0;
    $skipped = [];

    foreach ($mappings as $employeeNumber => $subjects) {
        $teacherStatement->execute(['employee_no' => $employeeNumber]);
        $teacherId = $teacherStatement->fetchColumn();

        if ($teacherId === false) {
            $skipped[] = "Teacher {$employeeNumber} was not found or is inactive.";
            continue;
        }

        foreach ($subjects as $subjectCode => $specializationLabel) {
            $subjectStatement->execute(['code' => $subjectCode]);
            $subjectId = $subjectStatement->fetchColumn();

            if ($subjectId === false) {
                $skipped[] = "Subject {$subjectCode} was not found or is inactive.";
                continue;
            }

            $upsertStatement->execute([
                'teacher_id' => (int)$teacherId,
                'subject_id' => (int)$subjectId,
                'specialization_label' => $specializationLabel,
            ]);
            $written++;
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Demo teacher qualifications are configured.',
        'records_processed' => $written,
        'skipped' => $skipped,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
