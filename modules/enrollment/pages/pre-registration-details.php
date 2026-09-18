<?php
/**
 * SMS 2 - Online Pre-registration : Screen 3 - Application Details
 * Module: Enrollment Management -> Registration
 *
 * The complete submitted application, read-only. The Registrar reviews what the
 * applicant supplied and never retypes it. Opening an application that is still
 * "Submitted" moves it to "For Review" and records that in the audit trail.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../includes/prereg-repository.php';
require_once __DIR__ . '/../includes/prereg-ui.php';

$referenceNo = trim((string) ($_GET['ref'] ?? ''));
$tab = trim((string) ($_GET['tab'] ?? 'personal'));
if (!in_array($tab, ['personal', 'academic', 'program', 'documents', 'history'], true)) {
    $tab = 'personal';
}

$pageTitle    = 'Application Details';
$activeModule = 'enrollment';
$activePage   = 'online-pre-registration';
$breadcrumbs  = [
    ['label' => 'Enrollment Management', 'url' => BASE_URL . '/modules/enrollment/index.php'],
    ['label' => 'Online Pre-registration', 'url' => BASE_URL . '/modules/enrollment/pages/online-pre-registration.php'],
    ['label' => 'Applications', 'url' => BASE_URL . '/modules/enrollment/pages/pre-registration-queue.php'],
    ['label' => $referenceNo !== '' ? $referenceNo : 'Application', 'url' => null],
];
$pageBannerIcon = 'fa-file-alt';
$pageBannerDescription = 'Review every detail the applicant submitted before deciding.';
$pageBannerBackUrl = BASE_URL . '/modules/enrollment/pages/pre-registration-queue.php';
$pageBannerBackLabel = 'Back to Queue';

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

$pdo = db();
$schemaReady = $pdo instanceof PDO && enrSchemaInstalled($pdo);
$application = null;
$reviews = [];
$documents = [];
$documentsAvailable = false;
$openedForReview = false;
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($schemaReady && $referenceNo !== '') {
    try {
        $application = enrFindApplicationByReference($pdo, $referenceNo);

        if ($application !== null) {
            // Submitted -> For Review, the moment a Registrar actually opens it.
            $openedForReview = enrMarkUnderReview($pdo, $application, $userId);
            if ($openedForReview) {
                $application['status'] = ENR_STATUS_FOR_REVIEW;
            }

            logActivity(
                'view',
                'Opened pre-registration application ' . $application['reference_no'],
                'enrollment',
                $userId > 0 ? $userId : null
            );

            $reviews = enrApplicationReviews($pdo, (int) $application['id']);

            $docProvider = enrDocumentStatusProvider();
            $documentsAvailable = $docProvider->isAvailable();
            $documents = $documentsAvailable
                ? $docProvider->documentsForApplication((int) $application['id'])
                : [];
        }
    } catch (Throwable $e) {
        error_log('Application details load failed: ' . $e->getMessage());
    }
}
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php enrPageStyles(); ?>

<?php enrRenderWorkflowSteps(3, $application !== null ? (string) $application['reference_no'] : null); ?>

<?php if (!$schemaReady): ?>

    <?php enrRenderState(
        'setup',
        'The Enrollment schema has not been installed yet',
        'Run php modules/enrollment/database/install_enrollment.php, then reload this page.'
    ); ?>

<?php elseif ($referenceNo === ''): ?>

    <?php enrRenderState(
        'error',
        'No application was selected',
        'This screen needs a reference number. Open an application from the queue instead of '
            . 'navigating here directly.',
        ['label' => 'Go to Application Queue', 'url' => enrPageUrl('pre-registration-queue.php')]
    ); ?>

<?php elseif ($application === null): ?>

    <?php enrRenderState(
        'no-results',
        'Application ' . $referenceNo . ' was not found',
        'No application carries this reference number. It may have been removed, or the '
            . 'reference may be mistyped.',
        ['label' => 'Back to Application Queue', 'url' => enrPageUrl('pre-registration-queue.php')]
    ); ?>

<?php else: ?>

    <?php
    $name = enrApplicantName($application);
    $status = (string) $application['status'];
    $ref = (string) $application['reference_no'];
    $isDecided = in_array($status, [ENR_STATUS_APPROVED, ENR_STATUS_REJECTED, ENR_STATUS_PROCESSED], true);
    $canValidate = in_array($status, [ENR_STATUS_FOR_REVIEW, ENR_STATUS_SUBMITTED], true);
    ?>

    <?php if ($openedForReview): ?>
        <?php enrRenderAlert(
            'info',
            'This application is now marked "For Review".',
            'It was "Submitted" until you opened it. The change was recorded in the audit trail.'
        ); ?>
    <?php endif; ?>

    <!-- Applicant header -->
    <section class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="enr-applicant-head">
                <span class="enr-avatar"><?= htmlspecialchars(enrInitials($name)) ?></span>
                <div class="flex-grow-1">
                    <h5 class="mb-1 fw-semibold"><?= htmlspecialchars($name) ?></h5>
                    <div class="text-muted small">
                        <?= htmlspecialchars($ref) ?> &middot;
                        <?= htmlspecialchars((string) $application['applicant_type']) ?> Applicant
                    </div>
                </div>
                <div><?= enrStatusBadge($status) ?></div>
            </div>

            <hr class="my-3">

            <dl class="enr-meta-grid mb-0">
                <div>
                    <dt>Email</dt>
                    <dd><?= htmlspecialchars((string) $application['email']) ?></dd>
                </div>
                <div>
                    <dt>Mobile</dt>
                    <dd><?= htmlspecialchars((string) ($application['mobile_no'] ?: '—')) ?></dd>
                </div>
                <div>
                    <dt>Date Submitted</dt>
                    <dd><?= htmlspecialchars(enrFormatDate($application['submitted_at'])) ?></dd>
                </div>
                <div>
                    <dt>Last Updated</dt>
                    <dd><?= htmlspecialchars(enrFormatDate($application['updated_at'])) ?></dd>
                </div>
            </dl>
        </div>
    </section>

    <!-- Tabs -->
    <?php
    $tabs = [
        'personal'  => 'Personal Information',
        'academic'  => 'Academic Information',
        'program'   => 'Program & Intake',
        'documents' => 'Documents',
        'history'   => 'Decision History',
    ];
    ?>
    <ul class="nav nav-tabs mb-0" role="tablist">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $tab === $key ? ' active' : '' ?>"
                   href="<?= htmlspecialchars(enrPageUrl('pre-registration-details.php', ['ref' => $ref, 'tab' => $key])) ?>">
                    <?= htmlspecialchars($label) ?>
                    <?php if ($key === 'history' && $reviews): ?>
                        <span class="badge text-bg-secondary ms-1"><?= count($reviews) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <section class="card border-0 shadow-sm rounded-top-0 mb-3">
        <div class="card-body">

            <?php if ($tab === 'personal'): ?>
                <div class="row g-3">
                    <?php enrReadOnlyField('First Name', $application['first_name']); ?>
                    <?php enrReadOnlyField('Last Name', $application['last_name']); ?>
                    <?php enrReadOnlyField('Middle Name', $application['middle_name']); ?>
                    <?php enrReadOnlyField('Date of Birth', enrFormatDate($application['birth_date'])); ?>
                    <?php enrReadOnlyField('Sex', $application['sex']); ?>
                    <?php enrReadOnlyField('Civil Status', $application['civil_status']); ?>
                    <?php enrReadOnlyField('Email Address', $application['email']); ?>
                    <?php enrReadOnlyField('Mobile Number', $application['mobile_no']); ?>
                    <div class="col-12">
                        <label class="form-label small text-muted mb-1">Current Address</label>
                        <div class="enr-readonly<?= trim((string) $application['current_address']) === '' ? ' is-empty' : '' ?>">
                            <?= htmlspecialchars(trim((string) $application['current_address']) !== ''
                                ? (string) $application['current_address']
                                : 'Not provided') ?>
                        </div>
                    </div>
                </div>

            <?php elseif ($tab === 'academic'): ?>
                <div class="row g-3">
                    <?php enrReadOnlyField('Previous School', $application['previous_school']); ?>
                    <?php enrReadOnlyField('School Type', $application['school_type']); ?>
                    <?php enrReadOnlyField('Year Graduated', $application['year_graduated'] ? (string) $application['year_graduated'] : ''); ?>
                    <?php enrReadOnlyField('Grade Average', $application['grade_average'] !== null ? (string) $application['grade_average'] : ''); ?>
                    <?php enrReadOnlyField('Previous Program / Strand', $application['previous_program']); ?>
                </div>

            <?php elseif ($tab === 'program'): ?>
                <div class="row g-3">
                    <?php
                    $programLabel = $application['program_code']
                        ? $application['program_code'] . ' — ' . $application['program_name']
                        : '';
                    ?>
                    <?php enrReadOnlyField('Selected Program', $programLabel, 'No program selected'); ?>
                    <?php enrReadOnlyField('Entry Level', $application['entry_level']); ?>
                    <?php enrReadOnlyField('Academic Year', $application['school_year'], 'No academic period set'); ?>
                    <?php enrReadOnlyField('Semester', $application['semester'], 'No academic period set'); ?>
                    <?php enrReadOnlyField('Campus', $application['campus']); ?>
                    <?php enrReadOnlyField('Preferred Section', $application['preferred_section'], 'No preference given'); ?>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    <?= smsIcon('info-circle', ['aria-hidden' => 'true']) ?>
                    Preferred section is a request, not a reservation. Section placement is decided
                    later by Auto Section Assignment.
                </p>

            <?php elseif ($tab === 'documents'): ?>
                <?php if (!$documentsAvailable): ?>
                    <?php enrRenderState(
                        'empty',
                        'Document verification is not available yet',
                        'Uploads, verification, and replacement requests belong to the Document Upload '
                            . 'Portal, which has not been built yet. Rather than show a document list '
                            . 'that is not backed by real data, this tab reports the gap. The two '
                            . 'document rules on the validation screen raise a warning for the same reason.'
                    ); ?>
                <?php elseif (!$documents): ?>
                    <?php enrRenderState(
                        'empty',
                        'No documents uploaded',
                        'This applicant has not uploaded any documents yet.'
                    ); ?>
                <?php else: ?>
                    <div class="mpl-table-wrap">
                        <table class="mpl-table">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Upload Status</th>
                                    <th>Verification</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $doc['name']) ?></td>
                                        <td><?= htmlspecialchars((string) $doc['upload_status']) ?></td>
                                        <td><?= enrStatusBadge((string) $doc['verification_status']) ?></td>
                                        <td>
                                            <a class="enr-btn enr-btn-ghost enr-btn-sm"
                                               href="<?= BASE_URL ?>/modules/enrollment/pages/document-upload-portal.php">
                                                <?= smsIcon('eye', ['aria-hidden' => 'true']) ?> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <?php if (!$reviews): ?>
                    <?php enrRenderState(
                        'empty',
                        'No decision has been recorded yet',
                        'Once this application is validated, approved, returned, or rejected, each '
                            . 'decision will be listed here with its remarks and reviewer.'
                    ); ?>
                <?php else: ?>
                    <div class="mpl-table-wrap">
                        <table class="mpl-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Decision</th>
                                    <th>Status Change</th>
                                    <th>Reviewer</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reviews as $review): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(enrFormatDateTime($review['reviewed_at'])) ?></td>
                                        <td><?= enrStatusBadge((string) $review['decision']) ?></td>
                                        <td class="small text-muted">
                                            <?= htmlspecialchars((string) ($review['previous_status'] ?? '—')) ?>
                                            &rarr;
                                            <?= htmlspecialchars((string) $review['new_status']) ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($review['reviewer_name'] ?? 'System')) ?></td>
                                        <td class="small"><?= htmlspecialchars((string) ($review['remarks'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </section>

    <!-- Actions -->
    <div class="d-flex flex-wrap gap-2">
        <a class="enr-btn enr-btn-ghost"
           href="<?= htmlspecialchars(enrPageUrl('pre-registration-queue.php')) ?>">
            <?= smsIcon('arrow-left', ['aria-hidden' => 'true']) ?> Back to Queue
        </a>

        <?php if ($canValidate): ?>
            <a class="enr-btn ms-auto"
               href="<?= htmlspecialchars(enrPageUrl('pre-registration-validate.php', ['ref' => $ref])) ?>">
                <?= smsIcon('clipboard-check', ['aria-hidden' => 'true']) ?> Proceed to Validation <?= smsIcon('arrow-right', ['aria-hidden' => 'true']) ?>
            </a>
        <?php elseif ($isDecided): ?>
            <a class="enr-btn enr-btn-soft ms-auto"
               href="<?= htmlspecialchars(enrPageUrl('pre-registration-result.php', ['ref' => $ref])) ?>">
                <?= smsIcon('file-check', ['aria-hidden' => 'true']) ?> View Processed Result <?= smsIcon('arrow-right', ['aria-hidden' => 'true']) ?>
            </a>
        <?php elseif ($status === ENR_STATUS_FOR_CORRECTION): ?>
            <span class="ms-auto text-muted small align-self-center">
                <?= smsIcon('info-circle', ['aria-hidden' => 'true']) ?>
                Returned to the applicant for correction. Validation resumes once they resubmit.
            </span>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
