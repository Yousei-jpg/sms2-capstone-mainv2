<?php
/**
 * SMS 2 - Online Pre-Registration Repository
 *
 * Every database read and write for the Online Pre-Registration subsystem.
 * Screens call these functions and never build SQL themselves.
 *
 * All statements are prepared. Multi-table writes run inside a transaction.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/enrollment.php';
require_once __DIR__ . '/providers/enrollment-providers.php';
require_once ROOT_PATH . '/includes/audit.php';

/**
 * Columns selected for list and detail views. Program and period are joined
 * for display only; the application stores ids, never the labels.
 */
function enrApplicationSelectSql(): string
{
    return 'SELECT a.*,
                   p.code  AS program_code,
                   p.name  AS program_name,
                   ap.school_year,
                   ap.semester
            FROM enr_applications a
            LEFT JOIN enr_programs         p  ON p.id  = a.program_id
            LEFT JOIN enr_academic_periods ap ON ap.id = a.academic_period_id';
}

/**
 * Build the WHERE clause and bindings shared by the queue list and its count.
 *
 * @param array<string, mixed> $filters
 * @return array{0:string, 1:array<string, mixed>}
 */
function enrBuildApplicationFilters(array $filters): array
{
    $where = [];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        // One concatenated haystack, one placeholder. A named placeholder cannot
        // be repeated when PDO::ATTR_EMULATE_PREPARES is false, and building the
        // haystack in SQL also lets "First Last" match even when a middle name
        // sits between them in the record.
        $where[] = '(CONCAT_WS(" ",
                        a.reference_no,
                        a.email,
                        a.first_name,
                        a.middle_name,
                        a.last_name,
                        CONCAT_WS(" ", a.first_name, a.last_name)
                    ) LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && in_array($status, enrApplicationStatuses(), true)) {
        $where[] = 'a.status = :status';
        $params[':status'] = $status;
    }

    $programId = (int) ($filters['program_id'] ?? 0);
    if ($programId > 0) {
        $where[] = 'a.program_id = :program_id';
        $params[':program_id'] = $programId;
    }

    $applicantType = trim((string) ($filters['applicant_type'] ?? ''));
    if ($applicantType !== '' && in_array($applicantType, enrApplicantTypes(), true)) {
        $where[] = 'a.applicant_type = :applicant_type';
        $params[':applicant_type'] = $applicantType;
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'DATE(a.submitted_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'DATE(a.submitted_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    return [$sql, $params];
}

/**
 * One page of the application queue.
 *
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function enrFindApplications(PDO $pdo, array $filters = [], int $page = 1, int $perPage = 10): array
{
    [$whereSql, $params] = enrBuildApplicationFilters($filters);

    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    // LIMIT/OFFSET are cast integers, never interpolated user input.
    $sql = enrApplicationSelectSql() . $whereSql
        . ' ORDER BY a.submitted_at DESC, a.id DESC'
        . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

/**
 * Total rows matching the same filters, for pagination.
 *
 * @param array<string, mixed> $filters
 */
function enrCountApplications(PDO $pdo, array $filters = []): int
{
    [$whereSql, $params] = enrBuildApplicationFilters($filters);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM enr_applications a' . $whereSql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * Count per status, for the dashboard summary cards. Statuses with no rows
 * come back as 0 rather than being absent, so the dashboard shows a true zero
 * state instead of a blank.
 *
 * @return array<string, int>
 */
function enrCountByStatus(PDO $pdo): array
{
    $counts = array_fill_keys(enrApplicationStatuses(), 0);
    $counts['Total'] = 0;

    $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM enr_applications GROUP BY status');
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $status = (string) $row['status'];
        $total = (int) $row['total'];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = $total;
        }
        $counts['Total'] += $total;
    }

    return $counts;
}

/**
 * Load one application by reference number.
 *
 * @return array<string, mixed>|null
 */
function enrFindApplicationByReference(PDO $pdo, string $referenceNo): ?array
{
    $referenceNo = trim($referenceNo);
    if ($referenceNo === '') {
        return null;
    }

    $stmt = $pdo->prepare(enrApplicationSelectSql() . ' WHERE a.reference_no = ? LIMIT 1');
    $stmt->execute([$referenceNo]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Decision history for an application, newest first.
 *
 * @return list<array<string, mixed>>
 */
function enrApplicationReviews(PDO $pdo, int $applicationId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, u.full_name AS reviewer_name, u.role_key AS reviewer_role
         FROM enr_application_reviews r
         LEFT JOIN users u ON u.id = r.reviewed_by
         WHERE r.application_id = ?
         ORDER BY r.reviewed_at DESC, r.id DESC'
    );
    $stmt->execute([$applicationId]);

    return $stmt->fetchAll() ?: [];
}

/**
 * The most recent decision, or null when the application has never been acted on.
 *
 * @return array<string, mixed>|null
 */
function enrLatestReview(PDO $pdo, int $applicationId): ?array
{
    $reviews = enrApplicationReviews($pdo, $applicationId);
    foreach ($reviews as $review) {
        if ((string) $review['decision'] !== 'Draft') {
            return $review;
        }
    }
    return null;
}

/**
 * The saved validation draft, if the Registrar saved one without deciding.
 *
 * @return array<string, mixed>|null
 */
function enrLatestDraft(PDO $pdo, int $applicationId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM enr_application_reviews
         WHERE application_id = ? AND decision = ?
         ORDER BY reviewed_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$applicationId, 'Draft']);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Move an application to "For Review" the first time a Registrar opens it.
 * Submitted -> For Review is the only transition this performs, and it is
 * skipped silently when the application is already past that point.
 */
