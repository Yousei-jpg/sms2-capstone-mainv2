-- ============================================================================
-- SMS 2 - Enrollment Module Schema
-- Subsystem: Registration -> Document Upload Portal
--
-- Target database: sms2_db. Depends on enrollment_db.sql having run first,
-- because uploaded documents reference enr_applications.
--
-- Every statement is idempotent. Running this file twice changes nothing.
-- Run through: modules/enrollment/database/install_document_upload.php
-- ============================================================================

-- ----------------------------------------------------------------------------
-- enr_document_requirements
--
-- Which documents an applicant must submit. This is a TABLE rather than a PHP
-- array because the list differs by applicant type: a Freshman submits Form
-- 138, a Transferee submits a Transcript of Records and an Honorable Dismissal
-- instead. Keeping it as data means BCP can change its requirements without a
-- code change.
--
-- OPEN QUESTION: BCP's official required-document list per applicant type is
-- unconfirmed. The seeds below follow the UI reference and are placeholders.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enr_document_requirements` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `applicant_type` varchar(30)  NOT NULL COMMENT 'matches enr_applications.applicant_type',
  `document_name`  varchar(120) NOT NULL,
  `description`    varchar(255) DEFAULT NULL COMMENT 'shown to the applicant as guidance',
  `is_required`    tinyint(1)   NOT NULL DEFAULT 1,
  `sort_order`     smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active`      tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enr_requirement` (`applicant_type`, `document_name`),
  KEY `idx_enr_requirement_type` (`applicant_type`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- enr_application_documents
--
-- One row per document an applicant has actually uploaded.
--
-- IMPORTANT: a MISSING document has NO row here. "Missing" is the absence of a
-- row against an active requirement, not a status value. That keeps the two
-- states in your specification genuinely distinct:
--   Missing            - never submitted (no row)
--   Needs Replacement  - submitted but inadequate (row exists, status set)
--
-- stored_name is the random filename produced by smsSecureUpload(). The file
-- itself lives under storage/uploads/enrollment-documents/, which carries an
-- .htaccess deny, so it is never reachable by URL. Serving goes through
-- modules/enrollment/api/document-file.php, which checks the session first.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enr_application_documents` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id`  int(10) UNSIGNED NOT NULL,
  `requirement_id`  int(10) UNSIGNED DEFAULT NULL COMMENT 'null for an extra document outside the checklist',
  `document_name`   varchar(120) NOT NULL COMMENT 'copied at upload time so history survives a requirement rename',
  `status`          varchar(30)  NOT NULL DEFAULT 'For Verification',

  -- Stored file details
  `original_name`   varchar(255) NOT NULL COMMENT 'the applicant filename, shown in the UI',
  `stored_name`     varchar(120) NOT NULL COMMENT 'random name on disk from smsSecureUpload()',
  `mime_type`       varchar(100) DEFAULT NULL,
  `file_size`       int(10) UNSIGNED NOT NULL DEFAULT 0,

  -- OCR is advisory only and never decides a status on its own.
  `ocr_status`      varchar(20)  NOT NULL DEFAULT 'Not Run'
                    COMMENT 'Not Run | Success | Failed | Disabled',
  `ocr_text`        mediumtext   DEFAULT NULL,
  `ocr_ran_at`      datetime     DEFAULT NULL,

  `uploaded_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  `uploaded_by`     int(10) UNSIGNED DEFAULT NULL COMMENT 'null when the applicant uploaded it themselves',
  `updated_at`      datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_enr_doc_application` (`application_id`),
  KEY `idx_enr_doc_requirement` (`requirement_id`),
  KEY `idx_enr_doc_status` (`status`),
  KEY `idx_enr_doc_uploaded` (`uploaded_at`),
  CONSTRAINT `fk_enr_doc_application`
    FOREIGN KEY (`application_id`) REFERENCES `enr_applications` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enr_doc_requirement`
    FOREIGN KEY (`requirement_id`) REFERENCES `enr_document_requirements` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enr_doc_uploader`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- enr_document_verifications
--
-- One row per Registrar verification decision. Never updated, never deleted:
-- asking for a replacement and then verifying the new file produces two rows,
-- so the reason a document was rejected the first time is not lost.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enr_document_verifications` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id`     int(10) UNSIGNED NOT NULL,
  `decision`        varchar(30)  NOT NULL COMMENT 'Verified | Needs Replacement | Rejected',
  `remarks`         text         DEFAULT NULL,
  `previous_status` varchar(30)  DEFAULT NULL,
  `new_status`      varchar(30)  NOT NULL,
  `verified_by`     int(10) UNSIGNED DEFAULT NULL,
  `verified_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enr_verify_document` (`document_id`),
  KEY `idx_enr_verify_decision` (`decision`),
  KEY `idx_enr_verify_date` (`verified_at`),
  CONSTRAINT `fk_enr_verify_document`
    FOREIGN KEY (`document_id`) REFERENCES `enr_application_documents` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enr_verify_user`
    FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Requirement seeds
