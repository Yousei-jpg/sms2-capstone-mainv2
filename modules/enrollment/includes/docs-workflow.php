<?php
/**
 * SMS 2 - Document Upload Portal: workflow rules
 * Module: Enrollment  |  Subsystem: Registration -> Document Upload Portal
 *
 * Owns the document status lifecycle, the legal transitions between statuses,
 * and how "documents complete" is calculated for an application.
 *
 * No HTML and no database writes here. Screens call these; docs-repository.php
 * persists. Every rule is re-checked server-side before a write.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Storage location
// ---------------------------------------------------------------------------

/**
 * Subdirectory under storage/uploads/ holding enrollment documents.
 *
 * Also defined in config/enrollment.php. Repeated here behind a !defined()
 * guard so the portal still works if config/enrollment.php is out of date:
 * every file that references this constant already loads this one. Whichever
 * loads first wins, and both define the same value.
 */
if (!defined('ENR_DOC_SUBDIR')) {
    define('ENR_DOC_SUBDIR', 'enrollment-documents');
}

// ---------------------------------------------------------------------------
// Status vocabulary
// ---------------------------------------------------------------------------

/**
 * MISSING is deliberately not a stored value. A missing document is the
 * ABSENCE of a row against an active requirement. It only ever exists as a
 * calculated status in enrDocChecklist().
 *
 * This is what keeps your two states genuinely different:
 *   Missing           - never submitted, nothing to look at
 *   Needs Replacement - submitted, looked at, found inadequate
 */
if (!defined('ENR_DOC_MISSING')) {
    define('ENR_DOC_MISSING',           'Missing');
    define('ENR_DOC_FOR_VERIFICATION',  'For Verification');
    define('ENR_DOC_VERIFIED',          'Verified');
    define('ENR_DOC_NEEDS_REPLACEMENT', 'Needs Replacement');
    define('ENR_DOC_REJECTED',          'Rejected');
}

/** Application-level rollup, derived from the documents beneath it. */
if (!defined('ENR_DOCS_INCOMPLETE')) {
    define('ENR_DOCS_INCOMPLETE',       'Incomplete');
    define('ENR_DOCS_FOR_VERIFICATION', 'For Verification');
    define('ENR_DOCS_COMPLETE',         'Complete');
}

/**
 * Statuses a stored document row may hold.
 *
 * @return list<string>
 */
function enrDocStatuses(): array
{
    return [
        ENR_DOC_FOR_VERIFICATION,
        ENR_DOC_VERIFIED,
        ENR_DOC_NEEDS_REPLACEMENT,
        ENR_DOC_REJECTED,
    ];
}

/**
 * Legal transitions for a single document.
 *
 * Rejected is terminal by design. If a Registrar wants to give the applicant
 * another attempt, the correct action is "Needs Replacement", which explicitly
 * invites a re-upload. Rejected means this document will not be accepted, and
 * a re-upload should not quietly reopen it.
 *
 * Verified -> Needs Replacement is allowed on purpose: a mistake spotted later
 * must be correctable, and the verification history keeps both decisions.
 *
 * @return array<string, list<string>>
 */
function enrDocTransitions(): array
{
    return [
        ENR_DOC_FOR_VERIFICATION  => [ENR_DOC_VERIFIED, ENR_DOC_NEEDS_REPLACEMENT, ENR_DOC_REJECTED],
        ENR_DOC_NEEDS_REPLACEMENT => [ENR_DOC_FOR_VERIFICATION, ENR_DOC_REJECTED],
        ENR_DOC_VERIFIED          => [ENR_DOC_NEEDS_REPLACEMENT],
        ENR_DOC_REJECTED          => [],
    ];
}

function enrDocCanTransition(string $from, string $to): bool
{
    $allowed = enrDocTransitions()[$from] ?? null;
    if ($allowed === null) {
        return false;
    }
    return in_array($to, $allowed, true);
}

/** A specific refusal message, never a generic one. */
function enrDocTransitionError(string $from, string $to, string $documentName = 'This document'): string
{
    if (!in_array($from, enrDocStatuses(), true)) {
        return $documentName . ' has an unrecognised status (' . $from . ') and cannot be verified.';
    }

    $allowed = enrDocTransitions()[$from] ?? [];

    if ($allowed === []) {
        return $documentName . ' was rejected and cannot be changed. '
            . 'If the applicant should be allowed another attempt, the document must be re-uploaded '
            . 'against a new requirement entry.';
    }

    return $documentName . ' is currently "' . $from . '" and cannot be set to "' . $to . '". '
        . 'Allowed outcomes from here: ' . implode(', ', $allowed) . '.';
}

