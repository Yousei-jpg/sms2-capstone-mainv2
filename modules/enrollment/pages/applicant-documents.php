<?php
/**
 * SMS 2 - Document Upload Portal : Screen 3 - Applicant Documents
 *
 * The full requirement checklist for one applicant, merged with what they have
 * actually uploaded. Read-only: verification decisions happen on Screen 4.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/prereg-repository.php';
require_once __DIR__ . '/../includes/docs-repository.php';
require_once __DIR__ . '/../includes/docs-ui.php';

$pageTitle    = 'Applicant Documents';
$activeModule = 'enrollment';
$activePage   = 'document-upload-portal';

$ref = trim((string) ($_GET['ref'] ?? ''));

$breadcrumbs = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Document Upload Portal', 'url' => enrPageUrl('document-upload-portal.php')],
    ['label' => 'Queue', 'url' => enrPageUrl('document-queue.php')],
    ['label' => $ref !== '' ? $ref : 'Applicant', 'url' => null],
];
$pageBannerIcon = 'fa-folder-open';
$pageBannerDescription = 'Check which requirements are satisfied and open any document to verify it.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrDocSchemaInstalled($pdo);

$application = null;
$checklist = [];
$completeness = [];
$loadError = '';

if ($schemaReady && $ref !== '') {
    try {
        $application = enrFindApplicationByReference($pdo, $ref);
        if ($application !== null) {
            $summary = enrDocApplicationSummary($pdo, $application);
            $checklist = $summary['checklist'];
            $completeness = $summary['completeness'];
        }
    } catch (Throwable $e) {
        error_log('Applicant documents load failed: ' . $e->getMessage());
        $loadError = $e->getMessage();
    }
}
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrDocWorkflowSteps(3, $ref !== '' ? $ref : null); ?>

<?php if (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The document tables have not been installed yet',
        'Run modules/enrollment/database/install_document_upload.php from the command line, then reload.'
    ); ?>

<?php elseif ($ref === ''): ?>

    <?php enrRenderState(
        'error',
        'No applicant was specified',
        'This screen needs a reference number. Open an applicant from the Document Queue instead of '
        . 'navigating here directly.',
        ['label' => 'Back to Queue', 'url' => enrPageUrl('document-queue.php')]
    ); ?>

<?php elseif ($loadError !== ''): ?>

    <?php enrRenderAlert('danger', 'This applicant could not be loaded.', $loadError,
        'Go back to the queue and try again.'); ?>

<?php elseif ($application === null): ?>

    <?php enrRenderState(
        'no-results',
        'No application found for ' . $ref,
        'That reference number does not exist. It may have been mistyped, or the application may have '
        . 'been removed.',
        ['label' => 'Back to Queue', 'url' => enrPageUrl('document-queue.php')]
    ); ?>

<?php else: ?>

    <?php $name = enrApplicantName($application); ?>

    <div class="row g-3 enr-animate">
        <div class="col-lg-4">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="enr-applicant-head mb-3">
                        <span class="enr-avatar"><?= htmlspecialchars(enrInitials($name)) ?></span>
                        <div>
                            <h2 class="h6 fw-semibold mb-0"><?= htmlspecialchars($name) ?></h2>
                            <div class="small text-muted"><?= htmlspecialchars((string) $application['reference_no']) ?></div>
                        </div>
                    </div>

                    <dl class="enr-meta-grid mb-3">
                        <dt>Program</dt>
                        <dd><?= htmlspecialchars((string) ($application['program_code'] ?? '—')) ?></dd>
                        <dt>Applicant Type</dt>
                        <dd><?= htmlspecialchars((string) $application['applicant_type']) ?></dd>
                        <dt>Email</dt>
                        <dd><?= htmlspecialchars((string) $application['email']) ?></dd>
                        <dt>Contact No.</dt>
                        <dd><?= htmlspecialchars((string) ($application['mobile_no'] ?: '—')) ?></dd>
                        <dt>Application</dt>
                        <dd><?= enrStatusBadge((string) $application['status']) ?></dd>
                    </dl>

                    <?php enrDocProgressBar($completeness); ?>

                    <?php if ($completeness['blocking']): ?>
                        <div class="alert alert-warning small mt-3 mb-0">
                            <div class="fw-semibold mb-1">
                                <?= smsIcon('alert-circle', ['aria-hidden' => 'true']) ?>
                                Not yet complete
                            </div>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($completeness['blocking'] as $reason): ?>
                                    <li><?= htmlspecialchars($reason) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success small mt-3 mb-0">
                            <?= smsIcon('circle-check', ['aria-hidden' => 'true']) ?>
                            Every required document is verified. This applicant is ready for Enrollment Validation.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-lg-8">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0 fw-semibold">Required Documents</h2>
                    <p class="small text-muted mb-0">
                        Checklist for a <?= htmlspecialchars((string) $application['applicant_type']) ?> applicant.
                    </p>
                </div>
                <div class="card-body">
                    <?php if (!$checklist): ?>
                        <?php enrRenderState(
                            'setup',
                            'No requirements are configured for this applicant type',
                            'No active rows exist in enr_document_requirements for "'
                            . (string) $application['applicant_type'] . '", so there is nothing to check against. '
                            . 'An administrator needs to add the required documents for this type.'
                        ); ?>
                    <?php else: ?>
                        <div class="enr-doc-list">
                            <?php foreach ($checklist as $item): ?>
                                <?php enrDocChecklistRow($item, (string) $application['reference_no']); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="enr-btn enr-btn-ghost" href="<?= htmlspecialchars(enrPageUrl('document-queue.php')) ?>">
            <?= smsIcon('arrow-left', ['aria-hidden' => 'true']) ?> Back to Queue
        </a>
        <a class="enr-btn enr-btn-ghost"
           href="<?= htmlspecialchars(enrPageUrl('pre-registration-details.php', ['ref' => $application['reference_no']])) ?>">
            <?= smsIcon('file-text', ['aria-hidden' => 'true']) ?> View Application
        </a>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
