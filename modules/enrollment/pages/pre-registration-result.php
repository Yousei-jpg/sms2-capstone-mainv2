<?php
/**
 * SMS 2 - Online Pre-registration : Screen 5 - Processed Result
 * Module: Enrollment Management -> Registration
 *
 * The outcome of the Registrar's decision, with the next steps that outcome
 * actually permits. A rejected application shows no path forward, because a
 * rejected application must not continue into downstream enrollment processes.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/prereg-repository.php';
require_once __DIR__ . '/../includes/prereg-ui.php';

$referenceNo = trim((string) ($_GET['ref'] ?? ''));
$justDecided = !empty($_GET['done']);

$pageTitle    = 'Processed Result';
$activeModule = 'enrollment';
$activePage   = 'online-pre-registration';
$breadcrumbs  = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Online Pre-registration', 'url' => BASE_URL . '/modules/enrollment/pages/online-pre-registration.php'],
    ['label' => 'Applications', 'url' => BASE_URL . '/modules/enrollment/pages/pre-registration-queue.php'],
    ['label' => $referenceNo !== '' ? $referenceNo : 'Application', 'url' => null],
    ['label' => 'Result', 'url' => null],
];
$pageBannerIcon = 'fa-check-circle';
$pageBannerDescription = 'The recorded outcome of this application and what happens next.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrSchemaInstalled($pdo);
$application = null;
$review = null;

if ($schemaReady && $referenceNo !== '') {
    try {
        $application = enrFindApplicationByReference($pdo, $referenceNo);
        if ($application !== null) {
            $review = enrLatestReview($pdo, (int) $application['id']);
        }
    } catch (Throwable $e) {
        error_log('Processed result load failed: ' . $e->getMessage());
    }
}
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrRenderWorkflowSteps(5, $application !== null ? (string) $application['reference_no'] : null); ?>

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
        'No application matches reference "' . $referenceNo . '".',
        ['label' => 'Back to Application Queue', 'url' => enrPageUrl('pre-registration-queue.php')]
    ); ?>

<?php elseif ($review === null): ?>

    <?php enrRenderState(
        'empty',
        'No decision has been recorded yet',
        'This application is still marked "' . $application['status'] . '". Validate it first, '
            . 'and the outcome will appear here.',
        ['label' => 'Go to Validation', 'url' => enrPageUrl('pre-registration-validate.php', ['ref' => $referenceNo])]
    ); ?>

<?php else: ?>

    <?php
    $ref = (string) $application['reference_no'];
    $status = (string) $application['status'];
    $name = enrApplicantName($application);
    $remarks = trim((string) ($review['remarks'] ?? ''));

    $isApproved  = ($status === ENR_STATUS_APPROVED || $status === ENR_STATUS_PROCESSED);
    $isRejected  = ($status === ENR_STATUS_REJECTED);
    $isCorrection = ($status === ENR_STATUS_FOR_CORRECTION);

    [$iconClass, $icon, $headline, $lead] = match (true) {
        $isApproved => [
            'enr-result-approved',
            'check-circle',
            'Application Approved',
            'The pre-registration application has been approved and is now available for enrollment processing.',
        ],
        $isCorrection => [
            'enr-result-correction',
            'exclamation-triangle',
            'Returned for Correction',
            'The application was sent back to the applicant. It returns to the review queue once they resubmit.',
        ],
        $isRejected => [
            'enr-result-rejected',
            'times-circle',
            'Application Rejected',
            'The application was not accepted. This closes the current enrollment process for this applicant.',
        ],
        default => [
            'enr-result-correction',
            'info-circle',
            'Decision Recorded',
            'The decision below is the most recent one on this application.',
        ],
    };
    ?>

    <?php if ($justDecided): ?>
        <?php enrRenderAlert(
            $isApproved ? 'success' : ($isRejected ? 'danger' : 'warning'),
            'Your decision was saved.',
            'Application ' . $ref . ' is now marked "' . $status . '".'
        ); ?>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card border-0 shadow-sm">
                <div class="card-body text-center py-4">
                    <div class="enr-result-icon <?= $iconClass ?> mx-auto">
                        <?= smsIcon($icon, ['aria-hidden' => 'true']) ?>
                    </div>
                    <h4 class="fw-semibold mb-2"><?= htmlspecialchars($headline) ?></h4>
                    <p class="text-muted mb-0 mx-auto" style="max-width:32rem;">
                        <?= htmlspecialchars($lead) ?>
                    </p>
                </div>

                <div class="card-body border-top">
                    <h6 class="fw-semibold mb-3">Application Details</h6>
                    <dl class="enr-meta-grid mb-0">
                        <div><dt>Reference No.</dt><dd><?= htmlspecialchars($ref) ?></dd></div>
                        <div><dt>Applicant Name</dt><dd><?= htmlspecialchars($name) ?></dd></div>
                        <div><dt>Applicant Type</dt><dd><?= htmlspecialchars((string) $application['applicant_type']) ?></dd></div>
                        <div><dt>Program</dt><dd><?= htmlspecialchars((string) ($application['program_code'] ?: '—')) ?></dd></div>
                        <div><dt>Entry Level</dt><dd><?= htmlspecialchars((string) ($application['entry_level'] ?: '—')) ?></dd></div>
                        <div><dt>Academic Year</dt><dd><?= htmlspecialchars((string) ($application['school_year'] ?: '—')) ?></dd></div>
                        <div><dt>Semester</dt><dd><?= htmlspecialchars((string) ($application['semester'] ?: '—')) ?></dd></div>
                        <div><dt>Status</dt><dd><?= enrStatusBadge($status) ?></dd></div>
                        <div>
                            <dt><?= $isRejected ? 'Rejected By' : ($isCorrection ? 'Returned By' : 'Approved By') ?></dt>
                            <dd><?= htmlspecialchars((string) ($review['reviewer_name'] ?? 'System')) ?></dd>
                        </div>
                        <div>
                            <dt><?= $isRejected ? 'Rejected On' : ($isCorrection ? 'Returned On' : 'Approved On') ?></dt>
                            <dd><?= htmlspecialchars(enrFormatDateTime($review['reviewed_at'])) ?></dd>
                        </div>
                    </dl>

                    <?php if ($remarks !== ''): ?>
                        <div class="mt-3">
                            <div class="small text-muted fw-semibold text-uppercase mb-1"
                                 style="letter-spacing:.03em;font-size:.72rem;">
                                <?= $isRejected ? 'Rejection Reason' : ($isCorrection ? 'What the applicant must correct' : 'Registrar Remarks') ?>
                            </div>
                            <div class="enr-readonly"><?= nl2br(htmlspecialchars($remarks)) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">Next Steps</h6>

                    <?php if ($isApproved): ?>
                        <?php
                        $steps = [
                            'Upload and verify the required documents in the Document Upload Portal.',
                            'Run Enrollment Validation against the complete record.',
                            'Generate the student ID number.',
                            'Assign the grade level, then the section.',
                        ];
                        ?>
                        <?php foreach ($steps as $i => $step): ?>
                            <div class="enr-next-step">
                                <span class="enr-next-step-no"><?= $i + 1 ?></span>
                                <span class="small"><?= htmlspecialchars($step) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <p class="text-muted small mt-3 mb-0">
                            <?= smsIcon('info-circle', ['aria-hidden' => 'true']) ?>
                            These subsystems have not been implemented yet, so the steps above are the
                            planned route rather than links you can follow today.
                        </p>

                    <?php elseif ($isCorrection): ?>
                        <div class="enr-next-step">
                            <span class="enr-next-step-no">1</span>
                            <span class="small">The applicant is notified of the correction remarks.</span>
                        </div>
                        <div class="enr-next-step">
                            <span class="enr-next-step-no">2</span>
                            <span class="small">They correct the information and resubmit.</span>
                        </div>
                        <div class="enr-next-step">
                            <span class="enr-next-step-no">3</span>
                            <span class="small">The application returns to the queue as "For Review".</span>
                        </div>
                        <div class="enr-next-step">
                            <span class="enr-next-step-no">4</span>
                            <span class="small">You validate it again. Correction is a loop, not an endpoint.</span>
                        </div>
                        <p class="text-muted small mt-3 mb-0">
                            <?= smsIcon('info-circle', ['aria-hidden' => 'true']) ?>
                            Applicant notification belongs to Parent Notification, which is not built yet.
                            Contact the applicant outside the system for now.
                        </p>

                    <?php else: ?>
                        <div class="enr-next-step is-blocked">
                            <span class="enr-next-step-no">&times;</span>
                            <span class="small">
                                This application is closed. It cannot be reopened, approved, or moved
                                into any downstream enrollment process.
                            </span>
                        </div>
                        <p class="text-muted small mt-3 mb-0">
                            If the applicant should be reconsidered, they must submit a new
                            pre-registration application, which receives a new reference number.
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="enr-btn" href="<?= htmlspecialchars(enrPageUrl('pre-registration-queue.php')) ?>">
            <?= smsIcon('arrow-left', ['aria-hidden' => 'true']) ?> Back to Application List
        </a>
        <a class="enr-btn enr-btn-ghost"
           href="<?= htmlspecialchars(enrPageUrl('pre-registration-details.php', ['ref' => $ref, 'tab' => 'history'])) ?>">
            <?= smsIcon('history', ['aria-hidden' => 'true']) ?> View Decision History
        </a>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
