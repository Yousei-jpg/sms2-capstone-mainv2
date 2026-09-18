<?php
/**
 * SMS 2 - Serve an uploaded enrollment document.
 *
 * Uploaded files live under storage/uploads/, which carries an .htaccess deny,
 * so they are NOT reachable by URL. This endpoint is the only way to read one,
 * and it checks the session and the module permission before sending a byte.
 *
 * That matters: these are birth certificates, report cards, and certificates
 * of indigency. A guessable public URL for them would be a data breach.
 *
 * Usage:  api/document-file.php?id=12            inline preview
 *         api/document-file.php?id=12&dl=1       force download
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/uploads.php';
require_once dirname(__DIR__) . '/config/enrollment.php';
require_once dirname(__DIR__) . '/includes/docs-repository.php';

requireAuth();
requireModuleAccess('enrollment');

/** Send a plain-text failure without leaking whether the id exists. */
function docFileFail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$documentId = (int) ($_GET['id'] ?? 0);
if ($documentId <= 0) {
    docFileFail(400, 'No document was requested.');
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    docFileFail(503, 'The database is unavailable. Try again shortly.');
}

if (!enrDocSchemaInstalled($pdo)) {
    docFileFail(503, 'The document schema has not been installed yet.');
}

$document = enrDocFind($pdo, $documentId);
if ($document === null) {
    docFileFail(404, 'That document does not exist.');
}

$storedName = (string) $document['stored_name'];

// Defend against a stored_name that has been tampered with in the database:
// resolve the real path and confirm it is still inside the upload directory.
$baseDir = realpath(smsUploadRoot() . '/' . ENR_DOC_SUBDIR);
$path    = realpath(smsUploadRoot() . '/' . ENR_DOC_SUBDIR . '/' . basename($storedName));

if ($baseDir === false || $path === false || !str_starts_with($path, $baseDir)) {
    docFileFail(404, 'The stored file for this document is missing.');
}

if (!is_file($path) || !is_readable($path)) {
    docFileFail(
        404,
        'The file for "' . (string) $document['document_name'] . '" is recorded in the database '
        . 'but is no longer on disk. Ask the applicant to upload it again.'
    );
}

$mime = (string) ($document['mime_type'] ?? 'application/octet-stream');

// Only preview types we trust inline. Anything else downloads, so a browser is
// never asked to render an unexpected content type from an uploaded file.
$inlineSafe  = enrDocIsImage($mime) || enrDocIsPdf($mime);
$forceDownload = !empty($_GET['dl']) || !$inlineSafe;
$disposition = $forceDownload ? 'attachment' : 'inline';

$filename = (string) $document['original_name'];
$filename = preg_replace('/[^\w\.\- ]/u', '_', $filename) ?: 'document';

// Log the access. Who looked at an applicant's birth certificate, and when,
// is exactly the kind of thing an audit trail exists for.
logActivity(
    'document_view',
    'Viewed document "' . (string) $document['document_name'] . '" for '
        . (string) $document['reference_no'] . ' (document #' . $documentId . ')',
    'enrollment',
    (int) ($_SESSION['user_id'] ?? 0) ?: null
);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'self\'');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($path);
exit;
