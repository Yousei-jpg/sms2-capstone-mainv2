<?php
/**
 * SMS 2 - Enrollment Module Schema Installer
 *
 * Applies modules/enrollment/database/enrollment_db.sql to the main SMS 2
 * database. Idempotent: safe to run repeatedly, existing data is untouched.
 *
 * Does not drop anything and does not run outside the `enr_` namespace, apart
 * from two INSERT IGNORE rows in system_settings.
 *
 * CLI:  C:\xampp\php\php.exe modules/enrollment/database/install_enrollment.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Run from CLI only:\n"
        . "  C:\\xampp\\php\\php.exe modules/enrollment/database/install_enrollment.php\n";
    exit(1);
}

function enrOut(string $message): void
{
    echo $message . PHP_EOL;
}

$sqlFile = __DIR__ . '/enrollment_db.sql';
if (!is_readable($sqlFile)) {
    enrOut('FAILED: cannot read ' . $sqlFile);
    exit(1);
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    enrOut('FAILED: ' . $e->getMessage());
    exit(1);
}

// The Enrollment schema references users.id, so the base install must exist.
// information_schema rather than SHOW TABLES LIKE ?: MySQL does not accept a
// bound parameter inside a SHOW statement.
$usersCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?'
);
$usersCheck->execute(['users']);
if ((int) $usersCheck->fetchColumn() === 0) {
    enrOut('FAILED: the `users` table does not exist in ' . DB_NAME . '.');
    enrOut('Run database/install.php or database/migrate.php first.');
    exit(1);
}

enrOut('Applying Enrollment schema to ' . DB_NAME . ' ...');
enrOut('');

/**
 * Split the migration on statement boundaries, ignoring semicolons that sit
 * inside comments or quoted strings.
 *
 * @return list<string>
 */
function enrSplitStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inLineComment = false;
    $quote = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($quote === '' && $char === '-' && $next === '-') {
            $inLineComment = true;
            continue;
        }

        if ($quote !== '') {
            $buffer .= $char;
            if ($char === '\\') {
                if ($next !== '') {
                    $buffer .= $next;
                    $i++;
                }
                continue;
            }
            if ($char === $quote) {
                $quote = '';
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

$statements = enrSplitStatements((string) file_get_contents($sqlFile));
if (!$statements) {
    enrOut('FAILED: no statements found in enrollment_db.sql.');
    exit(1);
}

$applied = 0;
$failed = 0;

foreach ($statements as $statement) {
    // Short label for the log line.
    $label = 'statement';
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`/i', $statement, $m)) {
        $label = 'table  ' . $m[1];
    } elseif (preg_match('/INSERT IGNORE INTO\s+`([^`]+)`/i', $statement, $m)) {
        $label = 'seed   ' . $m[1];
    }

    try {
        $affected = $pdo->exec($statement);
        $applied++;
        $note = ($affected !== false && $affected > 0)
            ? ' (' . $affected . ' row' . ($affected === 1 ? '' : 's') . ' added)'
            : ' (already present)';
        enrOut('  OK    ' . $label . $note);
    } catch (Throwable $e) {
        $failed++;
        enrOut('  ERROR ' . $label . ': ' . $e->getMessage());
    }
}

enrOut('');

if ($failed > 0) {
    enrOut('Finished with ' . $failed . ' error(s). ' . $applied . ' statement(s) applied.');
    exit(1);
}

// Report the resulting state so it can be verified without phpMyAdmin.
$tables = ['enr_academic_periods', 'enr_programs', 'enr_applications', 'enr_application_reviews'];
enrOut('Enrollment schema installed. Current row counts:');
foreach ($tables as $table) {
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        enrOut('  ' . str_pad($table, 26) . $count);
    } catch (Throwable $e) {
        enrOut('  ' . str_pad($table, 26) . 'unreadable: ' . $e->getMessage());
    }
}

enrOut('');
enrOut('The application queue is empty. To load sample applicants for testing:');
enrOut('  C:\\xampp\\php\\php.exe modules/enrollment/database/seed_sample_applications.php');
