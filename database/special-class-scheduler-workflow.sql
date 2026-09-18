-- Special Class Scheduler workflow extension
-- Adds only data owned by the Special Class Scheduling subsystem.

ALTER TABLE special_classes
    MODIFY special_type ENUM(
        'Remedial','Irregular','Midyear','Overload','Makeup',
        'Cross-enrollment','Tutorial','Review','Seminar','Other'
    ) NOT NULL DEFAULT 'Makeup',
    MODIFY status ENUM(
        'Draft','For Scheduling','Scheduled','Validated',
        'Ready to Publish','Published','Cancelled'
    ) NOT NULL DEFAULT 'Draft',
    ADD COLUMN IF NOT EXISTS reference_no VARCHAR(30) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS academic_year VARCHAR(20) NULL AFTER special_type,
    ADD COLUMN IF NOT EXISTS semester VARCHAR(30) NULL AFTER academic_year,
    ADD COLUMN IF NOT EXISTS program VARCHAR(30) NULL AFTER semester,
    ADD COLUMN IF NOT EXISTS assignment_mode ENUM('Section','Students') NOT NULL DEFAULT 'Section' AFTER program,
    ADD COLUMN IF NOT EXISTS time_block_id INT(10) UNSIGNED NULL AFTER room_id,
    ADD COLUMN IF NOT EXISTS validated_at DATETIME NULL AFTER remarks,
    ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER validated_at;

UPDATE special_classes
   SET reference_no = CONCAT('SC-', YEAR(created_at), '-', LPAD(id, 6, '0'))
 WHERE reference_no IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_special_reference
    ON special_classes (reference_no);

CREATE INDEX IF NOT EXISTS idx_special_term_status
    ON special_classes (academic_year, semester, status);

CREATE TABLE IF NOT EXISTS special_class_students (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    special_class_id BIGINT(20) UNSIGNED NOT NULL,
    student_key VARCHAR(60) NOT NULL,
    student_user_id INT(10) UNSIGNED NULL,
    pre_registration_id INT(10) UNSIGNED NULL,
    student_ref VARCHAR(40) NULL,
    student_name VARCHAR(150) NOT NULL,
    program VARCHAR(40) NULL,
    year_level TINYINT(3) UNSIGNED NULL,
    eligibility_status ENUM('Eligible','Conditional','Not Eligible','Not Configured')
        NOT NULL DEFAULT 'Not Configured',
    eligibility_note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_special_student (special_class_id, student_key),
    KEY idx_special_student_user (student_user_id),
    KEY idx_special_student_prereg (pre_registration_id),
    CONSTRAINT fk_special_student_class
        FOREIGN KEY (special_class_id) REFERENCES special_classes (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_special_student_user
        FOREIGN KEY (student_user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_special_student_prereg
        FOREIGN KEY (pre_registration_id) REFERENCES enr_pre_registrations (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS special_class_history (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    special_class_id BIGINT(20) UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    detail VARCHAR(500) NOT NULL,
    actor_user_id INT(10) UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_special_history_class (special_class_id, created_at),
    CONSTRAINT fk_special_history_class
        FOREIGN KEY (special_class_id) REFERENCES special_classes (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_special_history_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB does not support ADD CONSTRAINT IF NOT EXISTS consistently.
-- The API verifies time blocks from the shared table even if an FK is absent.
