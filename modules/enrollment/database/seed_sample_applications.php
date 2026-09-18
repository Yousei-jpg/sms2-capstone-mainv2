<?php
/**
 * SMS 2 - Sample Pre-Registration Applications  [TEST DATA ONLY]
 *
 * ============================================================================
 * THIS SCRIPT INSERTS FICTIONAL APPLICANTS. THEY ARE NOT REAL RECORDS.
 * Do not run it on a live BCP database. Every row it creates carries the
 * reference prefix defined below so they can all be removed again with --purge.
 * ============================================================================
 *
 * Purpose: the Registrar workflow cannot be tested until applications exist,
 * and the applicant-facing submission form is a later task. These rows give
 * the queue something to review.
 *
 * Each sample is deliberately shaped to exercise a different validation path,
 * so the checklist can be seen passing, warning, and failing.
 *
 * CLI:  php modules/enrollment/database/seed_sample_applications.php
 *       php modules/enrollment/database/seed_sample_applications.php --purge
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__) . '/config/enrollment.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Run from CLI only.\n";
    exit(1);
}

/** Sample rows use this prefix so they are identifiable and removable. */
const SAMPLE_PREFIX = 'SAMPLE-';

function seedOut(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    seedOut('FAILED: ' . $e->getMessage());
    exit(1);
}

$check = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?'
);
$check->execute(['enr_applications']);
if ((int) $check->fetchColumn() === 0) {
    seedOut('FAILED: the Enrollment schema is not installed.');
    seedOut('Run: php modules/enrollment/database/install_enrollment.php');
    exit(1);
}

// --- Purge mode -------------------------------------------------------------
if (in_array('--purge', $argv ?? [], true)) {
    $stmt = $pdo->prepare('DELETE FROM enr_applications WHERE reference_no LIKE ?');
    $stmt->execute([SAMPLE_PREFIX . '%']);
    seedOut('Removed ' . $stmt->rowCount() . ' sample application(s).');
    seedOut('Real applications were not touched: only references starting with '
        . SAMPLE_PREFIX . ' are deleted.');
    exit(0);
}

// --- Look up reference data -------------------------------------------------
$programs = [];
foreach ($pdo->query('SELECT id, code FROM enr_programs')->fetchAll() as $row) {
    $programs[(string) $row['code']] = (int) $row['id'];
}

if (!$programs) {
    seedOut('FAILED: enr_programs is empty. Run install_enrollment.php first.');
    exit(1);
}

$periodId = (int) ($pdo->query(
    'SELECT id FROM enr_academic_periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
)->fetchColumn() ?: 0);

$inactivePeriodId = (int) ($pdo->query(
    'SELECT id FROM enr_academic_periods WHERE is_active = 0 ORDER BY id LIMIT 1'
)->fetchColumn() ?: $periodId);

if ($periodId === 0) {
    seedOut('FAILED: no academic period found. Run install_enrollment.php first.');
    exit(1);
}

/**
 * Each entry notes which validation outcome it is built to produce, so the
 * checklist can be seen doing real work rather than always passing.
 */
