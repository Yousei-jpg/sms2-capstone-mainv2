<?php
/**
 * SMS 2 - Document Upload Portal: database access
 * Module: Enrollment  |  Subsystem: Registration -> Document Upload Portal
 *
 * Every query the portal runs lives here. Screens never write SQL directly.
 * All statements are prepared. Writes that touch more than one table run
 * inside a transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/docs-workflow.php';

// This layer writes audit entries, so it pulls in the audit helper itself rather
// than assuming whichever page or CLI script included it first has.
require_once ROOT_PATH . '/includes/audit.php';

// NOTE: this file deliberately does NOT require enrollment-providers.php.
// The dependency runs one way only — the providers call functions defined here,
// never the reverse. Requiring it back created a circular require_once whose
// resolution depended on which file loaded first, which left enrProgramProvider()
// undefined when a page entered through this file. Pages that need a provider
// include it themselves.

/**
 * Has the document schema been installed yet? Screens use this to show a
 * setup state instead of a fatal error when the migration has not been run.
 */
function enrDocSchemaInstalled(PDO $pdo): bool
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

        foreach (['enr_document_requirements', 'enr_application_documents', 'enr_document_verifications'] as $table) {
            $stmt->execute([$table]);
            if ((int) $stmt->fetchColumn() === 0) {
                $installed = false;
                return false;
            }
        }
    } catch (Throwable $e) {
        error_log('Document schema check failed: ' . $e->getMessage());
        $installed = false;
        return false;
    }

    $installed = true;
    return true;
}

// ---------------------------------------------------------------------------
// Requirements
// ---------------------------------------------------------------------------

/**
 * Active requirements for an applicant type, in display order.
 *
 * @return list<array<string,mixed>>
 */
function enrDocRequirements(PDO $pdo, string $applicantType): array
{
    $stmt = $pdo->prepare(
        'SELECT id, applicant_type, document_name, description, is_required, sort_order
           FROM enr_document_requirements
          WHERE applicant_type = ?
            AND is_active = 1
          ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$applicantType]);
    return $stmt->fetchAll() ?: [];
}

// ---------------------------------------------------------------------------
// Documents
// ---------------------------------------------------------------------------

/**
 * Every uploaded document for one application, newest first.
 *
 * @return list<array<string,mixed>>
 */
function enrDocsForApplication(PDO $pdo, int $applicationId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, application_id, requirement_id, document_name, status,
                original_name, stored_name, mime_type, file_size,
                ocr_status, ocr_text, ocr_ran_at,
                uploaded_at, uploaded_by, updated_at
           FROM enr_application_documents
          WHERE application_id = ?
          ORDER BY id DESC'
    );
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * One document with the application it belongs to, for the verification screen
 * and the file-serving endpoint.
 *
 * @return array<string,mixed>|null
 */
