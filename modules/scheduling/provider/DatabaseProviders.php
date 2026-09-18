<?php
declare(strict_types=1);

/**
 * SMS 2 - Class Scheduling Module
 * DATABASE-BACKED PROVIDER IMPLEMENTATIONS
 *
 * These classes fulfil the contracts in contracts.php by reading the shared
 * tables in sms2_db. They are the INTERIM implementations: they exist because
 * Curriculum, Registrar, Enrollment, Faculty, and Facilities have not yet
 * been delivered as modules.
 *
 * When a module goes live, create a new class implementing the same contract
 * (for example LiveCurriculumProvider calling that module's API) and register
 * it in bootstrap.php. Nothing else in the scheduling module changes.
 *
 * Nothing here writes. Every query is a SELECT.
 */

require_once __DIR__ . '/contracts.php';

/* ================================================================== */

class DatabaseCurriculumProvider implements CurriculumProviderContract
{
    private PDO $pdo;

    /** Friendly names for program codes until Curriculum supplies them. */
    private const PROGRAM_NAMES = [
        'BSIT' => 'Bachelor of Science in Information Technology',
        'BSCS' => 'Bachelor of Science in Computer Science',
        'BIT'  => 'Bachelor of Industrial Technology',
        'BSA'  => 'Bachelor of Science in Accountancy',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sourceModule(): string
    {
        return 'Curriculum & Subject Management';
    }

    public function sourceStatus(): string
    {
        return 'Read from shared subjects table (module pending)';
    }

    public function getPrograms(): array
    {
        /* Programs are derived from the subjects and sections actually in use,
           because no programs table exists yet. 'ALL' is a wildcard marker on
           general education subjects, not a real program. */
        $rows = $this->pdo->query(
            "SELECT DISTINCT program FROM subjects
             WHERE status = 'Active' AND program <> 'ALL'
             UNION
             SELECT DISTINCT program FROM sections
             WHERE status = 'Active'
             ORDER BY program ASC"
        )->fetchAll(PDO::FETCH_COLUMN);

        $programs = [];
        foreach ($rows as $code) {
            $programs[] = [
                'code' => $code,
                'name' => self::PROGRAM_NAMES[$code] ?? $code,
            ];
        }
        return $programs;
    }

    public function getYearLevels(): array
    {
        $rows = $this->pdo->query(
            "SELECT DISTINCT year_level FROM sections
             WHERE year_level IS NOT NULL
             UNION
             SELECT DISTINCT year_level FROM subjects
             WHERE year_level IS NOT NULL
             ORDER BY year_level ASC"
        )->fetchAll(PDO::FETCH_COLUMN);

        $levels = array_map('intval', $rows);
        return $levels ?: [1, 2, 3, 4];
    }

    public function getSubjects(): array
    {
        return $this->pdo->query(
            "SELECT id, code, name, units, subject_type, lecture_hours, lab_hours,
                    program, year_level, semester
             FROM subjects
             WHERE status = 'Active'
             ORDER BY code ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getEligibleSubjects(string $program, ?int $yearLevel, ?string $semester): array
    {
        /* NULL year level or semester on a subject means "applies to any",
           which is how General Education and PE subjects are modelled. */
        $stmt = $this->pdo->prepare(
            "SELECT id, code, name, units, subject_type, lecture_hours, lab_hours,
                    program, year_level, semester
             FROM subjects
             WHERE status = 'Active'
               AND (program = :program OR program = 'ALL')
               AND (year_level IS NULL OR :year_level IS NULL OR year_level = :year_level2)
               AND (semester   IS NULL OR :semester   IS NULL OR semester   = :semester2)
             ORDER BY code ASC"
        );
        $stmt->execute([
            'program'     => $program,
            'year_level'  => $yearLevel,
            'year_level2' => $yearLevel,
            'semester'    => $semester,
            'semester2'   => $semester,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMaxUnitsPerSection(): float
    {
        /* Institutional rule, configurable. Held here rather than hard-coded
           in a page so Curriculum can supply the real value later. */
        return 24.0;
    }
}

/* ================================================================== */

class DatabaseRegistrarProvider implements RegistrarProviderContract
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sourceModule(): string
    {
        return 'Registrar';
    }

    public function sourceStatus(): string
    {
        return 'Derived from existing section records (module pending)';
    }

    public function getAcademicYears(): array
    {
        $rows = $this->pdo->query(
            "SELECT DISTINCT academic_year FROM sections
             WHERE academic_year IS NOT NULL AND academic_year <> ''
             ORDER BY academic_year DESC"
        )->fetchAll(PDO::FETCH_COLUMN);

        $years = array_values(array_filter($rows));

        /* Always offer the next academic year so a new term can be prepared
           before any section exists for it. */
        if (!empty($years)) {
            $latest = $years[0];
            if (preg_match('/^(\d{4})-(\d{4})$/', $latest, $m)) {
                $next = ((int)$m[1] + 1) . '-' . ((int)$m[2] + 1);
                if (!in_array($next, $years, true)) {
                    array_unshift($years, $next);
                }
            }
        } else {
            $y = (int)date('Y');
            $years = [$y . '-' . ($y + 1)];
        }

        return $years;
    }

    public function getSemesters(): array
    {
        return ['1st Semester', '2nd Semester', 'Summer'];
    }

    public function getCurrentTerm(): array
    {
        /* The term with the most active sections is treated as the term in
           effect until Registrar publishes an official academic calendar. */
        $row = $this->pdo->query(
            "SELECT academic_year, semester, COUNT(*) AS section_count
             FROM sections
             WHERE status = 'Active'
             GROUP BY academic_year, semester
             ORDER BY section_count DESC, academic_year DESC
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'academic_year' => $row['academic_year'],
                'semester'      => $row['semester'],
            ];
        }

        $y = (int)date('Y');
        return [
            'academic_year' => $y . '-' . ($y + 1),
            'semester'      => '1st Semester',
        ];
    }
}

/* ================================================================== */

class DatabaseEnrollmentProvider implements EnrollmentProviderContract
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sourceModule(): string
    {
        return 'Enrollment Management';
    }

    public function sourceStatus(): string
    {
        return 'Read from shared sections table (module pending)';
    }

    public function getSections(?string $academicYear = null, ?string $semester = null): array
    {
        $sql = "SELECT id, code, name, program, year_level, semester, academic_year,
                       max_students, current_students, advisor_name, status,
                       created_at, updated_at
                FROM sections
                WHERE status = 'Active'";
        $params = [];

        if ($academicYear !== null && $academicYear !== '') {
            $sql .= " AND academic_year = :academic_year";
            $params['academic_year'] = $academicYear;
        }
        if ($semester !== null && $semester !== '') {
            $sql .= " AND semester = :semester";
            $params['semester'] = $semester;
        }

        $sql .= " ORDER BY code ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudentCount(int $sectionId): int
    {
        /* Interim source. When Enrollment is delivered this becomes a count of
           enrolled registrations rather than a stored figure on the section. */
        $stmt = $this->pdo->prepare(
            "SELECT current_students FROM sections WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $sectionId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int)$value;
    }

    public function getStudentCounts(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, current_students FROM sections"
        )->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['id']] = (int)$row['current_students'];
        }
        return $counts;
    }
}

/* ================================================================== */

class DatabaseFacultyProvider implements FacultyProviderContract
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sourceModule(): string
    {
        return 'Faculty Management';
    }

    public function sourceStatus(): string
    {
        return 'Read from shared teachers table (module pending)';
    }

    public function getTeachers(): array
    {
        return $this->pdo->query(
            "SELECT id, employee_no, full_name, department, email, max_load_units, status
             FROM teachers
             WHERE status = 'Active'
             ORDER BY full_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTeacherById(int $teacherId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, employee_no, full_name, department, email, max_load_units, status
             FROM teachers WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getMaxLoadUnits(int $teacherId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT max_load_units FROM teachers WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $teacherId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0.0 : (float)$value;
    }
}

/* ================================================================== */

class DatabaseFacilitiesProvider implements FacilitiesProviderContract
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sourceModule(): string
    {
        return 'Facilities / Property Management';
    }

    public function sourceStatus(): string
    {
        return 'Read from shared rooms table (module pending)';
    }

    public function getRooms(): array
    {
        return $this->pdo->query(
            "SELECT id, room_code, building, room_type, capacity, status
             FROM rooms
             WHERE status = 'Available'
             ORDER BY room_code ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBuildings(): array
    {
        return $this->pdo->query(
            "SELECT DISTINCT building FROM rooms
             WHERE building IS NOT NULL AND building <> ''
             ORDER BY building ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getRoomTypes(): array
    {
        return $this->pdo->query(
            "SELECT DISTINCT room_type FROM rooms ORDER BY room_type ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
}
