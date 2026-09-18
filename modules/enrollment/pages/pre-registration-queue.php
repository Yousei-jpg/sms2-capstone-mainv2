<?php
/**
 * SMS 2 - Online Pre-registration : Screen 2 - Application Queue
 * Module: Enrollment Management -> Registration
 *
 * Search, filter, and select applications for review. All filtering and
 * pagination happen in SQL, so the queue stays correct no matter how many
 * applications exist. Filters narrow this queue only.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/prereg-repository.php';
require_once __DIR__ . '/../includes/prereg-ui.php';

$pageTitle    = 'Application Queue';
$activeModule = 'enrollment';
$activePage   = 'online-pre-registration';
$breadcrumbs  = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Online Pre-registration', 'url' => BASE_URL . '/modules/enrollment/pages/online-pre-registration.php'],
    ['label' => 'Application Queue', 'url' => null],
];
$pageBannerIcon = 'fa-stream';
$pageBannerDescription = 'Search and filter submitted applications, then open one to review it.';
$pageBannerBackUrl = BASE_URL . '/modules/enrollment/pages/online-pre-registration.php';
$pageBannerBackLabel = 'Back to Dashboard';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrSchemaInstalled($pdo);

// --- Read filters from the query string -----------------------------------
$filters = [
    'search'         => trim((string) ($_GET['search'] ?? '')),
    'status'         => trim((string) ($_GET['status'] ?? '')),
    'program_id'     => (int) ($_GET['program_id'] ?? 0),
    'applicant_type' => trim((string) ($_GET['applicant_type'] ?? '')),
    'date_from'      => trim((string) ($_GET['date_from'] ?? '')),
    'date_to'        => trim((string) ($_GET['date_to'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

// Only non-empty filters travel in pagination and tab links.
$activeFilters = array_filter(
    $filters,
    static fn($value): bool => $value !== '' && $value !== 0
);

$rows = [];
$totalRows = 0;
$totalPages = 1;
$counts = [];
$programs = [];
$loadError = '';
$dateError = '';

// Field-level validation on the date range, before it reaches SQL.
if ($filters['date_from'] !== '' && $filters['date_to'] !== ''
    && $filters['date_from'] > $filters['date_to']) {
    $dateError = 'The "from" date is later than the "to" date, so no application could match. '
        . 'Swap the two dates or clear one of them.';
}

if ($schemaReady) {
    try {
        $programs = enrProgramProvider($pdo)->activePrograms();
        $counts = enrCountByStatus($pdo);

        if ($dateError === '') {
            $totalRows = enrCountApplications($pdo, $filters);
            $totalPages = max(1, (int) ceil($totalRows / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $rows = enrFindApplications($pdo, $filters, $page, $perPage);
        }
    } catch (Throwable $e) {
        error_log('Application queue load failed: ' . $e->getMessage());
        $loadError = 'The application queue could not be loaded.';
    }
}

$hasFilters = $activeFilters !== [];
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrRenderWorkflowSteps(2); ?>

<?php if (!$pdo instanceof PDO): ?>

    <?php enrRenderState(
        'error',
        'The database is unavailable',
        'SMS 2 could not connect to MySQL. Start MySQL in the XAMPP control panel and reload.'
    ); ?>

<?php elseif (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The Enrollment schema has not been installed yet',
        'Run php modules/enrollment/database/install_enrollment.php from the project root, '
            . 'then reload this page.'
    ); ?>

<?php else: ?>

    <?php if ($loadError !== ''): ?>
        <?php enrRenderAlert(
            'danger',
            $loadError,
            'The database rejected the query. No records were changed.',
            'Reload the page, or reset the filters and try again.'
        ); ?>
    <?php endif; ?>

    <?php
    // --- Status tabs, each carrying the current search and filters ---------
    $tabs = [
        ''                        => 'All',
        ENR_STATUS_FOR_REVIEW     => 'For Review',
        ENR_STATUS_FOR_CORRECTION => 'For Correction',
        ENR_STATUS_APPROVED       => 'Approved',
        ENR_STATUS_REJECTED       => 'Rejected',
        ENR_STATUS_PROCESSED      => 'Released',
    ];
    $tabBase = $activeFilters;
    unset($tabBase['status'], $tabBase['page']);
    ?>
    <nav class="enr-tabs" aria-label="Filter by status">
        <?php foreach ($tabs as $statusKey => $label): ?>
            <?php
            $isActive = ($filters['status'] === $statusKey);
            $query = $tabBase;
            if ($statusKey !== '') {
                $query['status'] = $statusKey;
            }
            $count = $statusKey === ''
                ? (int) ($counts['Total'] ?? 0)
                : (int) ($counts[$statusKey] ?? 0);
            ?>
            <?php
            // Each status carries its own icon so the tab bar is scannable
            // without reading every label.
            $tabIcon = match ($statusKey) {
                ENR_STATUS_FOR_REVIEW     => 'clock',
                ENR_STATUS_FOR_CORRECTION => 'edit',
                ENR_STATUS_APPROVED       => 'circle-check',
                ENR_STATUS_REJECTED       => 'circle-x',
                ENR_STATUS_PROCESSED      => 'send',
                default                   => 'list',
            };
            ?>
            <a class="enr-tab<?= $isActive ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars(enrPageUrl('pre-registration-queue.php', $query)) ?>">
                <?= smsIcon($tabIcon, ['aria-hidden' => 'true']) ?>
                <?= htmlspecialchars($label) ?>
                <span class="enr-tab-count"><?= $count ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Filter bar -->
    <section class="card border-0 shadow-sm mb-3 enr-filter-card">
        <div class="card-body">
            <form method="get" action="<?= htmlspecialchars(enrPageUrl('pre-registration-queue.php')) ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label" for="qSearch">Search</label>
                        <input type="search" class="form-control form-control-sm" id="qSearch" name="search"
                               value="<?= htmlspecialchars($filters['search']) ?>"
                               placeholder="Applicant name, reference no., or email">
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label" for="qProgram">Program</label>
                        <select class="form-select form-select-sm" id="qProgram" name="program_id">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int) $program['id'] ?>"
                                    <?= $filters['program_id'] === (int) $program['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $program['code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label" for="qType">Applicant Type</label>
                        <select class="form-select form-select-sm" id="qType" name="applicant_type">
                            <option value="">All Types</option>
                            <?php foreach (enrApplicantTypes() as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"
                                    <?= $filters['applicant_type'] === $type ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label" for="qFrom">Submitted From</label>
                        <input type="date" class="form-control form-control-sm" id="qFrom" name="date_from"
                               value="<?= htmlspecialchars($filters['date_from']) ?>">
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label" for="qTo">Submitted To</label>
                        <input type="date" class="form-control form-control-sm" id="qTo" name="date_to"
                               value="<?= htmlspecialchars($filters['date_to']) ?>">
                    </div>
                </div>

                <?php if ($filters['status'] !== ''): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filters['status']) ?>">
                <?php endif; ?>

                <?php if ($dateError !== ''): ?>
                    <div class="text-danger small mt-2">
                        <?= smsIcon('exclamation-triangle', ['aria-hidden' => 'true']) ?>
                        <?= htmlspecialchars($dateError) ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="enr-btn enr-btn-sm">
                        <?= smsIcon('filter', ['aria-hidden' => 'true']) ?> Apply Filter
                    </button>
                    <a class="enr-btn enr-btn-ghost enr-btn-sm"
                       href="<?= htmlspecialchars(enrPageUrl('pre-registration-queue.php')) ?>">
                        <?= smsIcon('undo', ['aria-hidden' => 'true']) ?> Reset
                    </a>
                </div>
            </form>
        </div>
    </section>

    <!-- Queue -->
    <section class="mpl-panel">
        <div class="mpl-panel-head">
            <div>
                <h2>Application List</h2>
                <p>
                    <?= $filters['status'] !== ''
                        ? 'Showing applications marked "' . htmlspecialchars($filters['status']) . '".'
                        : 'Showing all pre-registration applications.' ?>
                </p>
            </div>
        </div>

        <?php if ($dateError !== ''): ?>
            <div class="p-4">
                <?php enrRenderState(
                    'no-results',
                    'The date range cannot match anything',
                    $dateError,
                    ['label' => 'Reset filters', 'url' => enrPageUrl('pre-registration-queue.php')]
                ); ?>
            </div>
        <?php elseif (!$rows && $hasFilters): ?>
            <div class="p-4">
                <?php enrRenderState(
                    'no-results',
                    'No applications match these filters',
                    'Nothing in the queue matches the search and filters you applied. Widen the '
                        . 'search or reset the filters to see the full queue.',
                    ['label' => 'Reset filters', 'url' => enrPageUrl('pre-registration-queue.php')]
                ); ?>
            </div>
        <?php elseif (!$rows): ?>
            <div class="p-4">
                <?php enrRenderState(
                    'empty',
                    'The application queue is empty',
                    'No applications have been submitted yet. To load sample applicants for testing, '
                        . 'run: php modules/enrollment/database/seed_sample_applications.php'
                ); ?>
            </div>
        <?php else: ?>
            <div class="mpl-table-wrap">
                <table class="mpl-table">
                    <thead>
                        <tr>
                            <th>Reference No.</th>
                            <th>Applicant Name</th>
                            <th>Program</th>
                            <th>Applicant Type</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php $name = enrApplicantName($row); ?>
                            <tr data-enr-row-link="<?= htmlspecialchars(enrPageUrl('pre-registration-details.php', ['ref' => $row['reference_no']])) ?>">
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
                                <td><?= htmlspecialchars(enrFormatDate($row['submitted_at'])) ?></td>
                                <td><?= enrStatusBadge((string) $row['status']) ?></td>
                                <td>
                                    <a class="enr-btn enr-btn-soft enr-btn-sm"
                                       href="<?= htmlspecialchars(enrPageUrl('pre-registration-details.php', ['ref' => $row['reference_no']])) ?>">
                                        <?= smsIcon('eye', ['aria-hidden' => 'true']) ?> Open
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php enrRenderPagination(
                $page,
                $totalPages,
                $totalRows,
                'pre-registration-queue.php',
                $activeFilters
            ); ?>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
