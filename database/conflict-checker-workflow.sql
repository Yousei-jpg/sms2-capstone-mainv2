-- Conflict Checker workflow extension
-- Stores reviewable runs and richer findings without duplicating schedule data.

ALTER TABLE conflict_results
    MODIFY conflict_type ENUM(
        'Teacher','Faculty','Room','Section','Student/Section','Time',
        'Availability','Load','Capacity','Eligibility','Duplicate','Configured Rule'
    ) NOT NULL,
    ADD COLUMN IF NOT EXISTS finding_key VARCHAR(64) NULL AFTER run_id,
    ADD COLUMN IF NOT EXISTS related_scope ENUM('Class','Exam','Special') NULL AFTER reference_id,
    ADD COLUMN IF NOT EXISTS related_reference_id BIGINT(20) UNSIGNED NULL AFTER related_scope,
    ADD COLUMN IF NOT EXISTS affected_record_json LONGTEXT NULL AFTER end_time,
    ADD COLUMN IF NOT EXISTS explanation VARCHAR(500) NULL AFTER affected_record_json,
    ADD COLUMN IF NOT EXISTS suggested_module VARCHAR(60) NULL AFTER recommended_action,
    ADD COLUMN IF NOT EXISTS previous_finding_id BIGINT(20) UNSIGNED NULL AFTER suggested_module,
    ADD COLUMN IF NOT EXISTS rechecked_at DATETIME NULL AFTER resolved_at;

CREATE INDEX IF NOT EXISTS idx_conflict_finding_key
    ON conflict_results (finding_key, is_resolved);

CREATE TABLE IF NOT EXISTS validation_runs (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id VARCHAR(40) NOT NULL,
    previous_run_id VARCHAR(40) NULL,
    academic_year VARCHAR(20) NULL,
    semester VARCHAR(30) NULL,
    schedule_type ENUM('All','Regular','Special','Exam') NOT NULL DEFAULT 'All',
    selected_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
    findings_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
    critical_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
    warning_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
    resolved_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('Running','Has Conflicts','Valid','Completed','Failed') NOT NULL DEFAULT 'Running',
    completed_at DATETIME NULL,
    created_by INT(10) UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_validation_run_id (run_id),
    KEY idx_validation_runs_date (created_at, status),
    CONSTRAINT fk_validation_run_user
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS validation_run_records (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id VARCHAR(40) NOT NULL,
    record_key VARCHAR(60) NOT NULL,
    schedule_type ENUM('Regular','Special','Exam') NOT NULL,
    reference_id BIGINT(20) UNSIGNED NOT NULL,
    before_status VARCHAR(30) NULL,
    result_status ENUM('Valid','Conflict','Warning') NOT NULL DEFAULT 'Valid',
    after_status VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_validation_run_record (run_id, record_key),
    KEY idx_validation_record_ref (schedule_type, reference_id),
    CONSTRAINT fk_validation_record_run
        FOREIGN KEY (run_id) REFERENCES validation_runs (run_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
