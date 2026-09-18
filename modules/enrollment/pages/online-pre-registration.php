<?php
/**
 * SMS 2 - Online Pre-registration : Screen 1 - Registrar Dashboard
 * Module: Enrollment Management -> Registration
 *
 * Overview of pre-registration activity. Summary cards and rows link into the
 * Application Queue with the matching filter already applied. Every figure is
 * counted from enr_applications; nothing on this page is fabricated.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/prereg-repository.php';
require_once __DIR__ . '/../includes/prereg-ui.php';

$pageTitle    = 'Online Pre-registration';
$activeModule = 'enrollment';
$activePage   = 'online-pre-registration';
$breadcrumbs  = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Online Pre-registration', 'url' => null],
];
$pageBannerIcon = 'fa-globe';
$pageBannerDescription = 'Review, validate, and decide on applications submitted through pre-registration.';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

// layout-start.php has already run requireAuth() and requireModuleAccess().
$pdo = db();
$schemaReady = $pdo instanceof PDO && enrSchemaInstalled($pdo);

$counts = [];
$recent = [];
$activity = [];
$loadError = '';
$activePeriod = null;

if ($schemaReady) {
    try {
        $counts = enrCountByStatus($pdo);
        $recent = enrFindApplications($pdo, [], 1, 8);
        $activity = enrRecentActivity($pdo, 6);
        $activePeriod = enrAcademicPeriodProvider($pdo)->activePeriod();
    } catch (Throwable $e) {
        error_log('Pre-registration dashboard load failed: ' . $e->getMessage());
        $loadError = $e->getMessage();
    }
}
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrRenderWorkflowSteps(1); ?>

<?php if (!$pdo instanceof PDO): ?>

    <?php enrRenderState(
        'error',
        'The database is unavailable',
        'SMS 2 could not connect to MySQL, so no application data can be shown. '
            . 'Start MySQL in the XAMPP control panel, then reload this page.'
    ); ?>

<?php elseif (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The Enrollment schema has not been installed yet',
        'The enr_applications table does not exist in this database. Run the Enrollment '
            . 'installer from the command line, then reload this page: '
            . 'php modules/enrollment/database/install_enrollment.php'
    ); ?>

<?php elseif ($loadError !== ''): ?>

    <?php enrRenderAlert(
        'danger',
        'The dashboard could not load its figures.',
        'The database rejected the query. Nothing was changed.',
        'Reload the page. If this repeats, check the PHP error log.'
    ); ?>

<?php else: ?>

    <?php
    $forReviewCount = (int) ($counts[ENR_STATUS_FOR_REVIEW] ?? 0)
        + (int) ($counts[ENR_STATUS_SUBMITTED] ?? 0);

    $cards = [
        [
            'label' => 'Total Applications',
            'value' => (int) ($counts['Total'] ?? 0),
            'icon'  => 'fa-users',
            'tone'  => 'blue',
            'url'   => enrPageUrl('pre-registration-queue.php'),
        ],
        [
            'label' => 'For Review',
            'value' => $forReviewCount,
            'icon'  => 'fa-clock',
            'tone'  => 'amber',
            'url'   => enrPageUrl('pre-registration-queue.php', ['status' => ENR_STATUS_FOR_REVIEW]),
        ],
        [
            'label' => 'For Correction',
            'value' => (int) ($counts[ENR_STATUS_FOR_CORRECTION] ?? 0),
            'icon'  => 'fa-exclamation-circle',
            'tone'  => 'purple',
            'url'   => enrPageUrl('pre-registration-queue.php', ['status' => ENR_STATUS_FOR_CORRECTION]),
        ],
        [
            'label' => 'Approved',
            'value' => (int) ($counts[ENR_STATUS_APPROVED] ?? 0),
            'icon'  => 'fa-check-circle',
            'tone'  => 'green',
            'url'   => enrPageUrl('pre-registration-queue.php', ['status' => ENR_STATUS_APPROVED]),
        ],
        [
            'label' => 'Released',
            'value' => (int) ($counts[ENR_STATUS_PROCESSED] ?? 0),
            'icon'  => 'fa-paper-plane',
            'tone'  => 'blue',
            'url'   => enrPageUrl('pre-registration-queue.php', ['status' => ENR_STATUS_PROCESSED]),
        ],
    ];
    ?>

    <?php enrRenderStatCards($cards); ?>

    <?php if ($activePeriod === null): ?>
        <?php enrRenderAlert(
            'warning',
            'No active academic period is set.',
            'Applications can still be reviewed, but the academic-period rule will raise a '
                . 'warning on every application until one period is marked active.',
            'Set is_active = 1 on the correct row in enr_academic_periods.'
        ); ?>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <section class="mpl-panel">
                <div class="mpl-panel-head">
                    <div>
                        <h2>Recent Applications</h2>
                        <p>The most recently submitted pre-registration applications.</p>
                    </div>
                    <a class="mpl-btn mpl-btn-ghost mpl-btn-sm"
                       href="<?= htmlspecialchars(enrPageUrl('pre-registration-queue.php')) ?>">
                        <?= smsIcon('list', ['aria-hidden' => 'true']) ?> View All
                    </a>
                </div>

                <?php if (!$recent): ?>
                    <div class="p-4">
                        <?php enrRenderState(
                            'empty',
                            'No applications have been submitted yet',
                            'Once applicants submit through the pre-registration form they will appear '
                                . 'here and in the Application Queue. To load sample applicants for '
                                . 'testing, run: php modules/enrollment/database/seed_sample_applications.php'
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
                                    <th>Submitted</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $row): ?>
                                    <?php $name = enrApplicantName($row); ?>
                                    <tr>
                                        <td class="ref"><?= htmlspecialchars((string) $row['reference_no']) ?></td>
                                        <td>
                                            <div class="mpl-person">
                                                <span class="mpl-avatar"><?= htmlspecialchars(enrInitials($name)) ?></span>
                                                <div>
                                                    <strong><?= htmlspecialchars($name) ?></strong>
                                                    <small><?= htmlspecialchars((string) $row['applicant_type']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($row['program_code'] ?? '—')) ?></td>
                                        <td><?= htmlspecialchars(enrFormatDate($row['submitted_at'])) ?></td>
                                        <td><?= enrStatusBadge((string) $row['status']) ?></td>
                                        <td>
                                            <div class="mpl-actions">
                                                <a href="<?= htmlspecialchars(enrPageUrl('pre-registration-details.php', ['ref' => $row['reference_no']])) ?>"
                                                   title="Open application" aria-label="Open application">
                                                    <?= smsIcon('eye') ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-lg-4">
            <section class="mpl-panel h-100">
                <div class="mpl-panel-head">
                    <div>
                        <h2>Recent Activity</h2>
                        <p>Actions recorded in the Enrollment audit trail.</p>
                    </div>
                </div>
                <div class="p-3">
                    <?php if (!$activity): ?>
                        <p class="text-muted small mb-0">
                            No enrollment actions have been recorded yet. Opening, validating, and
                            deciding on an application will each appear here.
                        </p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($activity as $entry): ?>
                                <li class="pb-3 mb-3 border-bottom">
                                    <div class="small fw-semibold text-body">
                                        <?= htmlspecialchars((string) $entry['detail']) ?>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <?= htmlspecialchars((string) ($entry['user_name'] ?? 'System')) ?>
                                        &middot;
                                        <?= htmlspecialchars(enrFormatDateTime($entry['created_at'])) ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
