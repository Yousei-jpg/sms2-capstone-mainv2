<?php
/**
 * SMS 2 - Document Upload Portal : Screen 5 - Processing Result
 *
 * Confirms what was recorded and routes to the next sensible action. What it
 * shows depends on the decision: a verified document points forward, a
 * replacement request explains what the applicant must now do.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/prereg-repository.php';
require_once __DIR__ . '/../includes/docs-repository.php';
require_once __DIR__ . '/../includes/docs-ui.php';

$pageTitle    = 'Processing Result';
$activeModule = 'enrollment';
$activePage   = 'document-upload-portal';

$documentId = (int) ($_GET['id'] ?? 0);

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrDocSchemaInstalled($pdo);

$document = null;
$latest = null;
$completeness = [];
$nextDocumentId = null;

if ($schemaReady && $documentId > 0) {
    $document = enrDocFind($pdo, $documentId);
    if ($document !== null) {
        $history = enrDocVerifications($pdo, $documentId);
        $latest  = $history ? end($history) : null;

        $application = enrFindApplicationByReference($pdo, (string) $document['reference_no']);
        if ($application !== null) {
            $completeness = enrDocApplicationSummary($pdo, $application)['completeness'];
        }

        $nextDocumentId = enrDocNextForVerification($pdo, (int) $document['application_id'], $documentId);
    }
}

$ref = $document !== null ? (string) $document['reference_no'] : '';

$breadcrumbs = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Document Upload Portal', 'url' => enrPageUrl('document-upload-portal.php')],
    ['label' => $ref !== '' ? $ref : 'Applicant',
     'url'   => $ref !== '' ? enrPageUrl('applicant-documents.php', ['ref' => $ref]) : null],
    ['label' => 'Result', 'url' => null],
];
$pageBannerIcon = 'fa-clipboard-check';
$pageBannerDescription = 'The verification has been recorded.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrDocWorkflowSteps(5, $ref !== '' ? $ref : null, $documentId); ?>

<?php if (!$schemaReady): ?>

    <?php enrRenderState('setup', 'The document tables have not been installed yet',
        'Run modules/enrollment/database/install_document_upload.php, then reload.'); ?>

<?php elseif ($document === null): ?>

    <?php enrRenderState(
        'no-results',
        'That document could not be found',
        'The link may be out of date. Go back to the queue and open the applicant again.',
        ['label' => 'Back to Queue', 'url' => enrPageUrl('document-queue.php')]
    ); ?>

<?php else: ?>

    <?php
    $status = (string) $document['status'];
    $name   = trim($document['first_name'] . ' ' . $document['last_name']);

    $presentation = match ($status) {
        ENR_DOC_VERIFIED => [
            'icon'  => 'circle-check',
            'class' => 'enr-result-approved',
            'title' => 'Document Verified',
            'lead'  => 'The document has been checked and accepted. The applicant\'s record is updated.',
        ],
        ENR_DOC_NEEDS_REPLACEMENT => [
            'icon'  => 'refresh',
            'class' => 'enr-result-correction',
            'title' => 'Replacement Requested',
            'lead'  => 'The document stays on file but is not accepted. The applicant needs to upload a corrected copy.',
        ],
        ENR_DOC_REJECTED => [
            'icon'  => 'circle-x',
            'class' => 'enr-result-rejected',
            'title' => 'Document Rejected',
            'lead'  => 'The document is not acceptable and no replacement was requested.',
        ],
        default => [
            'icon'  => 'clock',
            'class' => 'enr-result-correction',
            'title' => 'Awaiting Verification',
            'lead'  => 'This document has not been decided on yet.',
        ],
    };
    ?>

    <div class="row g-3 enr-animate">
        <div class="col-lg-5">
            <section class="card border-0 shadow-sm h-100 text-center">
                <div class="card-body py-5">
                    <div class="enr-result-icon <?= htmlspecialchars($presentation['class']) ?> mx-auto mb-3">
                        <?= smsIcon($presentation['icon'], ['aria-hidden' => 'true']) ?>
                    </div>
                    <h2 class="h5 fw-semibold mb-2"><?= htmlspecialchars($presentation['title']) ?></h2>
                    <p class="text-muted small mb-0 mx-auto" style="max-width:26rem;">
                        <?= htmlspecialchars($presentation['lead']) ?>
                    </p>
                </div>
            </section>
        </div>

        <div class="col-lg-7">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0 fw-semibold">Verification Summary</h2>
                </div>
                <div class="card-body">
                    <dl class="enr-meta-grid mb-3">
                        <dt>Reference No.</dt><dd><?= htmlspecialchars($ref) ?></dd>
                        <dt>Applicant</dt><dd><?= htmlspecialchars($name) ?></dd>
                        <dt>Document</dt><dd><?= htmlspecialchars((string) $document['document_name']) ?></dd>
                        <?php if ($latest !== null): ?>
                            <dt>Previous Status</dt>
                            <dd><?= htmlspecialchars((string) $latest['previous_status']) ?></dd>
                            <dt>Current Status</dt><dd><?= enrDocBadge($status) ?></dd>
                            <dt>Verified By</dt>
                            <dd><?= htmlspecialchars((string) ($latest['verifier_name'] ?? 'System')) ?></dd>
                            <dt>Verified Date</dt>
                            <dd><?= htmlspecialchars(enrFormatDateTime((string) $latest['verified_at'])) ?></dd>
                            <dt>Remarks</dt>
                            <dd><?= htmlspecialchars((string) ($latest['remarks'] ?? '—')) ?></dd>
                        <?php else: ?>
                            <dt>Current Status</dt><dd><?= enrDocBadge($status) ?></dd>
                        <?php endif; ?>
                    </dl>

                    <?php if ($completeness): ?>
                        <?php enrDocProgressBar($completeness); ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="card border-0 shadow-sm">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0 fw-semibold">What Happens Next</h2>
                </div>
                <div class="card-body">
                    <?php if ($status === ENR_DOC_NEEDS_REPLACEMENT): ?>
                        <p class="small mb-2">
                            <?= smsIcon('info-circle', ['aria-hidden' => 'true']) ?>
                            The applicant must upload a corrected file. Once they do, it returns to this queue as
                            "For Verification" as a new record, so the rejected copy and your reason both stay on file.
                        </p>
                        <p class="small text-muted mb-0">
                            Notifying the applicant is handled by the Parent Notification subsystem, which is not
                            built yet. For now the applicant needs to be contacted outside the system.
                        </p>
                    <?php elseif ($status === ENR_DOC_REJECTED): ?>
                        <p class="small mb-0">
                            <?= smsIcon('alert-circle', ['aria-hidden' => 'true']) ?>
                            A rejected document is final and blocks document completeness. If the applicant should be
                            given another chance, use "Needs Replacement" on a fresh upload instead.
                        </p>
                    <?php elseif ($completeness && $completeness['status'] === ENR_DOCS_COMPLETE): ?>
                        <p class="small mb-0 text-success-emphasis">
                            <?= smsIcon('circle-check', ['aria-hidden' => 'true']) ?>
                            Every required document for this applicant is now verified. They are ready for
                            Enrollment Validation.
                        </p>
                    <?php elseif ($completeness): ?>
                        <p class="small mb-2">Still outstanding for this applicant:</p>
                        <ul class="small mb-0">
                            <?php foreach ($completeness['blocking'] as $reason): ?>
                                <li><?= htmlspecialchars($reason) ?></li>
                            <?php endforeach; ?>
                        </ul>
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
           href="<?= htmlspecialchars(enrPageUrl('applicant-documents.php', ['ref' => $ref])) ?>">
            <?= smsIcon('folder-open', ['aria-hidden' => 'true']) ?> Applicant Documents
        </a>
        <?php if ($nextDocumentId !== null): ?>
            <a class="enr-btn ms-auto"
               href="<?= htmlspecialchars(enrPageUrl('document-verify.php', ['id' => $nextDocumentId])) ?>">
                <?= smsIcon('checkbox', ['aria-hidden' => 'true']) ?> Verify Next Document
            </a>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
