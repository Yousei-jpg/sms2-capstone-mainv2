<?php
declare(strict_types=1);

/**
 * SMS 2 - Class Scheduling Module
 * PROVIDER CONTRACTS
 *
 * The Class Scheduling System does not own institutional academic data.
 * It consumes that data from other modules. These interfaces declare
 * exactly what Scheduling needs and nothing more.
 *
 * Every method is READ-ONLY by design. Scheduling never writes through a
 * provider. This enforces the data ownership rule: a module that owns a
 * record is the only module that may change it.
 *
 * Each contract is currently fulfilled by a database-backed implementation
 * that reads the shared tables (see DatabaseProviders.php). When the owning
 * module is delivered, only that one implementation is replaced. No page,
 * API, or query in the scheduling module has to change.
 *
 *      Scheduling  ->  Contract (this file)  ->  Implementation  ->  Data
 *                      stays the same           swapped later
 */

/**
 * Common to every provider. Lets the interface describe its own origin so
 * the user interface can show where each value came from.
 */
interface SchedulingDataProvider
{
    /** Name of the module that owns this data, e.g. 'Curriculum & Subject Management'. */
    public function sourceModule(): string;

    /** How the data is currently obtained, for display and for documentation. */
    public function sourceStatus(): string;
}

/* ------------------------------------------------------------------ */

/**
 * Owned by: Curriculum & Subject Management
 * Provides: programs, subjects, curriculum eligibility rules
 */
interface CurriculumProviderContract extends SchedulingDataProvider
{
    /** @return array<int, array{code:string, name:string}> */
    public function getPrograms(): array;

    /** @return array<int, int> Year levels offered, e.g. [1,2,3,4] */
    public function getYearLevels(): array;

    /** @return array<int, array> Every active subject. */
    public function getSubjects(): array;

    /**
     * Subjects a section may legitimately take.
     * A NULL year level or semester on a subject means "any", so those
     * subjects are always eligible.
     */
    public function getEligibleSubjects(string $program, ?int $yearLevel, ?string $semester): array;

    /** Maximum units a section may be assigned in one term. */
    public function getMaxUnitsPerSection(): float;
}

/* ------------------------------------------------------------------ */

/**
 * Owned by: Registrar
 * Provides: official academic periods
 */
interface RegistrarProviderContract extends SchedulingDataProvider
{
    /** @return array<int, string> e.g. ['2026-2027', '2027-2028'] */
    public function getAcademicYears(): array;

    /** @return array<int, string> e.g. ['1st Semester', '2nd Semester', 'Summer'] */
    public function getSemesters(): array;

    /** @return array{academic_year:string, semester:string} The period now in effect. */
    public function getCurrentTerm(): array;
}

/* ------------------------------------------------------------------ */

/**
 * Owned by: Enrollment Management
 * Provides: sections and their enrolled population
 */
interface EnrollmentProviderContract extends SchedulingDataProvider
{
    /** @return array<int, array> Active sections for the given term (all terms when null). */
    public function getSections(?string $academicYear = null, ?string $semester = null): array;

    /** Number of students currently enrolled in a section. */
    public function getStudentCount(int $sectionId): int;

    /** @return array<int, int> sectionId => studentCount, for list screens. */
    public function getStudentCounts(): array;
}

/* ------------------------------------------------------------------ */

/**
 * Owned by: Faculty Management
 * Provides: teaching staff and their teaching capacity
 */
interface FacultyProviderContract extends SchedulingDataProvider
{
    /** @return array<int, array> Active teachers. */
    public function getTeachers(): array;

    public function getTeacherById(int $teacherId): ?array;

    /** Maximum teaching load in units for a teacher. */
    public function getMaxLoadUnits(int $teacherId): float;
}

/* ------------------------------------------------------------------ */

/**
 * Owned by: Facilities / Property Management
 * Provides: rooms available for class assignment
 */
interface FacilitiesProviderContract extends SchedulingDataProvider
{
    /** @return array<int, array> Rooms with status 'Available'. */
    public function getRooms(): array;

    /** @return array<int, string> Distinct building names. */
    public function getBuildings(): array;

    /** @return array<int, string> Distinct room types. */
    public function getRoomTypes(): array;
}
