<?php
/**
 * SMS 2 - Enrollment Provider Contracts
 *
 * Enrollment needs reference data that other modules will eventually own.
 * Those modules (Curriculum, Registrar SIS, the academic-period source) are
 * currently empty page stubs holding no data.
 *
 * Rather than scatter direct queries through the Enrollment screens, every
 * outside-owned lookup goes through one of these read-only providers. When the
 * owning module is built, only the implementation below changes; no screen,
 * query, or template is touched.
 *
 * Read-only by design: Enrollment must never write to another module's data.
 */

declare(strict_types=1);

require_once __DIR__ . '/../docs-repository.php';

require_once dirname(__DIR__, 2) . '/config/enrollment.php';

/**
 * Programs offered by the institution.
 *
 * OWNER (eventually): Curriculum & Subject Management.
 * OWNER (today): enr_programs, seeded by enrollment_db.sql and flagged with
 * source = 'enrollment-stub'.
 */
interface ProgramProvider
{
    /** @return list<array{id:int, code:string, name:string, level:string}> */
    public function activePrograms(): array;

    /** @return array{id:int, code:string, name:string, level:string}|null */
    public function findProgram(int $programId): ?array;

    /** True when the program can currently accept applications. */
    public function isOffered(int $programId): bool;
}

/**
 * Academic year and semester.
 *
 * OWNER (eventually): a shared academic-period source. None exists in SMS 2.
 * OWNER (today): enr_academic_periods.
 */
interface AcademicPeriodProvider
{
    /** @return list<array{id:int, school_year:string, semester:string, is_active:int}> */
    public function allPeriods(): array;

    /** @return array{id:int, school_year:string, semester:string, is_active:int}|null */
    public function activePeriod(): ?array;

    /** @return array{id:int, school_year:string, semester:string, is_active:int}|null */
    public function findPeriod(int $periodId): ?array;
}

/**
 * Document verification status for an application.
 *
 * OWNER (eventually): Document Upload Portal, the next subsystem to be built.
 * OWNER (today): nobody. The stub below reports "not implemented" honestly
 * rather than returning a fabricated verified/missing list, so the validation
 * checklist can raise a truthful WARNING instead of a false PASS.
 */
interface DocumentStatusProvider
{
    /** Is the Document Upload Portal available to answer document questions? */
    public function isAvailable(): bool;

    /** @return list<array{name:string, upload_status:string, verification_status:string}> */
    public function documentsForApplication(int $applicationId): array;

    /** All required documents submitted? Null when unknown. */
    public function allRequiredSubmitted(int $applicationId): ?bool;

    /** All submitted documents verified? Null when unknown. */
    public function allVerified(int $applicationId): ?bool;
}

// ---------------------------------------------------------------------------
// Implementations
// ---------------------------------------------------------------------------

final class EnrDbProgramProvider implements ProgramProvider
{
    public function __construct(private PDO $pdo)
    {
    }

    public function activePrograms(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, code, name, level FROM enr_programs
             WHERE is_active = 1 ORDER BY code'
        );
        return $stmt->fetchAll() ?: [];
    }

    public function findProgram(int $programId): ?array
    {
        if ($programId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, level FROM enr_programs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$programId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function isOffered(int $programId): bool
    {
        if ($programId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT is_active FROM enr_programs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$programId]);
        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    }
}

final class EnrDbAcademicPeriodProvider implements AcademicPeriodProvider
{
    public function __construct(private PDO $pdo)
    {
    }

