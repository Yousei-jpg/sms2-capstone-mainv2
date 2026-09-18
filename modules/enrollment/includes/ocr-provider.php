<?php
/**
 * SMS 2 - OCR provider for enrollment documents
 * Module: Enrollment  |  Subsystem: Registration -> Document Upload Portal
 *
 * ============================================================================
 * OCR IS ADVISORY. It never sets, changes, or suggests a verification status.
 *
 * It reads text off a scan and tells the Registrar what it found. The decision
 * stays with the person. This is deliberate: OCR misreads bad photocopies all
 * the time, and auto-rejecting a genuine birth certificate because a scan was
 * dark would turn a software limitation into a student's problem.
 * ============================================================================
 *
 * Ships DISABLED. The portal is fully functional without it. Enable it only
 * once a Google Cloud Vision API key is configured.
 *
 * The API key is read from system_settings and stored ENCRYPTED through the
 * same crypto helper that already protects smtp_password. It is never written
 * into a source file.
 */

declare(strict_types=1);

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/crypto.php';

/** Is OCR switched on and actually usable? */
function enrOcrEnabled(): bool
{
    if (smsSetting('enr_ocr_enabled', '0') !== '1') {
        return false;
    }
    return enrOcrApiKey() !== '';
}

/**
 * The Vision API key, decrypted. Empty string when not configured.
 */
function enrOcrApiKey(): string
{
    // enr_ocr_api_key is registered in smsEncryptedSettingKeys(), so smsSetting()
    // decrypts it on read and encrypts any legacy plaintext value in place. The
    // key is never stored or committed in a source file.
    return trim(smsSetting('enr_ocr_api_key', ''));
}

/**
 * Why OCR is unavailable, phrased for the Registrar rather than for a log.
 */
function enrOcrUnavailableReason(): string
{
    if (smsSetting('enr_ocr_enabled', '0') !== '1') {
        return 'Text extraction is switched off. An administrator can enable it in System Settings '
            . 'once a Google Cloud Vision API key has been added.';
    }
    if (enrOcrApiKey() === '') {
        return 'Text extraction is switched on but no Google Cloud Vision API key has been saved, '
            . 'so it cannot run.';
    }
    return '';
}

/**
 * Run OCR over a stored document.
 *
 * @return array{ok:bool, status:string, text:string, message:string}
 *         status is one of: Success | Failed | Disabled
 */
function enrOcrExtractText(string $absolutePath, string $mimeType): array
{
    if (!enrOcrEnabled()) {
        return [
            'ok'      => false,
            'status'  => 'Disabled',
            'text'    => '',
            'message' => enrOcrUnavailableReason(),
        ];
    }

    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        return [
            'ok'      => false,
            'status'  => 'Failed',
            'text'    => '',
            'message' => 'The stored file could not be read from disk, so there was nothing to scan.',
        ];
    }

    // Vision accepts images directly. PDFs go through a different endpoint that
    // requires Cloud Storage, which this project does not use, so PDFs are
    // reported honestly as unsupported rather than silently skipped.
    if (!enrDocIsImage($mimeType)) {
        return [
            'ok'      => false,
            'status'  => 'Failed',
            'text'    => '',
            'message' => 'Text extraction currently supports JPG and PNG only. '
                . 'This document is a PDF, so it must be read manually.',
        ];
    }

    $contents = file_get_contents($absolutePath);
    if ($contents === false) {
        return [
            'ok'      => false,
            'status'  => 'Failed',
            'text'    => '',
            'message' => 'The file could not be loaded for scanning.',
        ];
    }

    $payload = json_encode([
        'requests' => [[
            'image'    => ['content' => base64_encode($contents)],
            'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
        ]],
    ]);

    $endpoint = 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode(enrOcrApiKey());

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok'      => false,
            'status'  => 'Failed',
            'text'    => '',
            'message' => 'Could not reach the Vision API: ' . $curlErr
                . '. Check the server\'s internet connection.',
        ];
    }

    $decoded = json_decode((string) $response, true);

    if ($httpCode !== 200 || !is_array($decoded)) {
        $apiMessage = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        return [
            'ok'      => false,
            'status'  => 'Failed',
            'text'    => '',
            'message' => 'The Vision API rejected the request: ' . $apiMessage,
        ];
    }

    $text = (string) ($decoded['responses'][0]['fullTextAnnotation']['text'] ?? '');

    if ($text === '') {
        return [
            'ok'      => true,
            'status'  => 'Success',
            'text'    => '',
            'message' => 'The scan completed but no readable text was found. '
                . 'That often means the image is too dark, too small, or out of focus.',
        ];
    }

    return [
        'ok'      => true,
        'status'  => 'Success',
        'text'    => $text,
        'message' => 'Text extracted successfully.',
    ];
}

/**
 * Does the applicant's name appear in the extracted text?
 *
 * This is the single most useful hint OCR can give a Registrar: it catches the
 * common case of someone uploading a sibling's or parent's document.
 *
 * A miss means nothing on its own. Married names, middle initials, and poor
 * scans all cause false negatives, which is exactly why this returns a hint
 * and not a verdict.
 *
 * @return array{checked:bool, matched:bool, matched_parts:list<string>, note:string}
 */
function enrOcrNameHint(string $ocrText, string $firstName, string $lastName): array
{
    if (trim($ocrText) === '') {
        return [
            'checked'       => false,
            'matched'       => false,
            'matched_parts' => [],
            'note'          => 'No text was extracted, so the name could not be checked.',
        ];
    }

    $haystack = mb_strtolower($ocrText);
    $parts    = [];

    foreach ([$firstName, $lastName] as $part) {
        $part = trim($part);
        if ($part === '' || mb_strlen($part) < 3) {
            continue;
        }
        if (str_contains($haystack, mb_strtolower($part))) {
            $parts[] = $part;
        }
    }

    if ($parts === []) {
        return [
            'checked'       => true,
            'matched'       => false,
            'matched_parts' => [],
            'note'          => 'The applicant\'s name was not found in the extracted text. '
                . 'This is a hint, not a problem: married names, initials, and poor scans '
                . 'often cause this. Read the document before deciding.',
        ];
    }

    return [
        'checked'       => true,
        'matched'       => true,
        'matched_parts' => $parts,
        'note'          => 'Found in the document text: ' . implode(', ', $parts) . '.',
    ];
}