$samples = [
    [
        'note' => 'complete record — every rule should PASS except the two document rules',
        'data' => [
            'applicant_type' => 'Freshman', 'status' => ENR_STATUS_SUBMITTED,
            'first_name' => 'Andrea', 'middle_name' => 'Reyes', 'last_name' => 'Bautista',
            'birth_date' => '2008-03-14', 'sex' => 'Female', 'civil_status' => 'Single',
            'email' => 'andrea.bautista@sample.invalid', 'mobile_no' => '+63 917 000 0101',
            'current_address' => '12 Quirino Ave, Novaliches, Quezon City',
            'previous_school' => 'Novaliches Senior High School', 'school_type' => 'Public',
            'year_graduated' => 2026, 'grade_average' => 91.40, 'previous_program' => 'STEM',
            'program' => 'BSIT', 'entry_level' => '1st Year', 'period' => 'active',
            'campus' => 'Main Campus', 'preferred_section' => null,
        ],
    ],
    [
        'note' => 'missing mobile and address — applicant-info rule should FAIL',
        'data' => [
            'applicant_type' => 'Freshman', 'status' => ENR_STATUS_SUBMITTED,
            'first_name' => 'Marco', 'middle_name' => null, 'last_name' => 'Villanueva',
            'birth_date' => '2007-11-02', 'sex' => 'Male', 'civil_status' => 'Single',
            'email' => 'marco.villanueva@sample.invalid', 'mobile_no' => null,
            'current_address' => null,
            'previous_school' => 'Bestlink Senior High', 'school_type' => 'Private',
            'year_graduated' => 2026, 'grade_average' => 88.00, 'previous_program' => 'ABM',
            'program' => 'BSBA', 'entry_level' => '1st Year', 'period' => 'active',
            'campus' => 'Main Campus', 'preferred_section' => null,
        ],
    ],
    [
        'note' => 'transferee with no previous program — applicant-type rule should WARN',
        'data' => [
            'applicant_type' => 'Transferee', 'status' => ENR_STATUS_SUBMITTED,
            'first_name' => 'Kristine', 'middle_name' => 'Lopez', 'last_name' => 'Ramos',
            'birth_date' => '2005-06-21', 'sex' => 'Female', 'civil_status' => 'Single',
            'email' => 'kristine.ramos@sample.invalid', 'mobile_no' => '+63 918 000 0303',
            'current_address' => '88 Mindanao Ave, Quezon City',
            'previous_school' => 'Metro Manila College', 'school_type' => 'Private',
            'year_graduated' => 2025, 'grade_average' => 86.75, 'previous_program' => null,
            'program' => 'BSCS', 'entry_level' => '2nd Year', 'period' => 'active',
            'campus' => 'Main Campus', 'preferred_section' => 'BSCS-2A',
        ],
    ],
    [
        'note' => 'incomplete academic background — academic-info rule should FAIL',
        'data' => [
            'applicant_type' => 'Returning', 'status' => ENR_STATUS_SUBMITTED,
            'first_name' => 'Paolo', 'middle_name' => 'Cruz', 'last_name' => 'Mendoza',
            'birth_date' => '2004-01-09', 'sex' => 'Male', 'civil_status' => 'Single',
            'email' => 'paolo.mendoza@sample.invalid', 'mobile_no' => '+63 919 000 0404',
            'current_address' => '5 Susano Rd, Caloocan City',
            'previous_school' => null, 'school_type' => null,
            'year_graduated' => null, 'grade_average' => null, 'previous_program' => 'BSIT',
            'program' => 'BSIT', 'entry_level' => '3rd Year', 'period' => 'active',
            'campus' => 'Main Campus', 'preferred_section' => null,
        ],
    ],
    [
        'note' => 'inactive academic period — academic-period rule should WARN',
        'data' => [
            'applicant_type' => 'Freshman', 'status' => ENR_STATUS_SUBMITTED,
            'first_name' => 'Jasmine', 'middle_name' => 'Torres', 'last_name' => 'Aquino',
            'birth_date' => '2008-08-30', 'sex' => 'Female', 'civil_status' => 'Single',
            'email' => 'jasmine.aquino@sample.invalid', 'mobile_no' => '+63 920 000 0505',
            'current_address' => '40 Zabarte Rd, Caloocan City',
            'previous_school' => 'Zabarte Senior High School', 'school_type' => 'Public',
            'year_graduated' => 2026, 'grade_average' => 89.20, 'previous_program' => 'HUMSS',
            'program' => 'BSED', 'entry_level' => '1st Year', 'period' => 'inactive',
            'campus' => 'Main Campus', 'preferred_section' => null,
        ],
    ],
    [
        'note' => 'already returned to the applicant — sits in the For Correction tab',
        'data' => [
            'applicant_type' => 'Freshman', 'status' => ENR_STATUS_FOR_CORRECTION,
            'first_name' => 'Daniel', 'middle_name' => 'Santos', 'last_name' => 'Garcia',
            'birth_date' => '2007-04-17', 'sex' => 'Male', 'civil_status' => 'Single',
            'email' => 'daniel.garcia@sample.invalid', 'mobile_no' => '+63 921 000 0606',
            'current_address' => '17 Camarin Rd, Caloocan City',
            'previous_school' => 'Camarin High School', 'school_type' => 'Public',
            'year_graduated' => 2026, 'grade_average' => 84.10, 'previous_program' => 'GAS',
            'program' => 'BSHM', 'entry_level' => '1st Year', 'period' => 'active',
            'campus' => 'Main Campus', 'preferred_section' => null,
        ],
    ],
];