/**
 * The three decisions a Registrar can record on the verification screen,
 * mapped to the status each produces.
 *
 * @return array<string, array{status:string, label:string, tone:string, needs_remarks:bool}>
 */
function enrDocDecisions(): array
{
    return [
        'verify' => [
            'status'        => ENR_DOC_VERIFIED,
            'label'         => 'Verified',
            'tone'          => 'success',
            'needs_remarks' => false,
        ],
        'replace' => [
            'status'        => ENR_DOC_NEEDS_REPLACEMENT,
            'label'         => 'Needs Replacement',
            'tone'          => 'warning',
            // The applicant is being asked to do something. They must be told what.
            'needs_remarks' => true,
        ],
        'reject' => [
            'status'        => ENR_DOC_REJECTED,
            'label'         => 'Rejected',
            'tone'          => 'danger',
            'needs_remarks' => true,
        ],
    ];
}

/**
 * Common reasons a document needs replacing, offered as quick picks so the
 * Registrar is not retyping the same sentence forty times a day. Free text
 * remains available and is always appended.
 *
 * @return list<string>
 */
function enrDocReplacementReasons(): array
{
    return [
        'The image is too blurry to read.',
        'The document is incomplete — part of it is cut off.',
        'The wrong document was uploaded.',
        'The document has expired.',
        'The file will not open or is corrupted.',
        'The name on the document does not match the application.',
    ];
}

// ---------------------------------------------------------------------------
// Checklist and completeness
// ---------------------------------------------------------------------------

/**
 * Merge an applicant type's requirements with what has actually been uploaded,
 * producing the checklist shown on Screen 3.
 *
 * A requirement with no matching upload comes back as Missing. That is the
 * only place the Missing status is ever produced.
 *
 * @param list<array<string,mixed>> $requirements from enr_document_requirements
 * @param list<array<string,mixed>> $documents    from enr_application_documents
 * @return list<array<string,mixed>>
 */
function enrDocChecklist(array $requirements, array $documents): array
{
    // Index uploads by requirement. If an applicant uploaded the same
    // requirement twice, the most recent row wins and the older one stays in
    // history rather than showing twice on the checklist.
    $byRequirement = [];
    foreach ($documents as $doc) {
        $key = (int) ($doc['requirement_id'] ?? 0);
        if ($key <= 0) {
            continue;
        }
        $existing = $byRequirement[$key] ?? null;
        if ($existing === null || (int) $doc['id'] > (int) $existing['id']) {
            $byRequirement[$key] = $doc;
        }
    }

    $checklist = [];

    foreach ($requirements as $requirement) {
        $requirementId = (int) $requirement['id'];
        $uploaded      = $byRequirement[$requirementId] ?? null;

        $checklist[] = [
            'requirement_id' => $requirementId,
            'document_name'  => (string) $requirement['document_name'],
            'description'    => (string) ($requirement['description'] ?? ''),
            'is_required'    => (int) $requirement['is_required'] === 1,
            'status'         => $uploaded === null
                ? ENR_DOC_MISSING
                : (string) $uploaded['status'],
            'document'       => $uploaded,
        ];
    }

    // Extra uploads that do not map to a requirement still deserve to be seen.
    foreach ($documents as $doc) {
        if ((int) ($doc['requirement_id'] ?? 0) > 0) {
            continue;
        }
        $checklist[] = [
            'requirement_id' => 0,
            'document_name'  => (string) $doc['document_name'],
            'description'    => 'Submitted outside the standard checklist.',
            'is_required'    => false,
            'status'         => (string) $doc['status'],
            'document'       => $doc,
        ];
    }

    return $checklist;
}

/**
 * Roll a checklist up into the application-level picture.
 *
 * "Complete" means every REQUIRED document is Verified. Optional documents
 * never block completion, and an uploaded-but-unverified document is not
 * complete — it is merely submitted.
 *
 * @param list<array<string,mixed>> $checklist from enrDocChecklist()
 * @return array{
 *   status:string, required_total:int, required_verified:int,
 *   uploaded:int, missing:int, required_missing:int, for_verification:int,
 *   needs_replacement:int, rejected:int, percent:int, blocking:list<string>
 * }
 */
