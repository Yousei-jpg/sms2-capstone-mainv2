<?php
declare(strict_types=1);

/**
 * A revision represents the official section information and its saved subject
 * selection. No scheduling status is written into the source section record.
 */
function sectionAssignmentRevision(array $section, array $subjectCodes): string
{
    $official = [];
    foreach (['id', 'code', 'name', 'program', 'year_level', 'semester',
        'academic_year', 'max_students', 'current_students', 'status', 'updated_at'] as $key) {
        $official[$key] = (string)($section[$key] ?? '');
    }
    $subjectCodes = array_map('strval', $subjectCodes);
    sort($subjectCodes, SORT_STRING);
    return hash('sha256', json_encode([$official, $subjectCodes], JSON_THROW_ON_ERROR));
}

function sectionAssignmentStatus(array $subjectCodes, bool $hasSchedules): string
{
    return $hasSchedules ? 'In Progress' : ($subjectCodes ? 'Ready for Scheduling' : 'For Scheduling');
}
