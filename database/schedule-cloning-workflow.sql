-- Schedule Cloning Tool: auditable draft-first workflow.

CREATE TABLE IF NOT EXISTS schedule_clone_batches (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(30) NOT NULL,
    schedule_name VARCHAR(150) NOT NULL,
    schedule_type ENUM('Regular') NOT NULL DEFAULT 'Regular',
    source_section_id INT(10) UNSIGNED NOT NULL,
    target_section_id INT(10) UNSIGNED NOT NULL,
    source_academic_year VARCHAR(20) NOT NULL,
    source_semester VARCHAR(30) NOT NULL,
    target_academic_year VARCHAR(20) NOT NULL,
    target_semester VARCHAR(30) NOT NULL,
    options_json LONGTEXT NOT NULL,
    status ENUM('Draft','Has Conflicts','Validated','Ready to Publish','Published','Cancelled') NOT NULL DEFAULT 'Draft',
    cloned_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
    latest_validation_run_id VARCHAR(40) NULL,
    validated_at DATETIME NULL,
    published_at DATETIME NULL,
    created_by INT(10) UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_clone_reference (reference_no),
    KEY idx_clone_status_date (status, created_at),
    KEY idx_clone_source (source_section_id, source_academic_year, source_semester),
    KEY idx_clone_target (target_section_id, target_academic_year, target_semester),
    KEY idx_clone_validation_run (latest_validation_run_id),
    CONSTRAINT fk_clone_source_section FOREIGN KEY (source_section_id) REFERENCES sections (id) ON UPDATE CASCADE,
    CONSTRAINT fk_clone_target_section FOREIGN KEY (target_section_id) REFERENCES sections (id) ON UPDATE CASCADE,
    CONSTRAINT fk_clone_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_clone_batch_entries (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    clone_batch_id BIGINT(20) UNSIGNED NOT NULL,
    source_entry_id BIGINT(20) UNSIGNED NULL,
    cloned_entry_id BIGINT(20) UNSIGNED NOT NULL,
    preview_warning_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_clone_batch_entry (clone_batch_id, cloned_entry_id),
    UNIQUE KEY uk_cloned_entry (cloned_entry_id),
    KEY idx_clone_source_entry (source_entry_id),
    CONSTRAINT fk_clone_entry_batch FOREIGN KEY (clone_batch_id) REFERENCES schedule_clone_batches (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_clone_entry_source FOREIGN KEY (source_entry_id) REFERENCES schedule_entries (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_clone_entry_created FOREIGN KEY (cloned_entry_id) REFERENCES schedule_entries (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_clone_history (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    clone_batch_id BIGINT(20) UNSIGNED NOT NULL,
    action VARCHAR(80) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    detail VARCHAR(500) NOT NULL,
    snapshot_json LONGTEXT NULL,
    changed_by INT(10) UNSIGNED NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_clone_history (clone_batch_id, changed_at),
    CONSTRAINT fk_clone_history_batch FOREIGN KEY (clone_batch_id) REFERENCES schedule_clone_batches (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_clone_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
