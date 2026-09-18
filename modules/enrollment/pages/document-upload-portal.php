<?php
/**
 * SMS 2 - Document Upload Portal : Screen 1 - Portal Dashboard
 * Module: Enrollment Management -> Registration
 *
 * Overview of document verification activity. Every figure is counted from
 * enr_application_documents and the requirement list; nothing is fabricated.
 * Cards link into the Document Queue with the matching filter applied.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/docs-repository.php';
require_once __DIR__ . '/../includes/docs-ui.php';

$pageTitle    = 'Document Upload Portal';
$activeModule = 'enrollment';
$activePage   = 'document-upload-portal';
$breadcrumbs  = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Document Upload Portal', 'url' => null],
];
$pageBannerIcon = 'fa-folder-open';
$pageBannerDescription = 'Review uploaded documents, verify them, and track which applicants are still incomplete.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

// layout-start.php has already run requireAuth() and requireModuleAccess().
$pdo = db();
$schemaReady = $pdo instanceof PDO && enrDocSchemaInstalled($pdo);

$counts = [];
$recent = [];
$loadError = '';

if ($schemaReady) {
    try {
        $counts = enrDocQueueCounts($pdo);
        $recent = enrDocRecentUploads($pdo, 6);
    } catch (Throwable $e) {
        error_log('Document portal dashboard load failed: ' . $e->getMessage());
        $loadError = $e->getMessage();
    }
}
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrDocWorkflowSteps(1); ?>

<?php if (!$pdo instanceof PDO): ?>

    <?php enrRenderState(
        'error',
        'The database is unavailable',
        'The Document Upload Portal cannot load because SMS 2 could not connect to the database. '
        . 'Check that MySQL is running in XAMPP, then reload this page.'
    ); ?>

<?php elseif (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The document tables have not been installed yet',
        'The Document Upload Portal needs its database tables before it can show anything. '
        . 'Run modules/enrollment/database/install_document_upload.php from the command line, then reload.'
    ); ?>

<?php elseif ($loadError !== ''): ?>

    <?php enrRenderAlert(
        'danger',
        'The dashboard figures could not be loaded.',
        $loadError,
        'Reload the page. If it keeps failing, check the PHP error log.'
    ); ?>

<?php else: ?>

    <?php
    $cards = [
        [
            'label' => 'Applications',
            'value' => (int) ($counts['Total'] ?? 0),
            'icon'  => 'users',
            'tone'  => 'blue',
            'url'   => enrPageUrl('document-queue.php'),
        ],
        [
            'label' => 'For Verification',
            'value' => (int) ($counts[ENR_DOCS_FOR_VERIFICATION] ?? 0),
            'icon'  => 'clock',
            'tone'  => 'amber',
            'url'   => enrPageUrl('document-queue.php', ['doc_status' => ENR_DOCS_FOR_VERIFICATION]),
        ],
        [
            'label' => 'Needs Replacement',
            'value' => (int) ($counts[ENR_DOC_NEEDS_REPLACEMENT] ?? 0),
            'icon'  => 'refresh',
            'tone'  => 'purple',
            'url'   => enrPageUrl('document-queue.php', ['doc_status' => ENR_DOC_NEEDS_REPLACEMENT]),
        ],
        [
            'label' => 'Incomplete',
            'value' => (int) ($counts[ENR_DOCS_INCOMPLETE] ?? 0),
            'icon'  => 'alert-circle',
            'tone'  => 'amber',
            'url'   => enrPageUrl('document-queue.php', ['doc_status' => ENR_DOCS_INCOMPLETE]),
        ],
        [
            'label' => 'Documents Complete',
            'value' => (int) ($counts[ENR_DOCS_COMPLETE] ?? 0),
            'icon'  => 'circle-check',
            'tone'  => 'green',
            'url'   => enrPageUrl('document-queue.php', ['doc_status' => ENR_DOCS_COMPLETE]),
        ],
    ];
    enrRenderStatCards($cards);
    ?>

    <div class="row g-3 enr-animate">
        <div class="col-12">
            <section class="card border-0 shadow-sm">
                <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
                    <div>
                        <h2 class="h6 mb-0 fw-semibold">Recent Uploads</h2>
                        <p class="small text-muted mb-0">The most recently submitted documents.</p>
                    </div>
                    <a class="enr-btn enr-btn-soft enr-btn-sm" href="<?= htmlspecialchars(enrPageUrl('document-queue.php')) ?>">
                        <?= smsIcon('list', ['aria-hidden' => 'true']) ?> View Queue
                    </a>
                </div>

                <div class="card-body p-0">
                    <?php if (!$recent): ?>
                        <div class="p-4">
                            <?php enrRenderState(
                                'empty',
                                'No documents have been uploaded yet',
                                'Documents appear here once applicants submit them. To load sample documents '
                                . 'for testing, run: php modules/enrollment/database/seed_sample_documents.php'
                            ); ?>
                        </div>
                    <?php else: ?>
                        <div class="mpl-table-wrap">
                            <table class="mpl-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Reference No.</th>
                                        <th>Applicant</th>
                                        <th>Document</th>
                                        <th>Program</th>
                                        <th>Uploaded</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $row): ?>
                                        <?php $name = trim($row['first_name'] . ' ' . $row['last_name']); ?>
                                        <tr data-enr-row-link="<?= htmlspecialchars(enrPageUrl('applicant-documents.php', ['ref' => $row['reference_no']])) ?>">
                                            <td class="ref"><?= htmlspecialchars((string) $row['reference_no']) ?></td>
                                            <td>
                                                <div class="mpl-person">
                                                    <span class="mpl-avatar"><?= htmlspecialchars(enrInitials($name)) ?></span>
                                                    <div><strong><?= htmlspecialchars($name) ?></strong></div>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars((string) $row['document_name']) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['program_code'] ?? '—')) ?></td>
                                            <td><?= htmlspecialchars(enrFormatDateTime((string) $row['uploaded_at'])) ?></td>
                                            <td><?= enrDocBadge((string) $row['status']) ?></td>
                                            <td>
                                                <a class="enr-btn enr-btn-soft enr-btn-sm"
                                                   href="<?= htmlspecialchars(enrPageUrl('document-verify.php', ['id' => (int) $row['id']])) ?>">
                                                    <?= smsIcon('checkbox', ['aria-hidden' => 'true']) ?> Verify
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
