<?php
declare(strict_types=1);

/**
 * SMS 2 - Section Assignment API
 * Module: Class Scheduling
 *
 * GET   returns reference data through the PROVIDER CONTRACTS, plus the
 *       section-subject assignments that Scheduling itself owns.
 * POST  saves a section and its subject assignments.
 *
 * Reference data (programs, subjects, academic years, semesters, student
 * counts) is never queried directly here. It is requested from a provider,
 * so the owning module can be swapped in later without touching this file.
 */

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/scheduling/provider/bootstrap.php';

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

$pdo = getDatabaseConnection();

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Sections, enriched with the subject codes the scheduling module has
 * assigned. The section records come from Enrollment's provider; the
 * assignments come from scheduling-owned data.
 */
function fetchSections(PDO $pdo): array
{
    $sections = scheduling_provider('enrollment')->getSections();

    $assignments = $pdo->query(
        "SELECT ss.section_id, sub.code
         FROM section_subjects ss
         JOIN subjects sub ON sub.id = ss.subject_id
         WHERE ss.status = 'Assigned'
         ORDER BY sub.code ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $bySection = [];
    foreach ($assignments as $row) {
        $bySection[(int)$row['section_id']][] = $row['code'];
    }

    foreach ($sections as &$section) {
        $section['subjects'] = $bySection[(int)$section['id']] ?? [];
    }
    unset($section);

    /* Most recently updated first, matching the Recent Sections panel. */
    usort($sections, static function (array $a, array $b): int {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });

    return $sections;
}

try {
    /* ============================= GET ============================= */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $curriculum = scheduling_provider('curriculum');
        $registrar  = scheduling_provider('registrar');

        echo json_encode([
            'ok'       => true,
            'sections' => fetchSections($pdo),

            /* --- supplied by Curriculum & Subject Management --- */
            'subjects'       => $curriculum->getSubjects(),
            'programs'       => $curriculum->getPrograms(),
            'year_levels'    => $curriculum->getYearLevels(),
            'max_units'      => $curriculum->getMaxUnitsPerSection(),

            /* --- supplied by Registrar --- */
            'academic_years' => $registrar->getAcademicYears(),
            'semesters'      => $registrar->getSemesters(),
            'current_term'   => $registrar->getCurrentTerm(),

            /* --- provenance, for the Data Sources panel --- */
            'data_sources'   => scheduling_provider_manifest(),
            'owned_data'     => scheduling_owned_data(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    /* ============================= POST ============================ */
    $payload = jsonBody();

    $id           = isset($payload['id']) && $payload['id'] !== '' ? (int)$payload['id'] : null;
    $code         = strtoupper(trim((string)($payload['code'] ?? '')));
    $name         = trim((string)($payload['name'] ?? ''));
    $program      = trim((string)($payload['program'] ?? ''));
    $yearLevel    = (int)($payload['year_level'] ?? 0);
    $semester     = trim((string)($payload['semester'] ?? ''));
    $academicYear = trim((string)($payload['academic_year'] ?? ''));
    $maxStudents  = (int)($payload['max_students'] ?? 0);
    $advisor      = trim((string)($payload['advisor_name'] ?? ''));
    $subjects     = $payload['subjects'] ?? [];

    if (!is_array($subjects)) {
        $subjects = [];
    }
    $subjects = array_values(array_unique(array_filter(array_map(
        static fn($value) => strtoupper(trim((string)$value)),
        $subjects
    ))));

    /* ---------- required fields ---------- */
    if ($code === '' || $name === '' || $program === '' || $yearLevel <= 0
        || $semester === '' || $academicYear === '') {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error' => 'Section name, code, program, year level, semester and academic year are required.']);
        exit;
    }

    if ($maxStudents < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error' => 'Section capacity must be at least 1.']);
        exit;
    }

    if (count($subjects) === 0) {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error' => 'Assign at least one subject before saving.']);
        exit;
    }

    /* ---------- academic period must be one Registrar recognises ---------- */
    $registrar = scheduling_provider('registrar');
    if (!in_array($semester, $registrar->getSemesters(), true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid semester.']);
        exit;
    }

    /* ---------- duplicate section within the same academic period ---------- */
    $dupSql = "SELECT id FROM sections
               WHERE code = :code AND academic_year = :academic_year AND semester = :semester";
    $dupParams = ['code' => $code, 'academic_year' => $academicYear, 'semester' => $semester];
    if ($id !== null) {
        $dupSql .= " AND id <> :id";
        $dupParams['id'] = $id;
    }
    $dupStmt = $pdo->prepare($dupSql . " LIMIT 1");
    $dupStmt->execute($dupParams);
    if ($dupStmt->fetch()) {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error' => 'Section ' . $code . ' already exists for ' . $academicYear . ' ' . $semester . '.']);
        exit;
    }

    /* ---------- subject eligibility, decided by Curriculum ---------- */
    $curriculum = scheduling_provider('curriculum');
    $eligible   = $curriculum->getEligibleSubjects($program, $yearLevel, $semester);

    $eligibleByCode = [];
    foreach ($eligible as $subject) {
        $eligibleByCode[$subject['code']] = $subject;
    }

    $invalid    = [];
    $totalUnits = 0.0;
    $subjectIds = [];

    foreach ($subjects as $subjectCode) {
        if (!isset($eligibleByCode[$subjectCode])) {
            $invalid[] = $subjectCode;
            continue;
        }
        $totalUnits  += (float)$eligibleByCode[$subjectCode]['units'];
        $subjectIds[] = (int)$eligibleByCode[$subjectCode]['id'];
    }

    if (count($invalid) > 0) {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error' => 'Not valid for this section: ' . implode(', ', $invalid)]);
        exit;
    }

    /* ---------- curriculum unit ceiling ---------- */
    $maxUnits = $curriculum->getMaxUnitsPerSection();
    if ($maxUnits > 0 && $totalUnits > $maxUnits) {
        http_response_code(422);
        echo json_encode(['ok' => false,
            'error' => 'Total units (' . $totalUnits . ') exceed the maximum of ' . $maxUnits . ' units per section.']);
        exit;
    }

    /* ---------- persist ---------- */
    $createdBy = function_exists('getCurrentUserId') ? getCurrentUserId() : null;

    $pdo->beginTransaction();

    if ($id === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO sections
                (code, name, program, year_level, semester, academic_year,
                 max_students, advisor_name, status, created_by)
             VALUES
                (:code, :name, :program, :year_level, :semester, :academic_year,
                 :max_students, :advisor_name, 'Active', :created_by)"
        );
        $stmt->execute([
            'code' => $code, 'name' => $name, 'program' => $program,
            'year_level' => $yearLevel, 'semester' => $semester,
            'academic_year' => $academicYear, 'max_students' => $maxStudents,
            'advisor_name' => $advisor !== '' ? $advisor : null,
            'created_by' => $createdBy,
        ]);
        $id = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare(
            "UPDATE sections
             SET code = :code, name = :name, program = :program,
                 year_level = :year_level, semester = :semester,
                 academic_year = :academic_year, max_students = :max_students,
                 advisor_name = :advisor_name
             WHERE id = :id"
        );
        $stmt->execute([
            'code' => $code, 'name' => $name, 'program' => $program,
            'year_level' => $yearLevel, 'semester' => $semester,
            'academic_year' => $academicYear, 'max_students' => $maxStudents,
            'advisor_name' => $advisor !== '' ? $advisor : null,
            'id' => $id,
        ]);
    }

    /* Replace the assignment set for this section. */
    $pdo->prepare("DELETE FROM section_subjects WHERE section_id = :section_id")
        ->execute(['section_id' => $id]);

    $insert = $pdo->prepare(
        "INSERT INTO section_subjects (section_id, subject_id, status, created_by)
         VALUES (:section_id, :subject_id, 'Assigned', :created_by)"
    );
    foreach ($subjectIds as $subjectId) {
        $insert->execute([
            'section_id' => $id,
            'subject_id' => $subjectId,
            'created_by' => $createdBy,
        ]);
    }

    $pdo->commit();

    if (function_exists('logActivity')) {
        logActivity('Save section', 'Saved section assignment ' . $code .
            ' (' . count($subjectIds) . ' subjects, ' . $totalUnits . ' units)', 'scheduling');
    }

    echo json_encode([
        'ok'          => true,
        'message'     => 'Section assignment saved successfully to the database.',
        'section'     => $id,
        'total_units' => $totalUnits,
        'sections'    => fetchSections($pdo),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Section Assignment API error: ' . $e->getMessage());
    $safeMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)
        ? $e->getMessage()
        : 'Server error. Please contact the administrator.';
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $safeMessage],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
