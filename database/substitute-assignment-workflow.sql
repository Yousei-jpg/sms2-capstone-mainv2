-- Substitute Assignment Tracker workflow support.
-- Adds auditable lifecycle, validation snapshots, and an in-system notification queue.

ALTER TABLE substitute_assignments
    ADD COLUMN IF NOT EXISTS reference_no VARCHAR(35) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS absence_end_date DATE NULL AFTER absence_date,
    ADD COLUMN IF NOT EXISTS duration_label VARCHAR(80) NULL AFTER absence_end_date,
    ADD COLUMN IF NOT EXISTS validation_json LONGTEXT NULL AFTER remarks,
    ADD COLUMN IF NOT EXISTS assigned_at DATETIME NULL AFTER validation_json,
    ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER assigned_at,
    ADD COLUMN IF NOT EXISTS notified_at DATETIME NULL AFTER completed_at,
    ADD UNIQUE KEY IF NOT EXISTS uk_substitute_reference (reference_no);

ALTER TABLE substitute_assignments
    MODIFY COLUMN status ENUM('Pending','Assigned','Ongoing','Completed','Cancelled')
    NOT NULL DEFAULT 'Pending';

CREATE TABLE IF NOT EXISTS substitute_assignment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    substitute_assignment_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) DEFAULT NULL,
    detail VARCHAR(500) NOT NULL,
    snapshot_json LONGTEXT DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_substitute_history_assignment (substitute_assignment_id, changed_at),
    KEY fk_substitute_history_user (changed_by),
    CONSTRAINT fk_substitute_history_assignment
        FOREIGN KEY (substitute_assignment_id) REFERENCES substitute_assignments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_history_user
        FOREIGN KEY (changed_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduling_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(100) NOT NULL,
    recipient_type ENUM('Teacher','Section','Scheduling Staff') NOT NULL,
    recipient_id INT UNSIGNED DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    action_url VARCHAR(255) DEFAULT NULL,
    status ENUM('Queued','Sent','Failed','Read') NOT NULL DEFAULT 'Queued',
    related_type VARCHAR(50) DEFAULT NULL,
    related_id BIGINT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_scheduling_notification_event (event_key, recipient_type, recipient_id),
    KEY idx_scheduling_notification_recipient (recipient_type, recipient_id, status),
    KEY idx_scheduling_notification_related (related_type, related_id),
    KEY fk_scheduling_notification_user (created_by),
    CONSTRAINT fk_scheduling_notification_user
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
