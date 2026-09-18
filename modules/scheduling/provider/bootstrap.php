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

/**
 * Describes every external dependency of the scheduling module.
 * Used by the Data Sources panel so the origin of each field is visible
 * on screen rather than only in documentation.
 *
 * @return array<int, array{key:string, module:string, status:string, provides:string}>
 */
function scheduling_provider_manifest(): array
{
    $map = [
        'curriculum' => 'Programs, subjects, units, curriculum eligibility rules',
        'registrar'  => 'Academic years, semesters, current academic term',
        'enrollment' => 'Sections, enrolled student counts',
        'faculty'    => 'Teachers, departments, maximum teaching load',
        'facilities' => 'Rooms, buildings, room types, seating capacity',
    ];

    $manifest = [];
    foreach ($map as $key => $provides) {
        $provider = scheduling_provider($key);
        $manifest[] = [
            'key'      => $key,
            'module'   => $provider->sourceModule(),
            'status'   => $provider->sourceStatus(),
            'provides' => $provides,
        ];
    }
    return $manifest;
}

/**
 * Data the Class Scheduling System owns outright. Listed alongside the
 * manifest so the ownership boundary is stated in one place.
 *
 * @return array<int, string>
 */
function scheduling_owned_data(): array
{
    return [
        'Section-subject assignments (section_subjects)',
        'Class schedules (schedule_entries)',
        'Time blocks (time_blocks)',
        'Teacher availability declarations (teacher_availability)',
        'Examination schedules (exam_schedules)',
        'Substitute assignments (substitute_assignments)',
        'Special classes (special_classes)',
        'Conflict validation results (conflict_results)',
    ];
}