$insert = $pdo->prepare(
    'INSERT INTO enr_applications
        (reference_no, applicant_type, status,
         first_name, middle_name, last_name, birth_date, sex, civil_status,
         email, mobile_no, current_address,
         previous_school, school_type, year_graduated, grade_average, previous_program,
         program_id, academic_period_id, entry_level, campus, preferred_section,
         submitted_at)
     VALUES
        (:reference_no, :applicant_type, :status,
         :first_name, :middle_name, :last_name, :birth_date, :sex, :civil_status,
         :email, :mobile_no, :current_address,
         :previous_school, :school_type, :year_graduated, :grade_average, :previous_program,
         :program_id, :academic_period_id, :entry_level, :campus, :preferred_section,
         :submitted_at)'
);

$existing = $pdo->prepare('SELECT COUNT(*) FROM enr_applications WHERE reference_no = ?');

seedOut('Inserting sample pre-registration applications (TEST DATA).');
seedOut('');

$created = 0;
$skipped = 0;
$sequence = 1;

foreach ($samples as $sample) {
    $data = $sample['data'];
    $reference = SAMPLE_PREFIX . date('Y') . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    $sequence++;

    $existing->execute([$reference]);
    if ((int) $existing->fetchColumn() > 0) {
        seedOut('  SKIP  ' . $reference . ' already exists');
        $skipped++;
        continue;
    }

    $programId = $programs[$data['program']] ?? null;
    if ($programId === null) {
        seedOut('  SKIP  ' . $reference . ': program ' . $data['program'] . ' not found');
        $skipped++;
        continue;
    }

    try {
        $insert->execute([
            ':reference_no'       => $reference,
            ':applicant_type'     => $data['applicant_type'],
            ':status'             => $data['status'],
            ':first_name'         => $data['first_name'],
            ':middle_name'        => $data['middle_name'],
            ':last_name'          => $data['last_name'],
            ':birth_date'         => $data['birth_date'],
            ':sex'                => $data['sex'],
            ':civil_status'       => $data['civil_status'],
            ':email'              => $data['email'],
            ':mobile_no'          => $data['mobile_no'],
            ':current_address'    => $data['current_address'],
            ':previous_school'    => $data['previous_school'],
            ':school_type'        => $data['school_type'],
            ':year_graduated'     => $data['year_graduated'],
            ':grade_average'      => $data['grade_average'],
            ':previous_program'   => $data['previous_program'],
            ':program_id'         => $programId,
            ':academic_period_id' => $data['period'] === 'active' ? $periodId : $inactivePeriodId,
            ':entry_level'        => $data['entry_level'],
            ':campus'             => $data['campus'],
            ':preferred_section'  => $data['preferred_section'],
            ':submitted_at'       => date('Y-m-d H:i:s', strtotime('-' . $sequence . ' days')),
        ]);
        $created++;
        seedOut('  OK    ' . $reference . ' — ' . $sample['note']);
    } catch (Throwable $e) {
        $skipped++;
        seedOut('  ERROR ' . $reference . ': ' . $e->getMessage());
    }
}

seedOut('');
seedOut($created . ' sample application(s) created, ' . $skipped . ' skipped.');
seedOut('');
seedOut('All sample references begin with "' . SAMPLE_PREFIX . '" so they are easy to spot');
seedOut('in the queue and easy to remove:');
seedOut('  php modules/enrollment/database/seed_sample_applications.php --purge');
