<?php
/**
 * SMS 2 - Grant the `registrar` role access to the Enrollment module.
 *
 * The seeded role_permissions table assigns `enrollment` to `admission` and
 * `sms_admin` only, so a user whose role_key is `registrar` is redirected away
 * by requireModuleAccess() before any Enrollment page renders. The Enrollment
 * module's primary operational user is Registrar Staff, so the grant is added.
 *
 * database/sms2_db.sql now seeds this row for fresh installs. This script exists
 * for databases that were already created from the old dump, where re-running
 * the dump is skipped (and would destroy data).
 *
 * Safe to run more than once. It inserts nothing if the grant already exists,
 * and it does not touch the existing `admission` grant.
 *
 * CLI:  C:\xampp\php\php.exe database/grant_registrar_enrollment.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Run from CLI only:\n  C:\\xampp\\php\\php.exe database/grant_registrar_enrollment.php\n";
    exit(1);
}

const TARGET_ROLE   = 'registrar';
const TARGET_MODULE = 'enrollment';

function out(string $message): void
{
    echo $message . PHP_EOL;
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    out('FAILED: ' . $e->getMessage());
    exit(1);
}

// Guard: the tables this script depends on must already exist.
foreach (['roles', 'role_permissions'] as $requiredTable) {
    // information_schema is used instead of SHOW TABLES LIKE ? because MySQL
    // does not accept a bound parameter in a SHOW statement.
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $check->execute([$requiredTable]);
    if ((int) $check->fetchColumn() === 0) {
        out('FAILED: table `' . $requiredTable . '` not found in ' . DB_NAME . '.');
        out('Run database/install.php (fresh install) or database/migrate.php first.');
        exit(1);
    }
}

// Guard: do not create a grant for a role that does not exist.
$roleStmt = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE role_key = ?');
$roleStmt->execute([TARGET_ROLE]);
if ((int) $roleStmt->fetchColumn() === 0) {
    out('FAILED: role `' . TARGET_ROLE . '` does not exist in the roles table.');
    exit(1);
}

$pdo->beginTransaction();

try {
    $existing = $pdo->prepare(
        'SELECT id, granted FROM role_permissions WHERE role_key = ? AND module_key = ? LIMIT 1'
    );
    $existing->execute([TARGET_ROLE, TARGET_MODULE]);
    $row = $existing->fetch();

    if ($row === false) {
        $insert = $pdo->prepare(
            'INSERT INTO role_permissions (role_key, module_key, granted) VALUES (?, ?, 1)'
        );
        $insert->execute([TARGET_ROLE, TARGET_MODULE]);
        $result = 'GRANTED: `' . TARGET_ROLE . '` can now open the Enrollment module.';
    } elseif ((int) $row['granted'] === 1) {
        $result = 'NO CHANGE: `' . TARGET_ROLE . '` already has Enrollment access.';
    } else {
        $update = $pdo->prepare('UPDATE role_permissions SET granted = 1 WHERE id = ?');
        $update->execute([(int) $row['id']]);
        $result = 'RE-ENABLED: the existing `' . TARGET_ROLE . '` Enrollment grant was set to 0 and is now 1.';
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    out('FAILED: ' . $e->getMessage());
    exit(1);
}

out($result);

// Report the resulting grants so the change is verifiable without opening phpMyAdmin.
$verify = $pdo->prepare(
    'SELECT role_key, granted FROM role_permissions WHERE module_key = ? ORDER BY role_key'
);
$verify->execute([TARGET_MODULE]);

out('');
out('Roles granted the Enrollment module:');
foreach ($verify->fetchAll() as $grant) {
    out('  - ' . $grant['role_key'] . ' (granted=' . (int) $grant['granted'] . ')');
}
out('');
out('Note: registrar users will now land on Enrollment instead of Registrar,');
out('because smsPrimaryModuleForRole() ranks `enrollment` above `registrar`.');
