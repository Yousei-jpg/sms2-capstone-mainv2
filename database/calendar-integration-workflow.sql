-- Calendar Integration: published schedule distribution, OAuth connections,
-- idempotent event sync, and auditable export history.

CREATE TABLE IF NOT EXISTS calendar_integrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(40) DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    method ENUM('ICS','Google') NOT NULL DEFAULT 'ICS',
    schedule_key VARCHAR(120) NOT NULL,
    schedule_type ENUM('Regular','Special','Exam') NOT NULL,
    academic_year VARCHAR(20) DEFAULT NULL,
    semester VARCHAR(30) DEFAULT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    recipient_keys_json LONGTEXT DEFAULT NULL,
    settings_json LONGTEXT DEFAULT NULL,
    source_fingerprint CHAR(64) DEFAULT NULL,
    google_calendar_id VARCHAR(255) DEFAULT NULL,
    google_account_email VARCHAR(190) DEFAULT NULL,
    access_token_encrypted LONGTEXT DEFAULT NULL,
    refresh_token_encrypted LONGTEXT DEFAULT NULL,
    token_expires_at DATETIME DEFAULT NULL,
    status ENUM('Draft','Connected','Synced','Needs Resync','Failed','Disconnected') NOT NULL DEFAULT 'Draft',
    event_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_sync_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_calendar_reference (reference_no),
    KEY idx_calendar_status (status, updated_at),
    KEY idx_calendar_schedule (schedule_type, academic_year, semester),
    CONSTRAINT fk_calendar_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_integration_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration_id BIGINT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    source_type ENUM('Class','Special','Exam') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    occurrence_date DATE NOT NULL,
    google_event_id VARCHAR(255) DEFAULT NULL,
    content_hash CHAR(64) NOT NULL,
    sync_status ENUM('Created','Updated','Unchanged','Failed','Removed') NOT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_calendar_event (integration_id, event_key),
    KEY idx_calendar_event_google (google_event_id),
    CONSTRAINT fk_calendar_event_integration FOREIGN KEY (integration_id)
        REFERENCES calendar_integrations(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_integration_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    integration_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(60) NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) DEFAULT NULL,
    detail VARCHAR(500) NOT NULL,
    metadata_json LONGTEXT DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calendar_history (integration_id, created_at),
    CONSTRAINT fk_calendar_history_integration FOREIGN KEY (integration_id)
        REFERENCES calendar_integrations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_calendar_history_user FOREIGN KEY (changed_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
