<?php
/**
 * SMS 2 - Install the Document Upload Portal schema.
 *
 * Applies modules/enrollment/database/document_upload_db.sql to sms2_db,
 * creates the storage directory the portal uploads into, and reports what it
 * found. Safe to run more than once.
 *
 * CLI:  C:\xampp\php\php.exe modules/enrollment/database/install_document_upload.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/uploads.php';
require_once dirname(__DIR__) . '/config/enrollment.php';
require_once dirname(__DIR__) . '/includes/docs-workflow.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Run from CLI only:\n"
        . "  C:\\xampp\\php\\php.exe modules/enrollment/database/install_document_upload.php\n";
    exit(1);
}

function docInstallOut(string $message = ''): void
{
    echo $message . PHP_EOL;
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    docInstallOut('FAILED: ' . $e->getMessage());
    exit(1);
}

// The document tables reference enr_applications, so Pre-Registration's schema
// has to exist first. Say so plainly rather than letting MySQL throw an
// unexplained foreign key error.
// information_schema rather than SHOW TABLES LIKE ?: MySQL rejects a bound
// parameter inside a SHOW statement when prepares are not emulated.
$check = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?'
);
$check->execute(['enr_applications']);
if ((int) $check->fetchColumn() === 0) {
    docInstallOut('FAILED: enr_applications does not exist.');
    docInstallOut('Run this first:');
    docInstallOut('  C:\\xampp\\php\\php.exe modules/enrollment/database/install_enrollment.php');
    exit(1);
}

$sqlFile = __DIR__ . '/document_upload_db.sql';
if (!is_file($sqlFile)) {
    docInstallOut('FAILED: document_upload_db.sql is missing from ' . __DIR__);
    exit(1);
}

docInstallOut('Applying Document Upload Portal schema to ' . DB_NAME . ' ...');
docInstallOut();

try {
    $pdo->exec((string) file_get_contents($sqlFile));
} catch (Throwable $e) {
    docInstallOut('FAILED while applying the migration: ' . $e->getMessage());
    docInstallOut('No partial state should remain; every statement is idempotent.');
    exit(1);
}

foreach (['enr_document_requirements', 'enr_application_documents', 'enr_document_verifications'] as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    docInstallOut((int) $stmt->fetchColumn() > 0
        ? '  OK    table  ' . $table
        : '  MISS  table  ' . $table);
}

// Create the upload directory with its deny rule, so the first real upload is
// not the moment this is discovered to be missing.
smsUploadEnsureDirs();
$docDir = smsUploadRoot() . '/' . ENR_DOC_SUBDIR;
if (!is_dir($docDir)) {
    @mkdir($docDir, 0750, true);
}
docInstallOut(is_dir($docDir)
    ? '  OK    storage ' . ENR_DOC_SUBDIR . '/ ready'
    : '  MISS  storage could not create ' . $docDir);

docInstallOut();
docInstallOut('Requirements per applicant type:');
$rows = $pdo->query(
    'SELECT applicant_type, COUNT(*) AS total, SUM(is_required) AS required
       FROM enr_document_requirements
      WHERE is_active = 1
      GROUP BY applicant_type
      ORDER BY applicant_type'
)->fetchAll();

foreach ($rows as $row) {
    docInstallOut(sprintf(
        '  %-16s %d documents (%d required)',
        (string) $row['applicant_type'],
        (int) $row['total'],
        (int) $row['required']
    ));
}

$docCount = (int) $pdo->query('SELECT COUNT(*) FROM enr_application_documents')->fetchColumn();

docInstallOut();
docInstallOut('Uploaded documents on file: ' . $docCount);

if ($docCount === 0) {
    docInstallOut();
    docInstallOut('No documents have been uploaded yet, so the verification queue will be empty.');
    docInstallOut('To attach sample documents to the sample applicants, run:');
    docInstallOut('  C:\\xampp\\php\\php.exe modules/enrollment/database/seed_sample_documents.php');
}

docInstallOut();
docInstallOut('OCR is disabled by default. The portal works fully without it.');