function enrDocFind(PDO $pdo, int $documentId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT d.*,
                a.reference_no, a.first_name, a.middle_name, a.last_name,
                a.applicant_type, a.email, a.mobile_no, a.status AS application_status,
                p.code AS program_code, p.name AS program_name
           FROM enr_application_documents d
           JOIN enr_applications a ON a.id = d.application_id
      LEFT JOIN enr_programs p     ON p.id = a.program_id
          WHERE d.id = ?
          LIMIT 1'
    );
    $stmt->execute([$documentId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * The full document picture for one application: requirements merged with
 * uploads, plus the completeness rollup.
 *
 * @return array{checklist:list<array<string,mixed>>, completeness:array<string,mixed>}
 */
function enrDocApplicationSummary(PDO $pdo, array $application): array
{
    $requirements = enrDocRequirements($pdo, (string) $application['applicant_type']);
    $documents    = enrDocsForApplication($pdo, (int) $application['id']);
    $checklist    = enrDocChecklist($requirements, $documents);

    return [
        'checklist'    => $checklist,
        'completeness' => enrDocCompleteness($checklist),
    ];
}

/**
 * Verification history for one document, oldest first so it reads as a story.
 *
 * @return list<array<string,mixed>>
 */
function enrDocVerifications(PDO $pdo, int $documentId): array
{
    $stmt = $pdo->prepare(
        'SELECT v.*, u.full_name AS verifier_name, u.username AS verifier_username
           FROM enr_document_verifications v
      LEFT JOIN users u ON u.id = v.verified_by
          WHERE v.document_id = ?
          ORDER BY v.id ASC'
    );
    $stmt->execute([$documentId]);
    return $stmt->fetchAll() ?: [];
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/**
 * Store an uploaded file's metadata after smsSecureUpload() has placed it on
 * disk. Returns the new document id.
 *
 * A re-upload against a requirement that previously needed replacement creates
 * a NEW row rather than editing the old one, so the rejected file and the
 * reason it was rejected both survive.
 *
 * @param array{stored_name:string, original_name:string, mime:string, size:int} $stored
 */
function enrDocStoreUpload(
    PDO $pdo,
    int $applicationId,
    ?int $requirementId,
    string $documentName,
    array $stored,
    ?int $uploadedBy
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO enr_application_documents
            (application_id, requirement_id, document_name, status,
             original_name, stored_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $applicationId,
        $requirementId > 0 ? $requirementId : null,
        $documentName,
        ENR_DOC_FOR_VERIFICATION,
        $stored['original_name'],
        $stored['stored_name'],
        $stored['mime'],
        $stored['size'],
        $uploadedBy !== null && $uploadedBy > 0 ? $uploadedBy : null,
    ]);

    $documentId = (int) $pdo->lastInsertId();

    logActivity(
        'document_upload',
        'Document uploaded: ' . $documentName . ' (document #' . $documentId . ')',
        'enrollment',
        $uploadedBy !== null && $uploadedBy > 0 ? $uploadedBy : null
    );

    return $documentId;
}

/**
 * Record a verification decision and move the document to its new status.
 *
 * Both writes land in one transaction. The UPDATE re-checks the current status
 * in its WHERE clause, so if another Registrar verified the same document
 * while this screen was open, zero rows change and the caller is told the
 * record is stale rather than silently overwriting them.
 *
 * @return array{ok:bool, stale:bool, message:string}
 */
function enrDocRecordVerification(
    PDO $pdo,
    array $document,
    string $newStatus,
    string $decision,
    string $remarks,
    int $userId
): array {
    $documentId    = (int) $document['id'];
    $documentName  = (string) $document['document_name'];
    $currentStatus = (string) $document['status'];

    if (!enrDocCanTransition($currentStatus, $newStatus)) {
        return [
            'ok'      => false,
            'stale'   => false,
            'message' => enrDocTransitionError($currentStatus, $newStatus, $documentName),
        ];
    }

    $pdo->beginTransaction();

    try {
        $update = $pdo->prepare(
            'UPDATE enr_application_documents
                SET status = ?
              WHERE id = ?
                AND status = ?'
        );
        $update->execute([$newStatus, $documentId, $currentStatus]);

        if ($update->rowCount() === 0) {
            $pdo->rollBack();

            $fresh = enrDocFind($pdo, $documentId);
            $now   = $fresh !== null ? (string) $fresh['status'] : 'unknown';

            return [
                'ok'      => false,
                'stale'   => true,
                'message' => $documentName . ' changed while you had it open. It was "'
                    . $currentStatus . '" when you opened it and is now "' . $now
                    . '". Reload the document and review the latest decision before deciding again.',
            ];
        }

        $insert = $pdo->prepare(
            'INSERT INTO enr_document_verifications
                (document_id, decision, remarks, previous_status, new_status, verified_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $documentId,
            $decision,
            $remarks !== '' ? $remarks : null,
            $currentStatus,
            $newStatus,
            $userId > 0 ? $userId : null,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Document verification failed: ' . $e->getMessage());

        return [
            'ok'      => false,
            'stale'   => false,
            'message' => 'The verification could not be saved because the database rejected the change. '
                . 'Nothing was recorded. Try again, and if it keeps failing report the reference number.',
        ];
    }

    logActivity(
        'document_verification',
        $documentName . ' for ' . ($document['reference_no'] ?? 'application')
            . ': ' . $decision . ' (' . $currentStatus . ' -> ' . $newStatus . ')',
        'enrollment',
        $userId > 0 ? $userId : null
    );

    return ['ok' => true, 'stale' => false, 'message' => ''];
}

/**
 * Store OCR output against a document. Advisory only: this never changes the
 * document's verification status.
 */
function enrDocSaveOcr(PDO $pdo, int $documentId, string $status, ?string $text): void
{
    $stmt = $pdo->prepare(
        'UPDATE enr_application_documents
            SET ocr_status = ?, ocr_text = ?, ocr_ran_at = NOW()
          WHERE id = ?'
    );
    $stmt->execute([$status, $text, $documentId]);
}

// ---------------------------------------------------------------------------
// Queue and dashboard
// ---------------------------------------------------------------------------

/**
 * Build the WHERE fragment for the document queue.
 *
 * @param array<string,mixed> $filters
 * @return array{sql:string, params:list<mixed>}
 */
function enrDocBuildFilters(array $filters): array
{
    // Documents only make sense for applications that are actually in play.
    // A rejected pre-registration has no document workflow.
    $sql = ' WHERE a.status IN (?, ?, ?, ?) ';
    $params = [
        ENR_STATUS_SUBMITTED,
        ENR_STATUS_FOR_REVIEW,
        ENR_STATUS_FOR_CORRECTION,
        ENR_STATUS_APPROVED,
    ];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= " AND (a.reference_no LIKE ?
                    OR a.email LIKE ?
                    OR CONCAT_WS(' ', a.first_name, a.middle_name, a.last_name) LIKE ?) ";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $programId = (int) ($filters['program_id'] ?? 0);
    if ($programId > 0) {
        $sql .= ' AND a.program_id = ? ';
        $params[] = $programId;
    }

    $applicantType = trim((string) ($filters['applicant_type'] ?? ''));
    if ($applicantType !== '') {
        $sql .= ' AND a.applicant_type = ? ';
        $params[] = $applicantType;
    }

    $from = trim((string) ($filters['date_from'] ?? ''));
    if ($from !== '') {
        $sql .= ' AND DATE(a.submitted_at) >= ? ';
        $params[] = $from;
    }

    $to = trim((string) ($filters['date_to'] ?? ''));
    if ($to !== '') {
        $sql .= ' AND DATE(a.submitted_at) <= ? ';
        $params[] = $to;
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Applications for the document queue, each with its upload counts.
 *
 * Document status filtering is applied in PHP rather than SQL, because
 * "Complete" depends on the requirement list for that applicant's type, which
 * is a per-row calculation. The SQL narrows first so the set stays small.
 *
 * @return list<array<string,mixed>>
 */
function enrDocFindApplications(PDO $pdo, array $filters = []): array
{
    $built = enrDocBuildFilters($filters);

    $sql = "SELECT a.id, a.reference_no, a.first_name, a.middle_name, a.last_name,
                   a.email, a.applicant_type, a.status AS application_status,
                   a.submitted_at, a.program_id,
                   p.code AS program_code
              FROM enr_applications a
         LEFT JOIN enr_programs p ON p.id = a.program_id"
         . $built['sql']
         . ' ORDER BY a.submitted_at DESC, a.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($built['params']);
    $rows = $stmt->fetchAll() ?: [];

    // Attach each application's document picture.
    foreach ($rows as $index => $row) {
        $summary = enrDocApplicationSummary($pdo, $row);
        $rows[$index]['checklist']    = $summary['checklist'];
        $rows[$index]['completeness'] = $summary['completeness'];
        $rows[$index]['doc_status']   = $summary['completeness']['status'];
    }

    // Apply the document-status filter after the rollup is known.
    $docStatus = trim((string) ($filters['doc_status'] ?? ''));
    if ($docStatus !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($docStatus): bool {
            if ($docStatus === ENR_DOC_NEEDS_REPLACEMENT) {
                return (int) $row['completeness']['needs_replacement'] > 0;
            }
            if ($docStatus === ENR_DOC_REJECTED) {
                return (int) $row['completeness']['rejected'] > 0;
            }
            return $row['doc_status'] === $docStatus;
        }));
    }

    return $rows;
}

/**
 * Counts for the portal dashboard cards and the queue tabs.
 *
 * @return array<string,int>
 */
function enrDocQueueCounts(PDO $pdo): array
{
    $rows = enrDocFindApplications($pdo);

    $counts = [
        'Total'                   => count($rows),
        ENR_DOCS_FOR_VERIFICATION => 0,
        ENR_DOCS_INCOMPLETE       => 0,
        ENR_DOCS_COMPLETE         => 0,
        ENR_DOC_NEEDS_REPLACEMENT => 0,
        ENR_DOC_REJECTED          => 0,
    ];

    foreach ($rows as $row) {
        $counts[$row['doc_status']] = ($counts[$row['doc_status']] ?? 0) + 1;

        if ((int) $row['completeness']['needs_replacement'] > 0) {
            $counts[ENR_DOC_NEEDS_REPLACEMENT]++;
        }
        if ((int) $row['completeness']['rejected'] > 0) {
            $counts[ENR_DOC_REJECTED]++;
        }
    }

    return $counts;
}

/**
 * Recently uploaded documents for the dashboard table.
 *
 * @return list<array<string,mixed>>
 */
function enrDocRecentUploads(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min(50, $limit));

    $stmt = $pdo->prepare(
        "SELECT d.id, d.document_name, d.status, d.uploaded_at, d.original_name,
                a.reference_no, a.first_name, a.middle_name, a.last_name,
                p.code AS program_code
           FROM enr_application_documents d
           JOIN enr_applications a ON a.id = d.application_id
      LEFT JOIN enr_programs p     ON p.id = a.program_id
          ORDER BY d.uploaded_at DESC, d.id DESC
          LIMIT $limit"
    );
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/**
 * The next document still awaiting verification for the same application,
 * powering the "Verify Next Document" action on the result screen.
 */
function enrDocNextForVerification(PDO $pdo, int $applicationId, int $afterDocumentId = 0): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id
           FROM enr_application_documents
          WHERE application_id = ?
            AND status = ?
            AND id <> ?
          ORDER BY id ASC
          LIMIT 1'
    );
    $stmt->execute([$applicationId, ENR_DOC_FOR_VERIFICATION, $afterDocumentId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}
