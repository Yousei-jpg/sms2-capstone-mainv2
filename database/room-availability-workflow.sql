-- Room Availability Checker workflow support.
-- Room facilities are configuration data; this migration intentionally does
-- not seed institution-specific facilities or room rules.

ALTER TABLE rooms
    MODIFY COLUMN status ENUM('Available','Unavailable','Maintenance','Reserved')
    NOT NULL DEFAULT 'Available';

CREATE TABLE IF NOT EXISTS room_facilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id INT UNSIGNED NOT NULL,
    facility_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Unavailable') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_room_facility (room_id, facility_name),
    KEY idx_room_facility_status (status, facility_name),
    CONSTRAINT fk_room_facility_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_assignment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type ENUM('Regular','Special','Exam') NOT NULL,
    source_record_id BIGINT UNSIGNED NOT NULL,
    previous_room_id INT UNSIGNED DEFAULT NULL,
    assigned_room_id INT UNSIGNED NOT NULL,
    schedule_snapshot_json LONGTEXT NOT NULL,
    validation_json LONGTEXT NOT NULL,
    assigned_by INT UNSIGNED DEFAULT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_room_assignment_source (source_type, source_record_id, assigned_at),
    KEY idx_room_assignment_room (assigned_room_id, assigned_at),
    KEY fk_room_assignment_previous_room (previous_room_id),
    KEY fk_room_assignment_user (assigned_by),
    CONSTRAINT fk_room_assignment_previous_room
        FOREIGN KEY (previous_room_id) REFERENCES rooms(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_room_assignment_room
        FOREIGN KEY (assigned_room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_room_assignment_user
        FOREIGN KEY (assigned_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
