-- ============================================================================
-- SMS 2 - Enrollment Module Schema
-- Subsystem: Registration -> Online Pre-Registration
--
-- Target database: sms2_db (the main SMS 2 database).
-- Enrollment lives here, not in a separate database, because its records
-- reference `users`.`id` for the reviewing Registrar. A cross-database
-- foreign key is not possible, so the tables are namespaced with `enr_`
-- instead of isolated in their own schema.
--
-- Every statement is idempotent. Running this file twice changes nothing.
-- Run through: modules/enrollment/database/install_enrollment.php
-- ============================================================================

-- ----------------------------------------------------------------------------
-- enr_academic_periods
--
-- OPEN QUESTION: no academic-period table exists anywhere in SMS 2, and no
-- module currently claims ownership of school year / semester. Enrollment
-- creates it provisionally so applications can be tied to a term. If a shared
-- academic-period source is introduced later, this table becomes the mirror
-- and AcademicPeriodProvider is repointed. No query outside the provider
-- reads this table directly.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enr_academic_periods` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_year` varchar(20)  NOT NULL COMMENT 'e.g. 2026-2027',
  `semester`    varchar(30)  NOT NULL COMMENT 'e.g. 1st Semester',
  `start_date`  date         DEFAULT NULL,
  `end_date`    date         DEFAULT NULL,
  `is_active`   tinyint(1)   NOT NULL DEFAULT 0,
  `created_at`  datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enr_period` (`school_year`, `semester`),
  KEY `idx_enr_period_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- enr_programs
--
-- OPEN QUESTION: the Curriculum module is an empty page stub and owns no data.
-- The Registrar's queue needs a Program filter, so Enrollment holds a minimal
-- reference list. `source` marks where each row came from so the handover to
-- Curriculum is traceable. Read only through ProgramProvider.
-- This is NOT a curriculum table: no units, prerequisites, or subject mapping.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enr_programs` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       varchar(20)  NOT NULL COMMENT 'e.g. BSIT',
  `name`       varchar(150) NOT NULL,
  `level`      varchar(40)  NOT NULL DEFAULT 'College',
  `is_active`  tinyint(1)   NOT NULL DEFAULT 1,
  `source`     varchar(40)  NOT NULL DEFAULT 'enrollment-stub'
               COMMENT 'enrollment-stub until Curriculum owns this record',
  `created_at` datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enr_program_code` (`code`),
  KEY `idx_enr_program_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- enr_applications
--
-- The Online Pre-Registration transaction. Applicant-submitted values live
-- here because an applicant is not yet a student and no student table exists.
-- Program and academic period are referenced by id, never copied as text.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enr_applications` (
  `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_no`       varchar(30)  NOT NULL COMMENT 'system-generated, format is configurable',
  `applicant_type`     varchar(30)  NOT NULL DEFAULT 'Freshman',
  `status`             varchar(20)  NOT NULL DEFAULT 'Submitted',

  -- Personal information (applicant-entered, read-only to the Registrar)
  `first_name`         varchar(80)  NOT NULL,
  `middle_name`        varchar(80)  DEFAULT NULL,
  `last_name`          varchar(80)  NOT NULL,
  `birth_date`         date         DEFAULT NULL,
  `sex`                varchar(20)  DEFAULT NULL,
  `civil_status`       varchar(20)  DEFAULT NULL,
  `email`              varchar(190) NOT NULL,
  `mobile_no`          varchar(30)  DEFAULT NULL,
  `current_address`    varchar(255) DEFAULT NULL,

  -- Academic information (applicant-entered)
  `previous_school`    varchar(150) DEFAULT NULL,
  `school_type`        varchar(60)  DEFAULT NULL,
  `year_graduated`     smallint(5) UNSIGNED DEFAULT NULL,
  `grade_average`      decimal(5,2) DEFAULT NULL,
  `previous_program`   varchar(120) DEFAULT NULL,

  -- Program and intake (referenced, not duplicated)
  `program_id`         int(10) UNSIGNED DEFAULT NULL,
  `academic_period_id` int(10) UNSIGNED DEFAULT NULL,
  `entry_level`        varchar(40)  DEFAULT NULL,
  `campus`             varchar(80)  DEFAULT NULL,
  `preferred_section`  varchar(40)  DEFAULT NULL,

  -- Linkage: null until the applicant has a system account
  `applicant_user_id`  int(10) UNSIGNED DEFAULT NULL,

  `submitted_at`       datetime     DEFAULT NULL,
  `created_at`         datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`         datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_enr_application_ref` (`reference_no`),
  KEY `idx_enr_application_status` (`status`),
  KEY `idx_enr_application_program` (`program_id`),
  KEY `idx_enr_application_period` (`academic_period_id`),
  KEY `idx_enr_application_type` (`applicant_type`),
  KEY `idx_enr_application_submitted` (`submitted_at`),
  KEY `idx_enr_application_email` (`email`),
  KEY `idx_enr_application_user` (`applicant_user_id`),
  KEY `idx_enr_application_lastname` (`last_name`),

  CONSTRAINT `fk_enr_application_program`
    FOREIGN KEY (`program_id`) REFERENCES `enr_programs` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enr_application_period`
    FOREIGN KEY (`academic_period_id`) REFERENCES `enr_academic_periods` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enr_application_user`
    FOREIGN KEY (`applicant_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- enr_application_reviews
--
-- One row per Registrar decision. Never updated, never deleted: a correction
-- loop produces a second row, not an edit of the first. This is the domain
-- record of the decision. The system-wide audit trail stays in `activity_logs`
-- and is written through logActivity().
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enr_application_reviews` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id`  int(10) UNSIGNED NOT NULL,
  `decision`        varchar(20)  NOT NULL COMMENT 'Approved | For Correction | Rejected | Draft',
  `checklist_json`  text         DEFAULT NULL COMMENT 'validation rule results at decision time',
  `remarks`         text         DEFAULT NULL,
  `previous_status` varchar(20)  DEFAULT NULL,
  `new_status`      varchar(20)  NOT NULL,
  `reviewed_by`     int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at`     datetime     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enr_review_application` (`application_id`),
  KEY `idx_enr_review_decision` (`decision`),
  KEY `idx_enr_review_reviewer` (`reviewed_by`),
  KEY `idx_enr_review_date` (`reviewed_at`),
  CONSTRAINT `fk_enr_review_application`
    FOREIGN KEY (`application_id`) REFERENCES `enr_applications` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enr_review_user`
    FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Reference seed data
--
-- These are institutional reference values, not transactional records and not
-- sample applicants. Sample applicants live in seed_sample_applications.php,
-- which is a separate opt-in script.
-- ============================================================================

INSERT IGNORE INTO `enr_academic_periods`
  (`school_year`, `semester`, `is_active`) VALUES
  ('2026-2027', '1st Semester', 1),
  ('2026-2027', '2nd Semester', 0);

INSERT IGNORE INTO `enr_programs` (`code`, `name`, `level`) VALUES
  ('BSIT',  'Bachelor of Science in Information Technology', 'College'),
  ('BSCS',  'Bachelor of Science in Computer Science',       'College'),
  ('BSBA',  'Bachelor of Science in Business Administration','College'),
  ('BSA',   'Bachelor of Science in Accountancy',            'College'),
  ('BSED',  'Bachelor of Secondary Education',               'College'),
  ('BSHM',  'Bachelor of Science in Hospitality Management', 'College');

-- Reference number counter. Stored in the existing system_settings table so the
-- format stays configurable instead of hardcoded.
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('enr_prereg_ref_prefix',   'PR'),
  ('enr_prereg_ref_padding',  '3');
