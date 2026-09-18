<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/authentication.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('isAuthenticated') && !isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

if (function_exists('userCanAccessModule') && !userCanAccessModule('scheduling')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to access Class Scheduling.']);
    exit;
}
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST to publish schedules.']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON request body.']);
        exit;
    }

    $sectionCode = trim((string)($payload['section_code'] ?? ''));
    if ($sectionCode === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'section_code is required.']);
        exit;
    }

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    $sectionStmt = $pdo->prepare(
        "SELECT id FROM sections WHERE code = :code AND status = 'Active' LIMIT 1"
    );
    $sectionStmt->execute(['code' => $sectionCode]);
    $section = $sectionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$section) {
        throw new RuntimeException('Section not found or inactive.');
    }

    $sectionId = (int)$section['id'];

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM schedule_entries
         WHERE section_id = :section_id
           AND status IN ('Draft', 'Validated')
           AND teacher_id IS NOT NULL
           AND room_id IS NOT NULL
           AND day_of_week IS NOT NULL
           AND start_time IS NOT NULL
           AND end_time IS NOT NULL"
    );
    $countStmt->execute(['section_id' => $sectionId]);
    $publishableCount = (int)$countStmt->fetchColumn();

    if ($publishableCount === 0) {
        throw new RuntimeException('No completed draft schedule entries are available to publish.');
    }

    // Do not publish incomplete entries.
    $incompleteStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM schedule_entries
         WHERE section_id = :section_id
           AND status IN ('Draft', 'Validated')
           AND (teacher_id IS NULL OR room_id IS NULL OR day_of_week IS NULL OR start_time IS NULL OR end_time IS NULL)"
    );
    $incompleteStmt->execute(['section_id' => $sectionId]);
    $incompleteCount = (int)$incompleteStmt->fetchColumn();

    if ($incompleteCount > 0) {
        throw new RuntimeException('Incomplete schedule entries exist. Complete all schedule assignments before publishing.');
    }

    $updateStmt = $pdo->prepare(
        "UPDATE schedule_entries
            SET status = 'Published',
                remarks = 'Published from Teacher Schedule Mapping.'
          WHERE section_id = :section_id
            AND status IN ('Draft', 'Validated')"
    );
    $updateStmt->execute(['section_id' => $sectionId]);
    $publishedCount = $updateStmt->rowCount();

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'section_code' => $sectionCode,
        'published_count' => $publishedCount,
        'message' => 'Schedule published successfully.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Publish schedule failed.',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