function enrMarkUnderReview(PDO $pdo, array $application, int $userId): bool
{
    $status = (string) $application['status'];
    if ($status !== ENR_STATUS_SUBMITTED) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE enr_applications SET status = ? WHERE id = ? AND status = ?');
    $stmt->execute([ENR_STATUS_FOR_REVIEW, (int) $application['id'], ENR_STATUS_SUBMITTED]);

    if ($stmt->rowCount() === 0) {
        return false;
    }

    logActivity(
        'status_change',
        'Pre-registration ' . $application['reference_no'] . ' opened for review ('
            . ENR_STATUS_SUBMITTED . ' -> ' . ENR_STATUS_FOR_REVIEW . ')',
        'enrollment',
        $userId > 0 ? $userId : null
    );

    return true;
}

/**
 * Persist a validation checklist without deciding anything.
 *
 * @param array<string, mixed> $checklist
 */
function enrSaveValidationDraft(
    PDO $pdo,
    array $application,
    array $checklist,
    string $remarks,
    int $userId
): bool {
    $applicationId = (int) $application['id'];
    $status = (string) $application['status'];

    // A draft never changes status, so previous and new are the same.
    $stmt = $pdo->prepare(
        'INSERT INTO enr_application_reviews
            (application_id, decision, checklist_json, remarks, previous_status, new_status, reviewed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $applicationId,
        'Draft',
        json_encode($checklist, JSON_UNESCAPED_UNICODE),
        $remarks !== '' ? $remarks : null,
        $status,
        $status,
        $userId > 0 ? $userId : null,
    ]);

    logActivity(
        'save_draft',
        'Validation draft saved for pre-registration ' . $application['reference_no'],
        'enrollment',
        $userId > 0 ? $userId : null
    );

    return true;
}

/**
 * Record a Registrar decision and move the application to its new status.
 *
 * Both writes happen in one transaction: the decision record and the status
 * change either both land or neither does.
 *
 * The UPDATE re-checks the current status in its WHERE clause. If another
 * Registrar decided the same application while this screen was open, zero rows
 * are affected and the caller is told the record is stale.
 *
 * @param array<string, mixed> $checklist
 * @return array{ok:bool, message:string, stale:bool}
 */
function enrRecordDecision(
    PDO $pdo,
    array $application,
    string $newStatus,
    string $decision,
    array $checklist,
    string $remarks,
    int $userId
): array {
    $applicationId = (int) $application['id'];
    $referenceNo = (string) $application['reference_no'];
    $currentStatus = (string) $application['status'];

    if (!enrCanTransition($currentStatus, $newStatus)) {
        return [
            'ok' => false,
            'stale' => false,
            'message' => enrTransitionError($currentStatus, $newStatus),
        ];
    }

    $pdo->beginTransaction();

    try {
        $update = $pdo->prepare(
            'UPDATE enr_applications SET status = ? WHERE id = ? AND status = ?'
        );
        $update->execute([$newStatus, $applicationId, $currentStatus]);

        if ($update->rowCount() === 0) {
            $pdo->rollBack();

            // Re-read to tell the Registrar what it actually is now.
            $fresh = enrFindApplicationByReference($pdo, $referenceNo);
            $actual = $fresh ? (string) $fresh['status'] : 'unknown';

            return [
                'ok' => false,
                'stale' => true,
                'message' => 'This application changed while you had it open. It was "'
                    . $currentStatus . '" when you opened it and is now "' . $actual . '". '
                    . 'Reload the application and review the latest decision before deciding again.',
            ];
        }

        $insert = $pdo->prepare(
            'INSERT INTO enr_application_reviews
                (application_id, decision, checklist_json, remarks, previous_status, new_status, reviewed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $applicationId,
            $decision,
            json_encode($checklist, JSON_UNESCAPED_UNICODE),
            $remarks !== '' ? $remarks : null,
            $currentStatus,
            $newStatus,
            $userId > 0 ? $userId : null,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Enrollment decision failed: ' . $e->getMessage());

        return [
            'ok' => false,
            'stale' => false,
            'message' => 'The decision could not be saved because the database rejected the change. '
                . 'Nothing was recorded. Try again, and if this repeats, report it with reference ' . $referenceNo . '.',
        ];
    }

    logActivity(
        'decision',
        'Pre-registration ' . $referenceNo . ': ' . $decision
            . ' (' . $currentStatus . ' -> ' . $newStatus . ')'
            . ($remarks !== '' ? ' | Remarks: ' . $remarks : ''),
        'enrollment',
        $userId > 0 ? $userId : null
    );

    return ['ok' => true, 'stale' => false, 'message' => ''];
}

/**
 * Audit entries this module wrote, for the history panel.
 *
 * @return list<array<string, mixed>>
 */
function enrRecentActivity(PDO $pdo, int $limit = 8): array
{
    $limit = max(1, min(50, $limit));

    try {
        $stmt = $pdo->prepare(
            'SELECT action, detail, user_name, created_at
             FROM activity_logs
             WHERE module_key = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['enrollment']);

        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('Enrollment activity read failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Has the Enrollment schema been installed?
 * Lets a screen show a clear setup message instead of a fatal SQL error.
 */
function enrSchemaInstalled(PDO $pdo): bool
{
    static $installed = null;
    if ($installed !== null) {
        return $installed;
    }

    try {
        // information_schema rather than SHOW TABLES LIKE ?: MySQL rejects a
        // bound parameter inside a SHOW statement when prepares are not emulated.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute(['enr_applications']);
        $installed = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $installed = false;
    }

    return $installed;
}

/**
 * Full name for display, skipping an absent middle name.
 *
 * @param array<string, mixed> $application
 */
function enrApplicantName(array $application): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($application['first_name'] ?? '')),
        trim((string) ($application['middle_name'] ?? '')),
        trim((string) ($application['last_name'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));
}
