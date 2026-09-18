<?php
/**
 * SMS 2 - Document Upload Portal : Screen 2 - Document Queue
 *
 * Search, filter, and select an applicant whose documents need attention.
 * Filtering narrows this queue only; it changes nothing elsewhere.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/docs-repository.php';
require_once __DIR__ . '/../includes/docs-ui.php';
// This screen's Program filter reads the program list through the provider.
require_once __DIR__ . '/../includes/providers/enrollment-providers.php';

$pageTitle    = 'Document Queue';
$activeModule = 'enrollment';
$activePage   = 'document-upload-portal';
$breadcrumbs  = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Document Upload Portal', 'url' => enrPageUrl('document-upload-portal.php')],
    ['label' => 'Queue', 'url' => null],
];
$pageBannerIcon = 'fa-list';
$pageBannerDescription = 'Search and filter applicants, then open one to review their documents.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrDocSchemaInstalled($pdo);

$filters = [
    'search'         => trim((string) ($_GET['search'] ?? '')),
    'program_id'     => (int) ($_GET['program_id'] ?? 0),
    'applicant_type' => trim((string) ($_GET['applicant_type'] ?? '')),
    'doc_status'     => trim((string) ($_GET['doc_status'] ?? '')),
    'date_from'      => trim((string) ($_GET['date_from'] ?? '')),
    'date_to'        => trim((string) ($_GET['date_to'] ?? '')),
];

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

$rows = [];
$counts = [];
$programs = [];
$totalRows = 0;
$totalPages = 1;
$loadError = '';
$dateError = '';

// Validate the date range before it reaches a query, and say what is wrong.
if ($filters['date_from'] !== '' && $filters['date_to'] !== ''
    && $filters['date_from'] > $filters['date_to']) {
    $dateError = 'The "from" date is after the "to" date, so no applicant could match. '
        . 'Swap the two dates or clear one of them.';
    $filters['date_from'] = '';
    $filters['date_to'] = '';
}

if ($schemaReady) {
    try {
        $all = enrDocFindApplications($pdo, $filters);
        $counts = enrDocQueueCounts($pdo);
        $programs = enrProgramProvider($pdo)->activePrograms();

        $totalRows  = count($all);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);
        $rows       = array_slice($all, ($page - 1) * $perPage, $perPage);
    } catch (Throwable $e) {
        error_log('Document queue load failed: ' . $e->getMessage());
        $loadError = $e->getMessage();
    }
}

$tabs = [
    ''                        => 'All',
    ENR_DOCS_FOR_VERIFICATION => 'For Verification',
    ENR_DOCS_INCOMPLETE       => 'Incomplete',
    ENR_DOC_NEEDS_REPLACEMENT => 'Needs Replacement',
    ENR_DOCS_COMPLETE         => 'Complete',
];

$tabBase = array_filter([
    'search'         => $filters['search'],
    'program_id'     => $filters['program_id'] > 0 ? $filters['program_id'] : '',
    'applicant_type' => $filters['applicant_type'],
]);
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrDocWorkflowSteps(2); ?>

<?php if (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The document tables have not been installed yet',
        'Run modules/enrollment/database/install_document_upload.php from the command line, then reload this page.'
    ); ?>

<?php else: ?>

    <nav class="enr-tabs" aria-label="Filter by document status">
        <?php foreach ($tabs as $statusKey => $label): ?>
            <?php
            $isActive = ($filters['doc_status'] === $statusKey);
            $query = $tabBase;
            if ($statusKey !== '') {
                $query['doc_status'] = $statusKey;
            }
            $count = $statusKey === ''
                ? (int) ($counts['Total'] ?? 0)
                : (int) ($counts[$statusKey] ?? 0);
            $tabIcon = match ($statusKey) {
                ENR_DOCS_FOR_VERIFICATION => 'clock',
                ENR_DOCS_INCOMPLETE       => 'alert-circle',
                ENR_DOC_NEEDS_REPLACEMENT => 'refresh',
                ENR_DOCS_COMPLETE         => 'circle-check',
                default                   => 'list',
            };
            ?>
            <a class="enr-tab<?= $isActive ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars(enrPageUrl('document-queue.php', $query)) ?>">
                <?= smsIcon($tabIcon, ['aria-hidden' => 'true']) ?>
                <?= htmlspecialchars($label) ?>
                <span class="enr-tab-count"><?= $count ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="card border-0 shadow-sm mb-3 enr-filter-card">
        <div class="card-body">
            <form method="get" action="<?= htmlspecialchars(enrPageUrl('document-queue.php')) ?>">
                <?php if ($filters['doc_status'] !== ''): ?>
                    <input type="hidden" name="doc_status" value="<?= htmlspecialchars($filters['doc_status']) ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="search">Search</label>
                        <input class="form-control" type="search" id="search" name="search"
                               value="<?= htmlspecialchars($filters['search']) ?>"
                               placeholder="Name, reference no., or email">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="program_id">Program</label>
                        <select class="form-select" id="program_id" name="program_id">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int) $program['id'] ?>"
                                    <?= $filters['program_id'] === (int) $program['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $program['code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="applicant_type">Applicant Type</label>
                        <select class="form-select" id="applicant_type" name="applicant_type">
                            <option value="">All Types</option>
                            <?php foreach (enrApplicantTypes() as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"
                                    <?= $filters['applicant_type'] === $type ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="date_from">Submitted between</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" type="date" id="date_from" name="date_from"
                                   value="<?= htmlspecialchars($filters['date_from']) ?>">
                            <input class="form-control" type="date" name="date_to"
                                   value="<?= htmlspecialchars($filters['date_to']) ?>">
                        </div>
                    </div>
                </div>

                <?php if ($dateError !== ''): ?>
                    <div class="alert alert-warning mt-3 mb-0 py-2 small">
                        <?= smsIcon('exclamation-triangle', ['aria-hidden' => 'true']) ?>
                        <?= htmlspecialchars($dateError) ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="enr-btn enr-btn-sm">
                        <?= smsIcon('filter', ['aria-hidden' => 'true']) ?> Apply Filter
                    </button>
                    <a class="enr-btn enr-btn-ghost enr-btn-sm"
                       href="<?= htmlspecialchars(enrPageUrl('document-queue.php')) ?>">
                        <?= smsIcon('undo', ['aria-hidden' => 'true']) ?> Reset
                    </a>
                </div>
            </form>
        </div>
    </section>

    <section class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if ($loadError !== ''): ?>
                <div class="p-4">
                    <?php enrRenderAlert('danger', 'The queue could not be loaded.', $loadError,
                        'Reload the page. If it keeps failing, check the PHP error log.'); ?>
                </div>
            <?php elseif (!$rows): ?>
                <div class="p-4">
                    <?php
                    $hasFilters = $filters['search'] !== '' || $filters['program_id'] > 0
                        || $filters['applicant_type'] !== '' || $filters['doc_status'] !== ''
                        || $filters['date_from'] !== '' || $filters['date_to'] !== '';

                    if ($hasFilters) {
                        enrRenderState(
                            'no-results',
                            'No applicants match these filters',
                            'Nothing in the document queue matches what you searched for. '
                            . 'Widen the filters or reset them to see the full queue.',
                            ['label' => 'Reset filters', 'url' => enrPageUrl('document-queue.php')]
                        );
                    } else {
                        enrRenderState(
                            'empty',
                            'No applications are waiting on documents',
                            'Applications appear here once they have been submitted through pre-registration. '
                            . 'To load sample data for testing, run seed_sample_applications.php then '
                            . 'seed_sample_documents.php.'
                        );
                    }
                    ?>
                </div>
            <?php else: ?>
                <div class="mpl-table-wrap">
                    <table class="mpl-table mb-0">
                        <thead>
                            <tr>
                                <th>Reference No.</th>
                                <th>Applicant</th>
                                <th>Program</th>
                                <th>Type</th>
                                <th>Documents</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $name = trim($row['first_name'] . ' ' . $row['last_name']);
                                $c = $row['completeness'];
                                ?>
                                <tr data-enr-row-link="<?= htmlspecialchars(enrPageUrl('applicant-documents.php', ['ref' => $row['reference_no']])) ?>">
                                    <td class="ref"><?= htmlspecialchars((string) $row['reference_no']) ?></td>
                                    <td>
                                        <div class="mpl-person">
                                            <span class="mpl-avatar"><?= htmlspecialchars(enrInitials($name)) ?></span>
                                            <div>
                                                <strong><?= htmlspecialchars($name) ?></strong>
                                                <small><?= htmlspecialchars((string) $row['email']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($row['program_code'] ?? '—')) ?></td>
                                    <td><?= htmlspecialchars((string) $row['applicant_type']) ?></td>
                                    <td>
                                        <span class="fw-semibold"><?= (int) $c['required_verified'] ?>/<?= (int) $c['required_total'] ?></span>
                                        <small class="text-muted d-block">verified</small>
                                    </td>
                                    <td><?= enrDocSummaryBadge((string) $row['doc_status']) ?></td>
                                    <td>
                                        <a class="enr-btn enr-btn-soft enr-btn-sm"
                                           href="<?= htmlspecialchars(enrPageUrl('applicant-documents.php', ['ref' => $row['reference_no']])) ?>">
                                            <?= smsIcon('folder-open', ['aria-hidden' => 'true']) ?> Open
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php enrRenderPagination($page, $totalPages, $totalRows, 'document-queue.php', array_filter([
                    'search'         => $filters['search'],
                    'program_id'     => $filters['program_id'] > 0 ? $filters['program_id'] : '',
                    'applicant_type' => $filters['applicant_type'],
                    'doc_status'     => $filters['doc_status'],
                    'date_from'      => $filters['date_from'],
                    'date_to'        => $filters['date_to'],
                ])); ?>
            <?php endif; ?>
        </div>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