    public function allPeriods(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, school_year, semester, is_active FROM enr_academic_periods
             ORDER BY school_year DESC, semester'
        );
        return $stmt->fetchAll() ?: [];
    }

    public function activePeriod(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT id, school_year, semester, is_active FROM enr_academic_periods
             WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findPeriod(int $periodId): ?array
    {
        if ($periodId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, school_year, semester, is_active FROM enr_academic_periods
             WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$periodId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

/**
 * Placeholder until the Document Upload Portal exists.
 *
 * Deliberately reports nothing rather than something plausible. Google OCR
 * verification, replacement handling, and upload history all belong to that
 * subsystem, and Pre-Registration must not pretend to know their outcome.
 */
final class EnrUnavailableDocumentStatusProvider implements DocumentStatusProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function documentsForApplication(int $applicationId): array
    {
        return [];
    }

    public function allRequiredSubmitted(int $applicationId): ?bool
    {
        return null;
    }

    public function allVerified(int $applicationId): ?bool
    {
        return null;
    }
}

/**
 * Live implementation, backed by the Document Upload Portal's own tables.
 *
 * This is the swap the stub above was written for. Pre-Registration's two
 * document rules now return real PASS and FAIL results instead of WARNING,
 * and no code in Pre-Registration changed to make that happen.
 *
 * It degrades honestly: if the document schema has not been installed, it
 * reports itself unavailable and the rules fall back to WARNING rather than
 * failing an applicant over a missing migration.
 */
final class EnrDbDocumentStatusProvider implements DocumentStatusProvider
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isAvailable(): bool
    {
        return enrDocSchemaInstalled($this->pdo);
    }

    public function documentsForApplication(int $applicationId): array
    {
        if (!$this->isAvailable() || $applicationId <= 0) {
            return [];
        }
        return enrDocsForApplication($this->pdo, $applicationId);
    }

    /**
     * Has every REQUIRED document been uploaded? Optional requirements never
     * block, and an uploaded-but-unverified file counts as submitted here:
     * submission and verification are two different questions.
     */
    public function allRequiredSubmitted(int $applicationId): ?bool
    {
        $completeness = $this->completeness($applicationId);
        if ($completeness === null) {
            return null;
        }

        // Only REQUIRED documents count. An optional requirement the applicant
        // never sent is not a gap. A document that needs replacing was still
        // submitted; objecting to it is the verification rule's job, not this one.
        return (int) $completeness['required_missing'] === 0;
    }

    /** Is every required document actually Verified? */
    public function allVerified(int $applicationId): ?bool
    {
        $completeness = $this->completeness($applicationId);
        if ($completeness === null) {
            return null;
        }

        return $completeness['status'] === ENR_DOCS_COMPLETE;
    }

    /**
     * @return array<string,mixed>|null null when the answer cannot be computed
     */
    private function completeness(int $applicationId): ?array
    {
        if (!$this->isAvailable() || $applicationId <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, applicant_type FROM enr_applications WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch();

            if ($application === false) {
                return null;
            }

            $summary = enrDocApplicationSummary($this->pdo, $application);

            // No configured requirements means there is nothing to judge
            // against. Returning null keeps the rule at WARNING rather than
            // passing an applicant who has uploaded nothing at all.
            if ((int) $summary['completeness']['required_total'] === 0) {
                return null;
            }

            return $summary['completeness'];
        } catch (Throwable $e) {
            error_log('Document status provider failed: ' . $e->getMessage());
            return null;
        }
    }
}

// ---------------------------------------------------------------------------
// Resolution — the single place a screen asks for a provider
// ---------------------------------------------------------------------------

function enrProgramProvider(?PDO $pdo = null): ProgramProvider
{
    static $instance = null;
    if ($instance === null) {
        $instance = new EnrDbProgramProvider($pdo ?? getDatabaseConnection());
    }
    return $instance;
}

function enrAcademicPeriodProvider(?PDO $pdo = null): AcademicPeriodProvider
{
    static $instance = null;
    if ($instance === null) {
        $instance = new EnrDbAcademicPeriodProvider($pdo ?? getDatabaseConnection());
    }
    return $instance;
}

function enrDocumentStatusProvider(?PDO $pdo = null): DocumentStatusProvider
{
    static $instance = null;
    if ($instance === null) {
        // The Document Upload Portal is built, so the live provider is used.
        // It reports itself unavailable if the schema is missing, in which case
        // the Pre-Registration rules fall back to WARNING exactly as before.
        try {
            $instance = new EnrDbDocumentStatusProvider($pdo ?? getDatabaseConnection());
        } catch (Throwable $e) {
            error_log('Document status provider unavailable: ' . $e->getMessage());
            $instance = new EnrUnavailableDocumentStatusProvider();
        }
    }
    return $instance;
}