--
-- OPEN QUESTION: replace these with BCP's official list when confirmed.
-- Freshman follows the UI reference. Transferee and Returning differ where the
-- difference is obvious (a transferee has no Form 138), and are marked for
-- confirmation rather than guessed at in detail.
-- ============================================================================

INSERT IGNORE INTO `enr_document_requirements`
  (`applicant_type`, `document_name`, `description`, `is_required`, `sort_order`) VALUES
  ('Freshman', 'Birth Certificate',        'PSA or NSO issued copy.',                        1, 1),
  ('Freshman', 'Report Card (Form 138)',   'Senior high school report card.',                1, 2),
  ('Freshman', 'Good Moral Certificate',   'Issued by your previous school.',                1, 3),
  ('Freshman', '2x2 ID Photo',             'Recent photo, white background.',                1, 4),
  ('Freshman', 'Certificate of Indigency', 'Only if applying for financial assistance.',     0, 5),
  ('Freshman', 'Other Documents',          'Any additional supporting document.',            0, 6),

  ('Transferee', 'Birth Certificate',              'PSA or NSO issued copy.',                1, 1),
  ('Transferee', 'Transcript of Records',          'From your previous college.',            1, 2),
  ('Transferee', 'Honorable Dismissal',            'Transfer credential from your previous college.', 1, 3),
  ('Transferee', 'Good Moral Certificate',         'Issued by your previous college.',       1, 4),
  ('Transferee', '2x2 ID Photo',                   'Recent photo, white background.',        1, 5),
  ('Transferee', 'Other Documents',                'Any additional supporting document.',    0, 6),

  ('Returning', 'Birth Certificate',   'PSA or NSO issued copy.',                            1, 1),
  ('Returning', 'Good Moral Certificate', 'Issued by your previous school.',                 1, 2),
  ('Returning', '2x2 ID Photo',        'Recent photo, white background.',                    1, 3),
  ('Returning', 'Other Documents',     'Any additional supporting document.',                0, 4),

  ('Cross-Enrollee', 'Permit to Cross-Enroll', 'Signed permit from your home institution.',  1, 1),
  ('Cross-Enrollee', '2x2 ID Photo',           'Recent photo, white background.',            1, 2),
  ('Cross-Enrollee', 'Other Documents',        'Any additional supporting document.',        0, 3);

-- ============================================================================
-- Configuration
--
-- Upload limits and the OCR switch. OCR ships disabled: it stays off until a
-- Vision API key is configured, and the portal works fully without it.
-- The API key itself is NOT seeded here. It is written encrypted through the
-- Administrator settings screen, the same way smtp_password already is.
-- ============================================================================
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('enr_doc_max_mb',        '10'),
  ('enr_doc_allowed_ext',   'pdf,jpg,jpeg,png'),
  ('enr_ocr_enabled',       '0'),
  ('enr_ocr_provider',      'google_vision');
