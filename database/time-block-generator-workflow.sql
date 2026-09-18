-- Time Block Generator workflow support.
-- Time ranges and breaks remain configurable; no institution-specific hours are seeded.

CREATE TABLE IF NOT EXISTS time_block_sets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(30) DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    interval_minutes SMALLINT UNSIGNED NOT NULL,
    break_start TIME DEFAULT NULL,
    break_end TIME DEFAULT NULL,
    block_type ENUM('Class','Exam') NOT NULL DEFAULT 'Class',
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    settings_json LONGTEXT DEFAULT NULL,
    block_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_time_block_set_reference (reference_no),
    KEY idx_time_block_set_status (status, created_at),
    CONSTRAINT fk_time_block_set_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_block_set_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    time_block_set_id BIGINT UNSIGNED NOT NULL,
    time_block_id INT UNSIGNED NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_time_block_set_item (time_block_set_id, time_block_id),
    KEY idx_time_block_set_item_block (time_block_id),
    CONSTRAINT fk_time_block_set_item_set FOREIGN KEY (time_block_set_id) REFERENCES time_block_sets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_time_block_set_item_block FOREIGN KEY (time_block_id) REFERENCES time_blocks(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_block_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    time_block_id INT UNSIGNED DEFAULT NULL,
    time_block_set_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(40) NOT NULL,
    detail VARCHAR(500) NOT NULL,
    snapshot_json LONGTEXT DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_time_block_history_block (time_block_id, created_at),
    KEY idx_time_block_history_set (time_block_set_id, created_at),
    KEY fk_time_block_history_user (changed_by),
    CONSTRAINT fk_time_block_history_block FOREIGN KEY (time_block_id) REFERENCES time_blocks(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_time_block_history_set FOREIGN KEY (time_block_set_id) REFERENCES time_block_sets(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_time_block_history_user FOREIGN KEY (changed_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
