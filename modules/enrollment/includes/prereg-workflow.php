<?php
/**
 * SMS 2 - Online Pre-Registration Validation Rules
 *
 * Each rule returns PASS, WARNING, or FAIL together with a message that names
 * the actual problem. No rule is allowed to say "validation failed" without
 * saying what failed and what to do about it.
 *
 * FAIL    blocks approval.
 * WARNING allows approval but is recorded in the decision's checklist.
 * PASS    the rule is satisfied.
 */

declare(strict_types=1);

require_once __DIR__ . '/prereg-repository.php';

if (!defined('ENR_RULE_PASS')) {
    define('ENR_RULE_PASS', 'PASS');
    define('ENR_RULE_WARNING', 'WARNING');
    define('ENR_RULE_FAIL', 'FAIL');
}

/**
 * @return array{key:string, label:string, result:string, message:string}
 */
function enrRule(string $key, string $label, string $result, string $message): array
{
    return ['key' => $key, 'label' => $label, 'result' => $result, 'message' => $message];
}

/**
 * Rule 1 — applicant information complete.
 *
 * @param array<string, mixed> $application
 * @return array{key:string, label:string, result:string, message:string}
 */
function enrRuleApplicantInfo(array $application): array
{
    $required = [
        'first_name'      => 'First Name',
        'last_name'       => 'Last Name',
        'birth_date'      => 'Date of Birth',
        'sex'             => 'Sex',
        'email'           => 'Email Address',
        'mobile_no'       => 'Mobile Number',
        'current_address' => 'Current Address',
    ];

    $missing = [];
    foreach ($required as $field => $label) {
        if (trim((string) ($application[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    if ($missing) {
        return enrRule(
            'applicant_info',
            'Applicant information complete',
            ENR_RULE_FAIL,
            'The applicant left ' . count($missing) . ' required field'
                . (count($missing) === 1 ? '' : 's')
                . ' blank: ' . implode(', ', $missing)
                . '. Return the application for correction so these can be supplied.'
        );
    }

    $email = (string) $application['email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return enrRule(
            'applicant_info',
            'Applicant information complete',
            ENR_RULE_FAIL,
            'The email address "' . $email . '" is not a valid address, so the applicant '
                . 'cannot be notified. Return for correction.'
        );
    }

    return enrRule(
        'applicant_info',
        'Applicant information complete',
        ENR_RULE_PASS,
        'All required personal fields are filled in.'
    );
}

/**
 * Rule 2 — academic information complete.
 *
 * @param array<string, mixed> $application
 */
function enrRuleAcademicInfo(array $application): array
{
    $required = [
        'previous_school' => 'Previous School',
        'school_type'     => 'School Type',
        'year_graduated'  => 'Year Graduated',
    ];

    $missing = [];
    foreach ($required as $field => $label) {
        $value = trim((string) ($application[$field] ?? ''));
        if ($value === '' || $value === '0') {
            $missing[] = $label;
        }
    }

    if ($missing) {
        return enrRule(
            'academic_info',
            'Academic information complete',
            ENR_RULE_FAIL,
            'Academic background is incomplete. Missing: ' . implode(', ', $missing)
                . '. This is needed to confirm the applicant is eligible for the selected entry level.'
        );
    }

    $year = (int) $application['year_graduated'];
    $currentYear = (int) date('Y');
    if ($year > $currentYear) {
        return enrRule(
            'academic_info',
            'Academic information complete',
            ENR_RULE_WARNING,
            'Year graduated is ' . $year . ', which is in the future. Confirm with the applicant '
                . 'whether they are still completing their previous level.'
        );
    }

    $average = $application['grade_average'] ?? null;
    if ($average === null || $average === '') {
        return enrRule(
            'academic_info',
            'Academic information complete',
            ENR_RULE_WARNING,
            'No grade average was submitted. Approval is still possible, but the average '
                . 'may be required later for scholarship or placement decisions.'
        );
    }

    return enrRule(
        'academic_info',
        'Academic information complete',
        ENR_RULE_PASS,
        'Previous school, school type, year graduated, and grade average are all present.'
    );
}

/**
 * Rule 3 — the selected program is currently offered.
 *
 * @param array<string, mixed> $application
 */
function enrRuleProgramOffered(array $application): array
{
    $programId = (int) ($application['program_id'] ?? 0);

    if ($programId <= 0) {
        return enrRule(
            'program_offered',
            'Program is currently offered',
            ENR_RULE_FAIL,
            'No program was selected on this application. A program is required before '
                . 'the applicant can be placed into a section.'
        );
    }

    $provider = enrProgramProvider();
    $program = $provider->findProgram($programId);

    if ($program === null) {
        return enrRule(
            'program_offered',
            'Program is currently offered',
            ENR_RULE_FAIL,
            'The program on this application no longer exists in the program list. '
                . 'Return for correction so the applicant can choose from the current offerings.'
        );
    }

    if (!$provider->isOffered($programId)) {
        return enrRule(
            'program_offered',
            'Program is currently offered',
            ENR_RULE_FAIL,
            $program['code'] . ' (' . $program['name'] . ') is marked inactive and is not '
                . 'accepting applications for this academic period. Return for correction '
                . 'so the applicant can select an available program.'
        );
    }

    return enrRule(
        'program_offered',
        'Program is currently offered',
        ENR_RULE_PASS,
        $program['code'] . ' is active and accepting applications.'
    );
}

/**
 * Rule 4 — applicant type is valid, and consistent with the academic record.
 *
 * @param array<string, mixed> $application
 */
function enrRuleApplicantType(array $application): array
{
    $type = trim((string) ($application['applicant_type'] ?? ''));

    if ($type === '') {
        return enrRule(
            'applicant_type',
            'Applicant type is valid',
            ENR_RULE_FAIL,
            'No applicant type was recorded. Freshman, Transferee, Returning, and Second '
                . 'Courser follow different admission requirements, so this cannot be left blank.'
        );
    }

    if (!in_array($type, enrApplicantTypes(), true)) {
        return enrRule(
            'applicant_type',
            'Applicant type is valid',
            ENR_RULE_FAIL,
            '"' . $type . '" is not a recognised applicant type. Accepted values are: '
                . implode(', ', enrApplicantTypes()) . '.'
        );
    }

    // A transferee with no previous program is a data inconsistency worth flagging.
    $previousProgram = trim((string) ($application['previous_program'] ?? ''));
    if (in_array($type, ['Transferee', 'Second Courser'], true) && $previousProgram === '') {
        return enrRule(
            'applicant_type',
            'Applicant type is valid',
            ENR_RULE_WARNING,
            'The applicant is marked "' . $type . '" but no previous program was supplied. '
                . 'A previous program is normally needed to evaluate credited subjects.'
        );
    }

    return enrRule(
        'applicant_type',
        'Applicant type is valid',
        ENR_RULE_PASS,
        'Applicant type "' . $type . '" is recognised and consistent with the submitted record.'
    );
}

/**
 * Rule 5 — required documents submitted.
 *
 * The Document Upload Portal owns this. Until it exists, the rule reports a
 * truthful WARNING rather than a fabricated PASS.
 *
 * @param array<string, mixed> $application
 */
function enrRuleDocumentsSubmitted(array $application): array
{
    $provider = enrDocumentStatusProvider();

    if (!$provider->isAvailable()) {
        return enrRule(
            'documents_submitted',
            'Required documents submitted',
            ENR_RULE_WARNING,
            'Document submission cannot be confirmed from this screen. The Document Upload '
                . 'Portal is the subsystem that owns uploads and has not been implemented yet, '
                . 'so this rule cannot pass or fail. Verify the documents outside the system '
                . 'before approving.'
        );
    }

    $submitted = $provider->allRequiredSubmitted((int) $application['id']);

    if ($submitted === null) {
        return enrRule(
            'documents_submitted',
            'Required documents submitted',
            ENR_RULE_WARNING,
            'The Document Upload Portal did not return a submission status for this applicant.'
        );
    }

    if ($submitted === false) {
        return enrRule(
            'documents_submitted',
            'Required documents submitted',
            ENR_RULE_FAIL,
            'One or more required documents have not been uploaded. Mark the application '
                . 'incomplete so the applicant can supply them.'
        );
    }

    return enrRule(
        'documents_submitted',
        'Required documents submitted',
        ENR_RULE_PASS,
        'All required documents have been uploaded.'
    );
}

/**
 * Rule 6 — submitted documents verified.
 *
 * @param array<string, mixed> $application
 */
function enrRuleDocumentsVerified(array $application): array
{
    $provider = enrDocumentStatusProvider();

    if (!$provider->isAvailable()) {
        return enrRule(
            'documents_verified',
            'Required documents verified',
            ENR_RULE_WARNING,
            'Document verification is performed in the Document Upload Portal, which has not '
                . 'been implemented yet. This rule is reported as unknown rather than passed.'
        );
    }

    $verified = $provider->allVerified((int) $application['id']);

    if ($verified === null) {
        return enrRule(
            'documents_verified',
            'Required documents verified',
            ENR_RULE_WARNING,
            'The Document Upload Portal did not return a verification status for this applicant.'
        );
    }

    if ($verified === false) {
        return enrRule(
            'documents_verified',
            'Required documents verified',
            ENR_RULE_FAIL,
            'At least one uploaded document has not been verified or needs replacement. '
                . 'Resolve it in the Document Upload Portal before approving this application.'
        );
    }

    return enrRule(
        'documents_verified',
        'Required documents verified',
        ENR_RULE_PASS,
        'Every uploaded document has been verified.'
    );
}

/**
 * Rule 7 — no duplicate active application for the same person and period.
 *
 * @param array<string, mixed> $application
 */
function enrRuleNoDuplicate(PDO $pdo, array $application): array
{
    $applicationId = (int) $application['id'];
    $email = trim((string) ($application['email'] ?? ''));
    $periodId = (int) ($application['academic_period_id'] ?? 0);

    if ($email === '') {
        return enrRule(
            'no_duplicate',
            'No duplicate active application',
            ENR_RULE_WARNING,
            'Without an email address, a duplicate check cannot be performed reliably.'
        );
    }

    $activeStatuses = [
        ENR_STATUS_SUBMITTED,
        ENR_STATUS_FOR_REVIEW,
        ENR_STATUS_FOR_CORRECTION,
        ENR_STATUS_APPROVED,
        ENR_STATUS_PROCESSED,
    ];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));

    $sql = 'SELECT reference_no, status FROM enr_applications
            WHERE id <> ?
              AND email = ?
              AND status IN (' . $placeholders . ')';
    $params = [$applicationId, $email];

    if ($periodId > 0) {
        $sql .= ' AND academic_period_id = ?';
    }
    $params = array_merge($params, $activeStatuses);
    if ($periodId > 0) {
        $params[] = $periodId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $duplicate = $stmt->fetch();

    if ($duplicate) {
        return enrRule(
            'no_duplicate',
            'No duplicate active application',
            ENR_RULE_FAIL,
            'This applicant already has an active application for the same academic period: '
                . $duplicate['reference_no'] . ' (' . $duplicate['status'] . '). '
                . 'Close one of the two before approving, otherwise the applicant will be '
                . 'enrolled twice.'
        );
    }

    return enrRule(
        'no_duplicate',
        'No duplicate active application',
        ENR_RULE_PASS,
        'No other active application exists for this email and academic period.'
    );
}

/**
 * Rule 8 — the academic period is open.
 *
 * @param array<string, mixed> $application
 */
function enrRuleAcademicPeriod(array $application): array
{
    $periodId = (int) ($application['academic_period_id'] ?? 0);

    if ($periodId <= 0) {
        return enrRule(
            'academic_period',
            'Academic period is open',
            ENR_RULE_FAIL,
            'This application is not tied to an academic year and semester, so it cannot '
                . 'continue to enrollment processing.'
        );
    }

    $provider = enrAcademicPeriodProvider();
    $period = $provider->findPeriod($periodId);

    if ($period === null) {
        return enrRule(
            'academic_period',
            'Academic period is open',
            ENR_RULE_FAIL,
            'The academic period on this application no longer exists.'
        );
    }

    $label = $period['school_year'] . ' ' . $period['semester'];

    if ((int) $period['is_active'] !== 1) {
        return enrRule(
            'academic_period',
            'Academic period is open',
            ENR_RULE_WARNING,
            $label . ' is not the active academic period. Confirm the applicant is enrolling '
                . 'for the correct term before approving.'
        );
    }

    return enrRule(
        'academic_period',
        'Academic period is open',
        ENR_RULE_PASS,
        $label . ' is the active academic period.'
    );
}

/**
 * Run every rule against an application.
 *
 * @param array<string, mixed> $application
 * @return list<array{key:string, label:string, result:string, message:string}>
 */
function enrRunValidation(PDO $pdo, array $application): array
{
    return [
        enrRuleApplicantInfo($application),
        enrRuleAcademicInfo($application),
        enrRuleProgramOffered($application),
        enrRuleApplicantType($application),
        enrRuleDocumentsSubmitted($application),
        enrRuleDocumentsVerified($application),
        enrRuleNoDuplicate($pdo, $application),
        enrRuleAcademicPeriod($application),
    ];
}

/**
 * Summarise a checklist.
 *
 * @param list<array{key:string, label:string, result:string, message:string}> $checklist
 * @return array{pass:int, warning:int, fail:int, blocking:list<string>}
 */
function enrValidationSummary(array $checklist): array
{
    $summary = ['pass' => 0, 'warning' => 0, 'fail' => 0, 'blocking' => []];

    foreach ($checklist as $rule) {
        switch ($rule['result']) {
            case ENR_RULE_PASS:
                $summary['pass']++;
                break;
            case ENR_RULE_WARNING:
                $summary['warning']++;
                break;
            case ENR_RULE_FAIL:
                $summary['fail']++;
                $summary['blocking'][] = $rule['label'];
                break;
        }
    }

    return $summary;
}

/**
 * Approval is blocked while any rule fails. Warnings never block, but they are
 * stored with the decision so the reasoning survives.
 *
 * @param list<array{key:string, label:string, result:string, message:string}> $checklist
 */
function enrCanApprove(array $checklist): bool
{
    return enrValidationSummary($checklist)['fail'] === 0;
}

/**
 * The Bootstrap contextual class for a rule result.
 */
function enrRuleToneClass(string $result): string
{
    return match ($result) {
        ENR_RULE_PASS    => 'success',
        ENR_RULE_WARNING => 'warning',
        ENR_RULE_FAIL    => 'danger',
        default          => 'secondary',
    };
}

/**
 * The icon name for a rule result, using the shared smsIcon() vocabulary.
 */
function enrRuleIcon(string $result): string
{
    return match ($result) {
        ENR_RULE_PASS    => 'check-circle',
        ENR_RULE_WARNING => 'exclamation-triangle',
        ENR_RULE_FAIL    => 'times-circle',
        default          => 'circle',
    };
}
