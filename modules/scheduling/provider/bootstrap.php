<?php
declare(strict_types=1);

/**
 * SMS 2 - Class Scheduling Module
 * PROVIDER BOOTSTRAP
 *
 * Single place where each contract is bound to an implementation.
 * This is the only file that changes when a module goes live.
 *
 * Usage from any scheduling page or API:
 *
 *     require_once ROOT_PATH . '/modules/scheduling/provider/bootstrap.php';
 *     $curriculum = scheduling_provider('curriculum');
 *     $programs   = $curriculum->getPrograms();
 */

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/DatabaseProviders.php';

/**
 * Returns the implementation bound to a contract.
 *
 * To switch Curriculum to a live module later, change one line below:
 *     'curriculum' => new LiveCurriculumProvider($apiClient),
 *
 * @param string $key curriculum | registrar | enrollment | faculty | facilities
 */
function scheduling_provider(string $key)
{
    static $registry = null;

    if ($registry === null) {
        $pdo = getDatabaseConnection();

        $registry = [
            /* ---- BINDINGS ------------------------------------------------
               Left side  = the contract the scheduling module depends on.
               Right side = the implementation currently fulfilling it.
               Replace the right side only.                                */
            'curriculum' => new DatabaseCurriculumProvider($pdo),
            'registrar'  => new DatabaseRegistrarProvider($pdo),
            'enrollment' => new DatabaseEnrollmentProvider($pdo),
            'faculty'    => new DatabaseFacultyProvider($pdo),
            'facilities' => new DatabaseFacilitiesProvider($pdo),
        ];
    }

    $key = strtolower(trim($key));
    if (!isset($registry[$key])) {
        throw new InvalidArgumentException("Unknown scheduling provider: {$key}");
    }

    return $registry[$key];
}