function enrDocCompleteness(array $checklist): array
{
    $requiredTotal = 0;
    $requiredVerified = 0;
    // Counted separately from $missing, which includes optional documents.
    // "Has the applicant submitted everything?" must only ask about required
    // ones, or an untouched optional row blocks a compliant applicant.
    $requiredMissing = 0;
    $uploaded = 0;
    $missing = 0;
    $forVerification = 0;
    $needsReplacement = 0;
    $rejected = 0;
    $blocking = [];

    foreach ($checklist as $item) {
        $status     = (string) $item['status'];
        $isRequired = (bool) $item['is_required'];
        $name       = (string) $item['document_name'];

        if ($status !== ENR_DOC_MISSING) {
            $uploaded++;
        }

        switch ($status) {
            case ENR_DOC_MISSING:
                $missing++;
                if ($isRequired) {
                    $requiredMissing++;
                    $blocking[] = $name . ' has not been submitted.';
                }
                break;
            case ENR_DOC_FOR_VERIFICATION:
                $forVerification++;
                if ($isRequired) {
                    $blocking[] = $name . ' is uploaded but has not been verified yet.';
                }
                break;
            case ENR_DOC_NEEDS_REPLACEMENT:
                $needsReplacement++;
                if ($isRequired) {
                    $blocking[] = $name . ' needs to be replaced by the applicant.';
                }
                break;
            case ENR_DOC_REJECTED:
                $rejected++;
                if ($isRequired) {
                    $blocking[] = $name . ' was rejected.';
                }
                break;
            case ENR_DOC_VERIFIED:
                if ($isRequired) {
                    $requiredVerified++;
                }
                break;
        }

        if ($isRequired) {
            $requiredTotal++;
        }
    }

    if ($requiredTotal > 0 && $requiredVerified === $requiredTotal) {
        $status = ENR_DOCS_COMPLETE;
    } elseif ($forVerification > 0 && $missing === 0 && $needsReplacement === 0 && $rejected === 0) {
        $status = ENR_DOCS_FOR_VERIFICATION;
    } else {
        $status = ENR_DOCS_INCOMPLETE;
    }

    $percent = $requiredTotal > 0
        ? (int) round(($requiredVerified / $requiredTotal) * 100)
        : 0;

    return [
        'status'            => $status,
        'required_total'    => $requiredTotal,
        'required_verified' => $requiredVerified,
        'uploaded'          => $uploaded,
        'missing'           => $missing,
        'required_missing'  => $requiredMissing,
        'for_verification'  => $forVerification,
        'needs_replacement' => $needsReplacement,
        'rejected'          => $rejected,
        'percent'           => $percent,
        'blocking'          => $blocking,
    ];
}

/**
 * Tone class for a document status, used by badges across the portal.
 */
function enrDocStatusClass(string $status): string
{
    return match ($status) {
        ENR_DOC_VERIFIED          => 'completed',
        ENR_DOC_FOR_VERIFICATION  => 'pending',
        ENR_DOC_NEEDS_REPLACEMENT => 'processing',
        ENR_DOC_REJECTED          => 'cancelled',
        ENR_DOC_MISSING           => 'cancelled',
        default                   => 'processing',
    };
}

function enrDocStatusIcon(string $status): string
{
    return match ($status) {
        ENR_DOC_VERIFIED          => 'circle-check',
        ENR_DOC_FOR_VERIFICATION  => 'clock',
        ENR_DOC_NEEDS_REPLACEMENT => 'refresh',
        ENR_DOC_REJECTED          => 'circle-x',
        ENR_DOC_MISSING           => 'alert-circle',
        default                   => 'file',
    };
}

// ---------------------------------------------------------------------------
// Upload constraints
// ---------------------------------------------------------------------------

/** Maximum upload size in bytes, configurable through system_settings. */
function enrDocMaxBytes(): int
{
    $mb = (int) smsSetting('enr_doc_max_mb', '10');
    if ($mb < 1 || $mb > 50) {
        $mb = 10;
    }
    return $mb * 1024 * 1024;
}

/**
 * Allowed types, narrowed from the foundation's general document whitelist.
 * Enrollment documents are scans and photos, so Word files are not accepted:
 * a birth certificate should never arrive as a .docx.
 *
 * @return array<string, list<string>>
 */
function enrDocAllowedTypes(): array
{
    $configured = array_filter(array_map(
        'trim',
        explode(',', smsSetting('enr_doc_allowed_ext', 'pdf,jpg,jpeg,png'))
    ));

    $all = smsUploadAllowedDocuments();
    $allowed = [];

    foreach ($configured as $ext) {
        $ext = strtolower($ext);
        if (isset($all[$ext])) {
            $allowed[$ext] = $all[$ext];
        }
    }

    return $allowed !== [] ? $allowed : [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
    ];
}

/** Human-readable size for the UI. */
function enrDocFormatSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' bytes';
}

/** Whether a stored document can be previewed inline or only downloaded. */
function enrDocIsImage(?string $mime): bool
{
    return in_array((string) $mime, ['image/jpeg', 'image/png'], true);
}

function enrDocIsPdf(?string $mime): bool
{
    return (string) $mime === 'application/pdf';
}
