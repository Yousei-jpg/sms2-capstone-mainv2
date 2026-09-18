<?php
/**
 * SMS 2 - Enrollment Module Configuration
 *
 * Status vocabulary, allowed transitions, and reference-number rules for the
 * Enrollment module. No institutional policy is hardcoded here: anything BCP
 * may want to change is read from system_settings with a documented default.
 *
 * Enrollment uses the main SMS 2 database, so there is no separate connection
 * function. Use getDatabaseConnection() / db() from config/database.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/security.php';

// ---------------------------------------------------------------------------
// Document Upload Portal
// ---------------------------------------------------------------------------

/**
 * Subdirectory under storage/uploads/ where enrollment documents are stored.
 * That directory carries an .htaccess deny, so files are never reachable by
 * URL. They are served only through modules/enrollment/api/document-file.php,
 * which checks the session and module permission first.
 */
if (!defined('ENR_DOC_SUBDIR')) {
    define('ENR_DOC_SUBDIR', 'enrollment-documents');
}

// ---------------------------------------------------------------------------
// Application status vocabulary
// ---------------------------------------------------------------------------
if (!defined('ENR_STATUS_DRAFT')) {
    define('ENR_STATUS_DRAFT', 'Draft');
    define('ENR_STATUS_SUBMITTED', 'Submitted');
    define('ENR_STATUS_FOR_REVIEW', 'For Review');
    define('ENR_STATUS_FOR_CORRECTION', 'For Correction');
    define('ENR_STATUS_APPROVED', 'Approved');
    define('ENR_STATUS_REJECTED', 'Rejected');
    define('ENR_STATUS_PROCESSED', 'Processed');
}

/**
 * Every status the Online Pre-Registration subsystem uses.
 *
 * @return list<string>
 */
function enrApplicationStatuses(): array
{
    return [
        ENR_STATUS_DRAFT,
        ENR_STATUS_SUBMITTED,
        ENR_STATUS_FOR_REVIEW,
        ENR_STATUS_FOR_CORRECTION,
        ENR_STATUS_APPROVED,
        ENR_STATUS_REJECTED,
        ENR_STATUS_PROCESSED,
    ];
}

/**
 * Allowed status transitions. A status missing from this map, or mapped to an
 * empty list, is terminal. Rejected is deliberately terminal: a rejected
 * application cannot silently continue into downstream enrollment processes.
 *
 * @return array<string, list<string>>
 */
function enrAllowedTransitions(): array
{
    return [
        ENR_STATUS_DRAFT          => [ENR_STATUS_SUBMITTED],
        ENR_STATUS_SUBMITTED      => [ENR_STATUS_FOR_REVIEW],
        ENR_STATUS_FOR_REVIEW     => [
            ENR_STATUS_APPROVED,
            ENR_STATUS_FOR_CORRECTION,
            ENR_STATUS_REJECTED,
        ],
        ENR_STATUS_FOR_CORRECTION => [ENR_STATUS_FOR_REVIEW],
        ENR_STATUS_APPROVED       => [ENR_STATUS_PROCESSED],
        ENR_STATUS_REJECTED       => [],
        ENR_STATUS_PROCESSED      => [],
    ];
}

/**
 * Can this application move from $from to $to?
 */
function enrCanTransition(string $from, string $to): bool
{
    $allowed = enrAllowedTransitions();
    return in_array($to, $allowed[$from] ?? [], true);
}

/**
 * Explain a refused transition in the user's language, never "invalid input".
 */
function enrTransitionError(string $from, string $to): string
{
    if ($from === $to) {
        return 'This application is already marked "' . $from . '".';
    }

    if ($from === ENR_STATUS_REJECTED) {
        return 'This application was rejected and is closed. A rejected application '
            . 'cannot be reopened or moved to "' . $to . '". The applicant must submit a new application.';
    }

    if ($from === ENR_STATUS_PROCESSED) {
        return 'This application has already been released for enrollment processing '
            . 'and can no longer be changed to "' . $to . '".';
    }

    $allowed = enrAllowedTransitions()[$from] ?? [];
    if (!$allowed) {
        return 'An application marked "' . $from . '" is final and cannot be changed.';
    }

    return 'An application marked "' . $from . '" cannot move to "' . $to . '". '
        . 'Allowed next steps: ' . implode(', ', $allowed) . '.';
}

/**
 * Maps a status to the shared .mpl-status CSS class already used across SMS 2,
 * so Enrollment badges match every other module without new CSS.
 */
function enrStatusClass(string $status): string
{
    return match ($status) {
        ENR_STATUS_APPROVED, ENR_STATUS_PROCESSED => 'completed',
        ENR_STATUS_REJECTED                       => 'cancelled',
        ENR_STATUS_FOR_CORRECTION                 => 'processing',
        ENR_STATUS_FOR_REVIEW, ENR_STATUS_SUBMITTED => 'pending',
        default                                   => 'scheduled',
    };
}

/**
 * Applicant types offered on the pre-registration form.
 *
 * OPEN QUESTION: BCP has not confirmed its official applicant categories.
 * This list is the working set and is referenced, never hardcoded inline.
 *
 * @return list<string>
 */
function enrApplicantTypes(): array
{
    return ['Freshman', 'Transferee', 'Returning', 'Second Courser'];
}

/**
 * Entry / year levels an applicant can be admitted into.
 *
 * OPEN QUESTION: official progression rules are owned by Grade Level
 * Assignment, which is not yet built. Pre-Registration only records what the
 * applicant selected; it does not decide eligibility.
 *
 * @return list<string>
 */
function enrEntryLevels(): array
{
    return ['1st Year', '2nd Year', '3rd Year', '4th Year'];
}

/**
 * Generate the next pre-registration reference number.
 *
 * Format is prefix-year-sequence, e.g. PR-2026-001. Prefix and padding come
 * from system_settings (seeded by enrollment_db.sql) so BCP can change the
 * format without a code edit.
 *
 * The sequence is derived from the highest existing reference for the year,
 * inside the caller's transaction, and the reference_no column carries a
 * UNIQUE index so a race still cannot produce a duplicate.
 */
function enrNextReferenceNo(PDO $pdo, ?int $year = null): string
{
    $year = $year ?? (int) date('Y');
    $prefix = trim(smsSetting('enr_prereg_ref_prefix', 'PR'));
    if ($prefix === '') {
        $prefix = 'PR';
    }
    $padding = (int) smsSetting('enr_prereg_ref_padding', '3');
    if ($padding < 1 || $padding > 10) {
        $padding = 3;
    }

    $like = $prefix . '-' . $year . '-%';
    $stmt = $pdo->prepare(
        'SELECT reference_no FROM enr_applications
         WHERE reference_no LIKE ?
         ORDER BY LENGTH(reference_no) DESC, reference_no DESC
         LIMIT 1'
    );
    $stmt->execute([$like]);
    $latest = (string) ($stmt->fetchColumn() ?: '');

    $next = 1;
    if ($latest !== '') {
        $parts = explode('-', $latest);
        $tail = (int) end($parts);
        if ($tail > 0) {
            $next = $tail + 1;
        }
    }

    return $prefix . '-' . $year . '-' . str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
}
