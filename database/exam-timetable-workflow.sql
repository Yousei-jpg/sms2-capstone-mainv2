-- Exam Timetable Generator: draft-first, validated, auditable workflow.
-- Generation rules are stored per timetable instead of hard-coded here.

CREATE TABLE IF NOT EXISTS exam_timetable_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(40) DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    exam_type ENUM('Prelim','Midterm','Semi-Final','Final','Special') NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester ENUM('1st Semester','2nd Semester','Summer') NOT NULL,
    date_from DATE DEFAULT NULL,
    date_to DATE DEFAULT NULL,
    status ENUM('Draft','Generated','For Validation','Validated','Ready to Publish','Published','Cancelled') NOT NULL DEFAULT 'Draft',
    options_json LONGTEXT DEFAULT NULL,
    solver_engine VARCHAR(80) DEFAULT NULL,
    validation_run_id VARCHAR(60) DEFAULT NULL,
    validation_summary_json LONGTEXT DEFAULT NULL,
    published_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_exam_batch_reference (reference_no),
    KEY idx_exam_batch_term (academic_year, semester, exam_type, status),
    CONSTRAINT fk_exam_batch_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE exam_schedules
    ADD COLUMN IF NOT EXISTS batch_id BIGINT UNSIGNED DEFAULT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS time_block_id INT UNSIGNED DEFAULT NULL AFTER room_id;

ALTER TABLE exam_schedules
    MODIFY COLUMN status ENUM('Draft','Generated','For Validation','Validated','Ready to Publish','Published','Cancelled')
    NOT NULL DEFAULT 'Draft';

SET @has_exam_batch_fk = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_schedules'
       AND CONSTRAINT_NAME = 'fk_exam_schedule_batch'
);
SET @add_exam_batch_fk = IF(@has_exam_batch_fk = 0,
    'ALTER TABLE exam_schedules ADD CONSTRAINT fk_exam_schedule_batch FOREIGN KEY (batch_id) REFERENCES exam_timetable_batches(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @add_exam_batch_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_exam_block_fk = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_schedules'
       AND CONSTRAINT_NAME = 'fk_exam_schedule_time_block'
);
SET @add_exam_block_fk = IF(@has_exam_block_fk = 0,
    'ALTER TABLE exam_schedules ADD CONSTRAINT fk_exam_schedule_time_block FOREIGN KEY (time_block_id) REFERENCES time_blocks(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @add_exam_block_fk; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE INDEX IF NOT EXISTS idx_exam_batch ON exam_schedules(batch_id, status);
CREATE INDEX IF NOT EXISTS idx_exam_time_block ON exam_schedules(time_block_id);

CREATE TABLE IF NOT EXISTS exam_timetable_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    exam_schedule_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) DEFAULT NULL,
    detail VARCHAR(500) NOT NULL,
    snapshot_json LONGTEXT DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exam_history_batch (batch_id, created_at),
    KEY idx_exam_history_schedule (exam_schedule_id),
    KEY fk_exam_history_user (changed_by),
    CONSTRAINT fk_exam_history_batch FOREIGN KEY (batch_id) REFERENCES exam_timetable_batches(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_exam_history_schedule FOREIGN KEY (exam_schedule_id) REFERENCES exam_schedules(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_exam_history_user FOREIGN KEY (changed_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_timetable_shares (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    audience ENUM('Faculty','Students/Sections','Program Heads','Scheduling Staff') NOT NULL,
    delivery_status ENUM('Queued','Sent','Skipped') NOT NULL DEFAULT 'Queued',
    message VARCHAR(500) DEFAULT NULL,
    sent_by INT UNSIGNED DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exam_share_batch (batch_id, created_at),
    CONSTRAINT fk_exam_share_batch FOREIGN KEY (batch_id) REFERENCES exam_timetable_batches(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_exam_share_user FOREIGN KEY (sent_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve pre-workflow exam records in an auditable migrated batch.
INSERT IGNORE INTO exam_timetable_batches
    (reference_no, title, exam_type, academic_year, semester, date_from, date_to, status, solver_engine, created_by)
SELECT CONCAT('EXM-LEGACY-', UPPER(REPLACE(exam_type,'-','')), '-',
              REPLACE(COALESCE(academic_year,'UNKNOWN'),'-',''), '-',
              LEFT(REPLACE(COALESCE(semester,'TERM'),' ',''),8)),
       CONCAT(exam_type, ' Examination Timetable (Migrated)'), exam_type,
       COALESCE(academic_year,'Unspecified'), COALESCE(semester,'1st Semester'),
       MIN(exam_date), MAX(exam_date),
       CASE WHEN SUM(status='Published') = COUNT(*) THEN 'Published' ELSE 'Draft' END,
       'Legacy records', MAX(created_by)
  FROM exam_schedules
 WHERE batch_id IS NULL
 GROUP BY exam_type, academic_year, semester;

UPDATE exam_schedules ex
JOIN exam_timetable_batches b
  ON b.reference_no = CONCAT('EXM-LEGACY-', UPPER(REPLACE(ex.exam_type,'-','')), '-',
       REPLACE(COALESCE(ex.academic_year,'UNKNOWN'),'-',''), '-',
       LEFT(REPLACE(COALESCE(ex.semester,'TERM'),' ',''),8))
SET ex.batch_id = b.id
WHERE ex.batch_id IS NULL;
