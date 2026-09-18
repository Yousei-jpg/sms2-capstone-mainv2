<?php
/**
 * SMS 2 - Document Upload Portal : Screen 4 - Document Verification
 *
 * Preview one document, optionally run OCR over it, and record the decision.
 *
 * Every rule is enforced here on the server, not in the browser: the legality
 * of the status change, whether remarks are required, and whether the record
 * changed since the screen was opened.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/uploads.php';
require_once __DIR__ . '/../includes/docs-repository.php';
require_once __DIR__ . '/../includes/docs-ui.php';
require_once __DIR__ . '/../includes/ocr-provider.php';

$pageTitle    = 'Document Verification';
$activeModule = 'enrollment';
$activePage   = 'document-upload-portal';

$documentId = (int) ($_GET['id'] ?? $_POST['document_id'] ?? 0);

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrDocSchemaInstalled($pdo);

$document   = null;
$formError  = '';
$staleError = '';
$ocrNotice  = '';
$ocrTone    = 'info';

if ($schemaReady && $documentId > 0) {
    $document = enrDocFind($pdo, $documentId);
}

// ---------------------------------------------------------------------------
// POST handling
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $document !== null) {
    requireCsrf();

    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'run_ocr') {
        // OCR is advisory. It writes ocr_text and nothing else: the document's
        // verification status is untouched by anything below.
        $path = smsUploadRoot() . '/' . ENR_DOC_SUBDIR . '/' . basename((string) $document['stored_name']);
        $result = enrOcrExtractText($path, (string) $document['mime_type']);

        enrDocSaveOcr($pdo, $documentId, $result['status'], $result['text'] !== '' ? $result['text'] : null);

        $ocrNotice = $result['message'];
        $ocrTone   = $result['ok'] ? 'success' : 'warning';
        $document  = enrDocFind($pdo, $documentId);

    } elseif ($action === 'decide') {
        $decisionKey = (string) ($_POST['decision'] ?? '');
        $remarks     = trim((string) ($_POST['remarks'] ?? ''));
        $decisions   = enrDocDecisions();

        if (!isset($decisions[$decisionKey])) {
            $formError = 'Choose whether the document is verified, needs replacement, or is rejected.';
        } elseif ($decisions[$decisionKey]['needs_remarks'] && $remarks === '') {
            // The applicant is being asked to act. They must be told what to fix.
            $formError = $decisions[$decisionKey]['label'] === 'Needs Replacement'
                ? 'Explain what is wrong with the document. The applicant sees this remark and needs to '
                    . 'know what to upload instead.'
                : 'A rejection must record a reason. Explain why this document is not acceptable.';
        } else {
            $target = $decisions[$decisionKey];
            $result = enrDocRecordVerification(
                $pdo,
                $document,
                $target['status'],
                $target['label'],
                $remarks,
                $userId
            );

            if ($result['ok']) {
                header('Location: ' . enrPageUrl('document-result.php', ['id' => $documentId]));
                exit;
            }

            if ($result['stale']) {
                $staleError = $result['message'];
                $document = enrDocFind($pdo, $documentId);
            } else {
                $formError = $result['message'];
            }
        }
    }
}

$ref = $document !== null ? (string) $document['reference_no'] : '';

$breadcrumbs = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Document Upload Portal', 'url' => enrPageUrl('document-upload-portal.php')],
    ['label' => $ref !== '' ? $ref : 'Applicant',
     'url'   => $ref !== '' ? enrPageUrl('applicant-documents.php', ['ref' => $ref]) : null],
    ['label' => 'Verify', 'url' => null],
];
$pageBannerIcon = 'fa-check-double';
$pageBannerDescription = 'Review the uploaded file, then record whether it is acceptable.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrDocWorkflowSteps(4, $ref !== '' ? $ref : null, $documentId); ?>

<?php if (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The document tables have not been installed yet',
        'Run modules/enrollment/database/install_document_upload.php from the command line, then reload.'
    ); ?>

<?php elseif ($document === null): ?>

    <?php enrRenderState(
        'no-results',
        'That document could not be found',
        'The document may have been removed, or the link may be out of date. Open it again from the '
        . 'applicant\'s checklist.',
        ['label' => 'Back to Queue', 'url' => enrPageUrl('document-queue.php')]
    ); ?>

<?php else: ?>

    <?php
    $name          = trim($document['first_name'] . ' ' . $document['last_name']);
    $status        = (string) $document['status'];
    $isDecidable   = enrDocTransitions()[$status] !== [];
    $fileUrl       = BASE_URL . '/modules/enrollment/api/document-file.php?id=' . $documentId;
    $history       = enrDocVerifications($pdo, $documentId);
    $ocrText       = (string) ($document['ocr_text'] ?? '');
    $nameHint      = $ocrText !== ''
        ? enrOcrNameHint($ocrText, (string) $document['first_name'], (string) $document['last_name'])
        : null;
    ?>

    <?php if ($staleError !== ''): ?>
        <?php enrRenderAlert('warning', 'This document changed while you had it open.', $staleError,
            'Review the current status below before deciding again.'); ?>
    <?php endif; ?>

    <?php if ($formError !== ''): ?>
        <?php enrRenderAlert('danger', 'The decision was not saved.', $formError, ''); ?>
    <?php endif; ?>

    <?php if ($ocrNotice !== ''): ?>
        <?php enrRenderAlert($ocrTone, 'Text extraction', $ocrNotice, ''); ?>
    <?php endif; ?>

    <div class="row g-3 enr-animate">
        <!-- Preview -->
        <div class="col-lg-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0 fw-semibold"><?= htmlspecialchars((string) $document['document_name']) ?></h2>
                    <a class="enr-btn enr-btn-ghost enr-btn-sm" href="<?= htmlspecialchars($fileUrl . '&dl=1') ?>">
                        <?= smsIcon('download', ['aria-hidden' => 'true']) ?> Download
                    </a>
                </div>
                <div class="card-body">
                    <div class="enr-doc-preview">
                        <?php if (enrDocIsImage((string) $document['mime_type'])): ?>
                            <img src="<?= htmlspecialchars($fileUrl) ?>"
                                 alt="Uploaded <?= htmlspecialchars((string) $document['document_name']) ?>">
                        <?php elseif (enrDocIsPdf((string) $document['mime_type'])): ?>
                            <object data="<?= htmlspecialchars($fileUrl) ?>" type="application/pdf">
                                <p class="p-3 mb-0 small">
                                    Your browser cannot display this PDF inline.
                                    <a href="<?= htmlspecialchars($fileUrl . '&dl=1') ?>">Download it instead</a>.
                                </p>
                            </object>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted small">
                                <?= smsIcon('file', ['aria-hidden' => 'true']) ?>
                                This file type cannot be previewed in the browser.
                                <a href="<?= htmlspecialchars($fileUrl . '&dl=1') ?>">Download it</a> to review it.
                            </div>
                        <?php endif; ?>
                    </div>

                    <dl class="enr-meta-grid mt-3 mb-0">
                        <dt>Applicant</dt><dd><?= htmlspecialchars($name) ?></dd>
                        <dt>Reference No.</dt><dd><?= htmlspecialchars($ref) ?></dd>
                        <dt>File Name</dt><dd><?= htmlspecialchars((string) $document['original_name']) ?></dd>
                        <dt>File Size</dt><dd><?= htmlspecialchars(enrDocFormatSize((int) $document['file_size'])) ?></dd>
                        <dt>Uploaded</dt><dd><?= htmlspecialchars(enrFormatDateTime((string) $document['uploaded_at'])) ?></dd>
                        <dt>Current Status</dt><dd><?= enrDocBadge($status) ?></dd>
                    </dl>
                </div>
            </section>
        </div>

        <!-- Decision -->
        <div class="col-lg-6">
            <!-- OCR: advisory only -->
            <section class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="h6 mb-0 fw-semibold">Text Extraction</h2>
                        <p class="small text-muted mb-0">Advisory only. It never sets the status.</p>
                    </div>
                    <?php if (enrOcrEnabled()): ?>
                        <form method="post" class="m-0">
                            <?= csrfField() ?>
                            <input type="hidden" name="document_id" value="<?= $documentId ?>">
                            <button type="submit" name="action" value="run_ocr"
                                    class="enr-btn enr-btn-soft enr-btn-sm" data-enr-submit>
                                <?= smsIcon('scan', ['aria-hidden' => 'true']) ?> Read Document
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!enrOcrEnabled()): ?>
                        <p class="small text-muted mb-0">
                            <?= smsIcon('info-circle', ['aria-hidden' => 'true']) ?>
                            <?= htmlspecialchars(enrOcrUnavailableReason()) ?>
                            Verification works normally without it.
                        </p>
                    <?php elseif ($ocrText === ''): ?>
                        <p class="small text-muted mb-0">
                            No text has been extracted from this document yet.
                        </p>
                    <?php else: ?>
                        <?php if ($nameHint !== null): ?>
                            <div class="alert <?= $nameHint['matched'] ? 'alert-success' : 'alert-warning' ?> small py-2">
                                <?= smsIcon($nameHint['matched'] ? 'circle-check' : 'alert-circle', ['aria-hidden' => 'true']) ?>
                                <?= htmlspecialchars($nameHint['note']) ?>
                            </div>
                        <?php endif; ?>
                        <details>
                            <summary class="small fw-semibold">Extracted text</summary>
                            <pre class="enr-ocr-text mt-2 mb-0"><?= htmlspecialchars($ocrText) ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0 fw-semibold">Verification Result</h2>
                </div>
                <div class="card-body">
                    <?php if (!$isDecidable): ?>
                        <div class="alert alert-secondary small mb-0">
                            <?= smsIcon('lock', ['aria-hidden' => 'true']) ?>
                            <?= htmlspecialchars(enrDocTransitionError($status, ENR_DOC_VERIFIED,
                                (string) $document['document_name'])) ?>
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="document_id" value="<?= $documentId ?>">

                            <?php foreach (enrDocDecisions() as $key => $decision): ?>
                                <?php $allowed = enrDocCanTransition($status, $decision['status']); ?>
                                <label class="enr-decision-option<?= $allowed ? '' : ' is-disabled' ?>">
                                    <input type="radio" name="decision" value="<?= htmlspecialchars($key) ?>"
                                           <?= $allowed ? '' : 'disabled' ?>>
                                    <span>
                                        <span class="enr-decision-title text-<?= htmlspecialchars($decision['tone']) ?>">
                                            <?= htmlspecialchars($decision['label']) ?>
                                        </span>
                                        <span class="enr-decision-hint d-block">
                                            <?php if (!$allowed): ?>
                                                Not available from the current status.
                                            <?php elseif ($key === 'verify'): ?>
                                                The document is readable, valid, and belongs to this applicant.
                                            <?php elseif ($key === 'replace'): ?>
                                                The document exists but is not acceptable. The applicant will be asked
                                                to upload a corrected file.
                                            <?php else: ?>
                                                The document is not acceptable and no replacement will be requested.
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>

                            <div class="mt-3">
                                <label class="form-label fw-semibold" for="remarks">Remarks</label>
                                <p class="small text-muted mb-2">
                                    Required when asking for a replacement or rejecting. The applicant reads this.
                                </p>

                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php foreach (enrDocReplacementReasons() as $reason): ?>
                                        <button type="button" class="enr-btn enr-btn-ghost enr-btn-sm"
                                                data-enr-reason="<?= htmlspecialchars($reason) ?>">
                                            <?= htmlspecialchars($reason) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <textarea class="form-control" id="remarks" name="remarks" rows="3"
                                          maxlength="500"
                                          placeholder="Explain what the applicant needs to do."><?= htmlspecialchars((string) ($_POST['remarks'] ?? '')) ?></textarea>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <a class="enr-btn enr-btn-ghost enr-btn-sm"
                                   href="<?= htmlspecialchars(enrPageUrl('applicant-documents.php', ['ref' => $ref])) ?>">
                                    <?= smsIcon('arrow-left', ['aria-hidden' => 'true']) ?> Cancel
                                </a>
                                <button type="submit" name="action" value="decide"
                                        class="enr-btn enr-btn-sm ms-auto" data-enr-submit data-enr-decision-submit>
                                    <?= smsIcon('circle-check', ['aria-hidden' => 'true']) ?>
                                    <span data-enr-decision-label>Save Verification</span>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($history): ?>
                <section class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-transparent">
                        <h2 class="h6 mb-0 fw-semibold">Verification History</h2>
                        <p class="small text-muted mb-0">Earlier decisions are kept, never overwritten.</p>
                    </div>
                    <div class="card-body p-0">
                        <table class="mpl-table mb-0">
                            <thead>
                                <tr><th>Decision</th><th>Change</th><th>By</th><th>When</th><th>Remarks</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $entry): ?>
                                    <tr>
                                        <td><?= enrDocBadge((string) $entry['new_status']) ?></td>
                                        <td class="small text-muted">
                                            <?= htmlspecialchars((string) $entry['previous_status']) ?>
                                            &rarr; <?= htmlspecialchars((string) $entry['new_status']) ?>
                                        </td>
                                        <td class="small"><?= htmlspecialchars((string) ($entry['verifier_name'] ?? 'System')) ?></td>
                                        <td class="small"><?= htmlspecialchars(enrFormatDateTime((string) $entry['verified_at'])) ?></td>
                                        <td class="small"><?= htmlspecialchars((string) ($entry['remarks'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Quick-pick reasons append into the remarks box rather than replacing it,
    // so the Registrar can stack a reason and their own note.
    document.addEventListener('DOMContentLoaded', function () {
        var box = document.getElementById('remarks');
        if (!box) { return; }
        document.querySelectorAll('[data-enr-reason]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-enr-reason');
                box.value = box.value.trim() === '' ? text : box.value.trim() + ' ' + text;
                box.focus();
            });
        });
    });
    </script>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
