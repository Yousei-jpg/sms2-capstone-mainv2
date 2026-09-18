<?php
/**
 * SMS 2 - Online Pre-registration : Screen 4 - Validation & Decision
 * Module: Enrollment Management -> Registration
 *
 * Runs the validation rules and records the Registrar's decision.
 *
 * Server-side guarantees, independent of what the form allowed:
 *   - CSRF token verified on every POST
 *   - rules re-run at decision time, never trusted from the submitted form
 *   - approval refused while any rule FAILs
 *   - remarks required for Return for Correction and Reject
 *   - status transition validated against enrAllowedTransitions()
 *   - the UPDATE re-checks the status it expected, so a stale screen cannot
 *     overwrite another Registrar's decision
 *
 * The POST is handled before any output so a successful decision can redirect
 * to the result screen (post/redirect/get), which also stops a refresh from
 * recording the same decision twice.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once __DIR__ . '/../includes/prereg-repository.php';
require_once __DIR__ . '/../includes/prereg-workflow.php';
require_once __DIR__ . '/../includes/prereg-ui.php';

// Auth and permission run here rather than waiting for layout-start.php,
// because this screen writes to the database before it renders anything.
requireAuth();
requireModuleAccess('enrollment');

$referenceNo = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrSchemaInstalled($pdo);
$userId = (int) ($_SESSION['user_id'] ?? 0);

$application = null;
$checklist = [];
$summary = ['pass' => 0, 'warning' => 0, 'fail' => 0, 'blocking' => []];
$savedDraft = null;

$flash = ['type' => '', 'message' => '', 'detail' => '', 'next' => ''];
$remarksValue = '';
$decisionValue = '';
$remarksError = '';
$decisionError = '';

if ($schemaReady && $referenceNo !== '') {
    $application = enrFindApplicationByReference($pdo, $referenceNo);

    // Opening validation counts as taking the application under review. Without
    // this, an application reached directly from a link is still "Submitted",
    // and the transition map correctly refuses Submitted -> Approved. Details
    // does the same promotion, so arriving from either screen behaves alike.
    if ($application !== null && (string) $application['status'] === ENR_STATUS_SUBMITTED) {
        if (enrMarkUnderReview($pdo, $application, $userId)) {
            $application['status'] = ENR_STATUS_FOR_REVIEW;
        }
    }
}

// ---------------------------------------------------------------------------
// POST — save draft or record a decision (before any output)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $application !== null) {

    if (!csrfVerify()) {
        $flash = [
            'type'    => 'danger',
            'message' => 'Your session security token did not match.',
            'detail'  => 'Nothing was saved. This usually means the page sat open long enough for '
                . 'the session to be renewed.',
            'next'    => 'Reload this page and make the decision again.',
        ];
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        $remarksValue = trim((string) ($_POST['remarks'] ?? ''));
        $decisionValue = trim((string) ($_POST['decision'] ?? ''));

        // Rules are always re-run here. The browser's copy is never trusted.
        $checklist = enrRunValidation($pdo, $application);
        $summary = enrValidationSummary($checklist);

        if ($action === 'save_draft') {
            enrSaveValidationDraft($pdo, $application, $checklist, $remarksValue, $userId);
            $flash = [
                'type'    => 'success',
                'message' => 'Validation draft saved.',
                'detail'  => 'The checklist and your remarks were stored. No decision was recorded '
                    . 'and the application status is unchanged.',
                'next'    => 'You can leave this page and finish the decision later.',
            ];

        } elseif ($action === 'decide') {

            $map = [
                'approve' => [ENR_STATUS_APPROVED, 'Approved'],
                'return'  => [ENR_STATUS_FOR_CORRECTION, 'For Correction'],
                'reject'  => [ENR_STATUS_REJECTED, 'Rejected'],
            ];

            if (!isset($map[$decisionValue])) {
                $decisionError = 'Choose one of the three decisions before saving.';

            } elseif (in_array($decisionValue, ['return', 'reject'], true) && $remarksValue === '') {
                $remarksError = $decisionValue === 'return'
                    ? 'Remarks are required when returning an application. The applicant needs to '
                        . 'know exactly what to correct before resubmitting.'
                    : 'Remarks are required when rejecting an application. The rejection reason is '
                        . 'kept on the record permanently.';

            } elseif ($decisionValue === 'approve' && !enrCanApprove($checklist)) {
                $decisionError = 'This application cannot be approved while '
                    . $summary['fail'] . ' requirement'
                    . ($summary['fail'] === 1 ? '' : 's')
                    . ' still fail: ' . implode(', ', $summary['blocking'])
                    . '. Return it for correction instead, or resolve the failing requirement first.';

            } else {
                [$newStatus, $decisionLabel] = $map[$decisionValue];

                $result = enrRecordDecision(
                    $pdo,
                    $application,
                    $newStatus,
                    $decisionLabel,
                    $checklist,
                    $remarksValue,
                    $userId
                );

                if ($result['ok']) {
                    header('Location: ' . enrPageUrl('pre-registration-result.php', [
                        'ref'  => $application['reference_no'],
                        'done' => 1,
                    ]));
                    exit;
                }

                $flash = [
                    'type'    => $result['stale'] ? 'warning' : 'danger',
                    'message' => $result['stale']
                        ? 'This application changed while you had it open.'
                        : 'The decision could not be saved.',
                    'detail'  => $result['message'],
                    'next'    => $result['stale']
                        ? 'Reload the application to see the current status and latest decision.'
                        : 'Nothing was written. You can safely try again.',
                ];

                // Refresh so the screen reflects reality rather than the stale copy.
                $application = enrFindApplicationByReference($pdo, $referenceNo) ?? $application;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Run the rules for display
// ---------------------------------------------------------------------------
if ($application !== null) {
    try {
        if (!$checklist) {
            $checklist = enrRunValidation($pdo, $application);
            $summary = enrValidationSummary($checklist);
        }
        $savedDraft = enrLatestDraft($pdo, (int) $application['id']);
        if ($savedDraft !== null && $remarksValue === '') {
            $remarksValue = (string) ($savedDraft['remarks'] ?? '');
        }
    } catch (Throwable $e) {
        error_log('Validation run failed: ' . $e->getMessage());
    }
}

$currentStatus = $application !== null ? (string) $application['status'] : '';
$isOpenForDecision = in_array($currentStatus, [ENR_STATUS_FOR_REVIEW, ENR_STATUS_SUBMITTED], true);
$canApproveNow = $checklist !== [] && enrCanApprove($checklist);

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$pageTitle    = 'Validation & Decision';
$activeModule = 'enrollment';
$activePage   = 'online-pre-registration';
$breadcrumbs  = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Online Pre-registration', 'url' => BASE_URL . '/modules/enrollment/pages/online-pre-registration.php'],
    ['label' => 'Applications', 'url' => BASE_URL . '/modules/enrollment/pages/pre-registration-queue.php'],
    ['label' => $referenceNo !== '' ? $referenceNo : 'Application',
     'url' => $referenceNo !== ''
        ? BASE_URL . '/modules/enrollment/pages/pre-registration-details.php?ref=' . urlencode($referenceNo)
        : null],
    ['label' => 'Validation', 'url' => null],
];
$pageBannerIcon = 'fa-check-double';
$pageBannerDescription = 'Check every requirement, then approve, return, or reject the application.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrRenderWorkflowSteps(4, $application !== null ? (string) $application['reference_no'] : null); ?>

<?php if (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The Enrollment schema has not been installed yet',
        'Run php modules/enrollment/database/install_enrollment.php, then reload this page.'
    ); ?>

<?php elseif ($application === null): ?>

    <?php enrRenderState(
        'no-results',
        'Application not found',
        'No application matches reference "' . $referenceNo . '". Open one from the queue instead.',
        ['label' => 'Back to Application Queue', 'url' => enrPageUrl('pre-registration-queue.php')]
    ); ?>

<?php else: ?>

    <?php $ref = (string) $application['reference_no']; ?>

    <?php if ($flash['message'] !== ''): ?>
        <?php enrRenderAlert($flash['type'], $flash['message'], $flash['detail'], $flash['next']); ?>
    <?php endif; ?>

    <?php if (!$isOpenForDecision): ?>
        <?php enrRenderAlert(
            'info',
            'This application is already marked "' . $currentStatus . '".',
            enrTransitionError($currentStatus, ENR_STATUS_APPROVED),
            'The checklist below is shown for reference only; no decision can be recorded.'
        ); ?>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Validation checklist -->
        <div class="col-lg-7">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="fw-semibold mb-1">Validation Checklist</h5>
                            <p class="text-muted small mb-0">
                                <?= htmlspecialchars(enrApplicantName($application)) ?> &middot; <?= htmlspecialchars($ref) ?>
                            </p>
                        </div>
                        <div class="text-end small">
                            <span class="text-success fw-semibold"><?= (int) $summary['pass'] ?> passed</span><br>
                            <span class="text-warning fw-semibold"><?= (int) $summary['warning'] ?> warning</span><br>
                            <span class="text-danger fw-semibold"><?= (int) $summary['fail'] ?> failed</span>
                        </div>
                    </div>

                    <?php foreach ($checklist as $rule): ?>
                        <?php
                        $toneClass = match ($rule['result']) {
                            ENR_RULE_PASS    => 'enr-rule-pass',
                            ENR_RULE_WARNING => 'enr-rule-warning',
                            default          => 'enr-rule-fail',
                        };
                        ?>
                        <article class="enr-rule <?= $toneClass ?>">
                            <span class="enr-rule-icon">
                                <?= smsIcon(enrRuleIcon($rule['result']), ['aria-hidden' => 'true']) ?>
                            </span>
                            <div>
                                <div class="enr-rule-label">
                                    <?= htmlspecialchars($rule['label']) ?>
                                    <span class="badge text-bg-<?= htmlspecialchars(enrRuleToneClass($rule['result'])) ?> ms-1">
                                        <?= htmlspecialchars($rule['result']) ?>
                                    </span>
                                </div>
                                <div class="enr-rule-message"><?= htmlspecialchars($rule['message']) ?></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- Decision -->
        <div class="col-lg-5">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h5 class="fw-semibold mb-1">Decision</h5>
                    <p class="text-muted small mb-3">
                        Every decision is recorded with your name, the time, and these remarks.
                    </p>

                    <?php if ($savedDraft !== null): ?>
                        <div class="alert alert-light border small py-2">
                            <?= smsIcon('save', ['aria-hidden' => 'true']) ?>
                            A draft was saved on <?= htmlspecialchars(enrFormatDateTime($savedDraft['reviewed_at'])) ?>.
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= htmlspecialchars(enrPageUrl('pre-registration-validate.php', ['ref' => $ref])) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="ref" value="<?= htmlspecialchars($ref) ?>">

                        <label class="enr-decision-option<?= ($canApproveNow && $isOpenForDecision) ? '' : ' is-disabled' ?>">
                            <input type="radio" name="decision" value="approve"
                                   <?= $decisionValue === 'approve' ? 'checked' : '' ?>
                                   <?= ($canApproveNow && $isOpenForDecision) ? '' : 'disabled' ?>>
                            <span>
                                <span class="enr-decision-title text-success">Approve Application</span>
                                <span class="enr-decision-hint d-block">
                                    <?= $canApproveNow
                                        ? 'Valid and complete. Releases the record for enrollment processing.'
                                        : 'Blocked while any requirement fails.' ?>
                                </span>
                            </span>
                        </label>

                        <label class="enr-decision-option<?= $isOpenForDecision ? '' : ' is-disabled' ?>">
                            <input type="radio" name="decision" value="return"
                                   <?= $decisionValue === 'return' ? 'checked' : '' ?>
                                   <?= $isOpenForDecision ? '' : 'disabled' ?>>
                            <span>
                                <span class="enr-decision-title text-warning">Return for Correction</span>
                                <span class="enr-decision-hint d-block">
                                    Sends it back so the applicant can fix and resubmit. Remarks required.
                                </span>
                            </span>
                        </label>

                        <label class="enr-decision-option<?= $isOpenForDecision ? '' : ' is-disabled' ?>">
                            <input type="radio" name="decision" value="reject"
                                   <?= $decisionValue === 'reject' ? 'checked' : '' ?>
                                   <?= $isOpenForDecision ? '' : 'disabled' ?>>
                            <span>
                                <span class="enr-decision-title text-danger">Reject Application</span>
                                <span class="enr-decision-hint d-block">
                                    Closes the application permanently. It cannot be reopened. Remarks required.
                                </span>
                            </span>
                        </label>

                        <?php if ($decisionError !== ''): ?>
                            <div class="text-danger small mb-2">
                                <?= smsIcon('exclamation-triangle', ['aria-hidden' => 'true']) ?>
                                <?= htmlspecialchars($decisionError) ?>
                            </div>
                        <?php endif; ?>

                        <div class="mt-3">
                            <label class="form-label small fw-semibold" for="remarks">
                                Remarks
                                <span class="text-muted fw-normal">(required to return or reject)</span>
                            </label>
                            <textarea class="form-control<?= $remarksError !== '' ? ' is-invalid' : '' ?>"
                                      id="remarks" name="remarks" rows="4" maxlength="500"
                                      <?= $isOpenForDecision ? '' : 'disabled' ?>
                                      placeholder="Explain exactly what the applicant must correct, or why the application is rejected."><?= htmlspecialchars($remarksValue) ?></textarea>
                            <?php if ($remarksError !== ''): ?>
                                <div class="invalid-feedback d-block"><?= htmlspecialchars($remarksError) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Maximum 500 characters.</div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a class="enr-btn enr-btn-ghost enr-btn-sm"
                               href="<?= htmlspecialchars(enrPageUrl('pre-registration-details.php', ['ref' => $ref])) ?>">
                                <?= smsIcon('arrow-left', ['aria-hidden' => 'true']) ?> Back
                            </a>
                            <?php if ($isOpenForDecision): ?>
                                <button type="submit" name="action" value="save_draft" class="enr-btn enr-btn-soft enr-btn-sm" data-enr-submit>
                                    <?= smsIcon('save', ['aria-hidden' => 'true']) ?> Save Draft
                                </button>
                                <button type="submit" name="action" value="decide" class="enr-btn enr-btn-sm ms-auto" data-enr-submit data-enr-decision-submit>
                                    <?= smsIcon('circle-check', ['aria-hidden' => 'true']) ?>
                                    <span data-enr-decision-label>Save Decision</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
