-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: sms2_db_recovery
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `conflict_results`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conflict_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(40) NOT NULL,
  `finding_key` varchar(64) DEFAULT NULL,
  `scope` enum('Class','Exam','Special') NOT NULL DEFAULT 'Class',
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `related_scope` enum('Class','Exam','Special') DEFAULT NULL,
  `related_reference_id` bigint(20) unsigned DEFAULT NULL,
  `conflict_type` enum('Teacher','Faculty','Room','Section','Student/Section','Time','Availability','Load','Capacity','Eligibility','Duplicate','Configured Rule') NOT NULL,
  `severity` enum('Error','Warning','Info') NOT NULL DEFAULT 'Error',
  `section_id` int(10) unsigned DEFAULT NULL,
  `teacher_id` int(10) unsigned DEFAULT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  `conflict_date` date DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `affected_record_json` longtext DEFAULT NULL,
  `explanation` varchar(500) DEFAULT NULL,
  `message` varchar(500) NOT NULL,
  `recommended_action` varchar(500) DEFAULT NULL,
  `suggested_module` varchar(60) DEFAULT NULL,
  `previous_finding_id` bigint(20) unsigned DEFAULT NULL,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `rechecked_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_conflict_run` (`run_id`,`severity`),
  KEY `idx_conflict_scope_ref` (`scope`,`reference_id`),
  KEY `idx_conflict_unresolved` (`is_resolved`,`created_at`),
  KEY `fk_conflict_section` (`section_id`),
  KEY `fk_conflict_teacher` (`teacher_id`),
  KEY `fk_conflict_room` (`room_id`),
  KEY `idx_conflict_finding_key` (`finding_key`,`is_resolved`),
  CONSTRAINT `fk_conflict_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_conflict_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_conflict_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exam_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `section_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `proctor_id` int(10) unsigned DEFAULT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  `time_block_id` int(10) unsigned DEFAULT NULL,
  `exam_type` enum('Prelim','Midterm','Semi-Final','Final','Special') NOT NULL DEFAULT 'Final',
  `exam_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `status` enum('Draft','Generated','For Validation','Validated','Ready to Publish','Published','Cancelled') NOT NULL DEFAULT 'Draft',
  `source` enum('Manual','Optimizer','Cloned') NOT NULL DEFAULT 'Manual',
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` enum('1st Semester','2nd Semester','Summer') DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exam_section` (`section_id`,`status`),
  KEY `idx_exam_proctor_time` (`proctor_id`,`exam_date`,`start_time`,`end_time`),
  KEY `idx_exam_room_time` (`room_id`,`exam_date`,`start_time`,`end_time`),
  KEY `fk_exam_subject` (`subject_id`),
  KEY `idx_exam_batch` (`batch_id`,`status`),
  KEY `idx_exam_time_block` (`time_block_id`),
  CONSTRAINT `fk_exam_proctor` FOREIGN KEY (`proctor_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_schedule_batch` FOREIGN KEY (`batch_id`) REFERENCES `exam_timetable_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_schedule_time_block` FOREIGN KEY (`time_block_id`) REFERENCES `time_blocks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rooms`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rooms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_code` varchar(40) NOT NULL,
  `building` varchar(100) DEFAULT NULL,
  `room_type` enum('Lecture','Laboratory','Special') NOT NULL DEFAULT 'Lecture',
  `capacity` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('Available','Unavailable','Maintenance') NOT NULL DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rooms_code` (`room_code`),
  KEY `idx_rooms_status_capacity` (`status`,`capacity`),
  KEY `idx_rooms_type` (`room_type`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schedule_entries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schedule_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `section_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `teacher_id` int(10) unsigned DEFAULT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  `time_block_id` int(10) unsigned DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `class_type` enum('Lecture','Laboratory','Online','Exam') NOT NULL DEFAULT 'Lecture',
  `status` enum('Draft','Validated','Published','Cancelled') NOT NULL DEFAULT 'Draft',
  `source` enum('Manual','Optimizer','Cloned') NOT NULL DEFAULT 'Manual',
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` enum('1st Semester','2nd Semester','Summer') DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_schedule_section` (`section_id`,`status`),
  KEY `idx_schedule_teacher_time` (`teacher_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_schedule_room_time` (`room_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_schedule_term` (`academic_year`,`semester`,`status`),
  KEY `fk_schedule_subject` (`subject_id`),
  KEY `fk_schedule_time_block` (`time_block_id`),
  CONSTRAINT `fk_schedule_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_schedule_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_schedule_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_schedule_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_schedule_time_block` FOREIGN KEY (`time_block_id`) REFERENCES `time_blocks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sections`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `program` varchar(30) NOT NULL,
  `year_level` tinyint(3) unsigned NOT NULL,
  `semester` enum('1st Semester','2nd Semester','Summer') NOT NULL DEFAULT '1st Semester',
  `academic_year` varchar(20) NOT NULL,
  `max_students` int(10) unsigned NOT NULL DEFAULT 0,
  `current_students` int(10) unsigned NOT NULL DEFAULT 0,
  `advisor_name` varchar(120) DEFAULT NULL,
  `advisor_teacher_id` int(10) unsigned DEFAULT NULL,
  `status` enum('Active','Inactive','Archived') NOT NULL DEFAULT 'Active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sections_code_term` (`code`,`academic_year`,`semester`),
  KEY `idx_sections_program_year` (`program`,`year_level`),
  KEY `idx_sections_ay_sem` (`academic_year`,`semester`),
  KEY `idx_sections_status` (`status`),
  KEY `fk_sections_advisor` (`advisor_teacher_id`),
  CONSTRAINT `fk_sections_advisor` FOREIGN KEY (`advisor_teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `section_subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `section_subjects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `section_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `status` enum('Assigned','Dropped','Completed') NOT NULL DEFAULT 'Assigned',
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_section_subject` (`section_id`,`subject_id`),
  KEY `fk_section_subjects_subject` (`subject_id`),
  KEY `idx_section_subjects_status` (`status`),
  CONSTRAINT `fk_section_subjects_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_section_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `special_classes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `special_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(30) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `special_type` enum('Remedial','Irregular','Midyear','Overload','Makeup','Cross-enrollment','Tutorial','Review','Seminar','Other') NOT NULL DEFAULT 'Makeup',
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` varchar(30) DEFAULT NULL,
  `program` varchar(30) DEFAULT NULL,
  `assignment_mode` enum('Section','Students') NOT NULL DEFAULT 'Section',
  `section_id` int(10) unsigned DEFAULT NULL,
  `subject_id` int(10) unsigned DEFAULT NULL,
  `teacher_id` int(10) unsigned DEFAULT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  `time_block_id` int(10) unsigned DEFAULT NULL,
  `class_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('Draft','For Scheduling','Scheduled','Validated','Ready to Publish','Published','Cancelled') NOT NULL DEFAULT 'Draft',
  `remarks` varchar(255) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_special_reference` (`reference_no`),
  KEY `idx_special_date` (`class_date`,`start_time`,`end_time`),
  KEY `idx_special_teacher_time` (`teacher_id`,`class_date`,`start_time`,`end_time`),
  KEY `idx_special_room_time` (`room_id`,`class_date`,`start_time`,`end_time`),
  KEY `fk_special_section` (`section_id`),
  KEY `fk_special_subject` (`subject_id`),
  KEY `idx_special_term_status` (`academic_year`,`semester`,`status`),
  CONSTRAINT `fk_special_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_special_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_special_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_special_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subjects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `units` decimal(4,1) NOT NULL DEFAULT 3.0,
  `subject_type` enum('Major','Minor','GE','PE','Elective') NOT NULL DEFAULT 'Major',
  `lecture_hours` decimal(4,1) NOT NULL DEFAULT 3.0,
  `lab_hours` decimal(4,1) NOT NULL DEFAULT 0.0,
  `program` varchar(30) NOT NULL DEFAULT 'ALL',
  `year_level` tinyint(3) unsigned DEFAULT NULL,
  `semester` enum('1st Semester','2nd Semester','Summer') DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subjects_code` (`code`),
  KEY `idx_subjects_program_year_sem` (`program`,`year_level`,`semester`),
  KEY `idx_subjects_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `substitute_assignments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `substitute_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `schedule_entry_id` bigint(20) unsigned NOT NULL,
  `original_teacher_id` int(10) unsigned NOT NULL,
  `substitute_teacher_id` int(10) unsigned DEFAULT NULL,
  `absence_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Assigned','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_substitute_entry_date` (`schedule_entry_id`,`absence_date`),
  KEY `idx_substitute_status` (`status`,`absence_date`),
  KEY `fk_substitute_original` (`original_teacher_id`),
  KEY `fk_substitute_replacement` (`substitute_teacher_id`),
  CONSTRAINT `fk_substitute_entry` FOREIGN KEY (`schedule_entry_id`) REFERENCES `schedule_entries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_substitute_original` FOREIGN KEY (`original_teacher_id`) REFERENCES `teachers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_substitute_replacement` FOREIGN KEY (`substitute_teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teachers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teachers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_no` varchar(30) DEFAULT NULL,
  `full_name` varchar(120) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `max_load_units` decimal(4,1) NOT NULL DEFAULT 24.0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teachers_employee_no` (`employee_no`),
  KEY `idx_teachers_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teacher_availability`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_availability` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `availability` enum('Available','Unavailable','Preferred') NOT NULL DEFAULT 'Available',
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` enum('1st Semester','2nd Semester','Summer') DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_teacher_avail_lookup` (`teacher_id`,`day_of_week`,`start_time`,`end_time`),
  KEY `idx_teacher_avail_term` (`academic_year`,`semester`),
  CONSTRAINT `fk_teacher_avail_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `time_blocks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `time_blocks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `label` varchar(60) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `block_type` enum('Class','Break','Exam') NOT NULL DEFAULT 'Class',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_time_blocks_code` (`code`),
  KEY `idx_time_blocks_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exam_timetable_batches`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_timetable_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(40) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `exam_type` enum('Prelim','Midterm','Semi-Final','Final','Special') NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('1st Semester','2nd Semester','Summer') NOT NULL,
  `date_from` date DEFAULT NULL,
  `date_to` date DEFAULT NULL,
  `status` enum('Draft','Generated','For Validation','Validated','Ready to Publish','Published','Cancelled') NOT NULL DEFAULT 'Draft',
  `options_json` longtext DEFAULT NULL,
  `solver_engine` varchar(80) DEFAULT NULL,
  `validation_run_id` varchar(60) DEFAULT NULL,
  `validation_summary_json` longtext DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_batch_reference` (`reference_no`),
  KEY `idx_exam_batch_term` (`academic_year`,`semester`,`exam_type`,`status`),
  KEY `fk_exam_batch_user` (`created_by`),
  CONSTRAINT `fk_exam_batch_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exam_timetable_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_timetable_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL,
  `exam_schedule_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) DEFAULT NULL,
  `detail` varchar(500) NOT NULL,
  `snapshot_json` longtext DEFAULT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exam_history_batch` (`batch_id`,`created_at`),
  KEY `idx_exam_history_schedule` (`exam_schedule_id`),
  KEY `fk_exam_history_user` (`changed_by`),
  CONSTRAINT `fk_exam_history_batch` FOREIGN KEY (`batch_id`) REFERENCES `exam_timetable_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_history_schedule` FOREIGN KEY (`exam_schedule_id`) REFERENCES `exam_schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exam_timetable_shares`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_timetable_shares` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL,
  `audience` enum('Faculty','Students/Sections','Program Heads','Scheduling Staff') NOT NULL,
  `delivery_status` enum('Queued','Sent','Skipped') NOT NULL DEFAULT 'Queued',
  `message` varchar(500) DEFAULT NULL,
  `sent_by` int(10) unsigned DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exam_share_batch` (`batch_id`,`created_at`),
  KEY `fk_exam_share_user` (`sent_by`),
  CONSTRAINT `fk_exam_share_batch` FOREIGN KEY (`batch_id`) REFERENCES `exam_timetable_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_exam_share_user` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `special_class_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `special_class_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `special_class_id` bigint(20) unsigned NOT NULL,
  `action` varchar(40) NOT NULL,
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) DEFAULT NULL,
  `detail` varchar(500) NOT NULL,
  `actor_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_special_history_class` (`special_class_id`,`created_at`),
  KEY `fk_special_history_actor` (`actor_user_id`),
  CONSTRAINT `fk_special_history_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_special_history_class` FOREIGN KEY (`special_class_id`) REFERENCES `special_classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `special_class_students`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `special_class_students` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `special_class_id` bigint(20) unsigned NOT NULL,
  `student_key` varchar(60) NOT NULL,
  `student_user_id` int(10) unsigned DEFAULT NULL,
  `pre_registration_id` int(10) unsigned DEFAULT NULL,
  `student_ref` varchar(40) DEFAULT NULL,
  `student_name` varchar(150) NOT NULL,
  `program` varchar(40) DEFAULT NULL,
  `year_level` tinyint(3) unsigned DEFAULT NULL,
  `eligibility_status` enum('Eligible','Conditional','Not Eligible','Not Configured') NOT NULL DEFAULT 'Not Configured',
  `eligibility_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_special_student` (`special_class_id`,`student_key`),
  KEY `idx_special_student_user` (`student_user_id`),
  KEY `idx_special_student_prereg` (`pre_registration_id`),
  CONSTRAINT `fk_special_student_class` FOREIGN KEY (`special_class_id`) REFERENCES `special_classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_special_student_user` FOREIGN KEY (`student_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teacher_subject_qualifications`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_subject_qualifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `specialization_label` varchar(120) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `verified_by` int(10) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teacher_subject_qualification` (`teacher_id`,`subject_id`),
  KEY `idx_teacher_qualification_status` (`status`),
  KEY `fk_teacher_qualification_subject` (`subject_id`),
  KEY `fk_teacher_qualification_verifier` (`verified_by`),
  CONSTRAINT `fk_teacher_qualification_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_teacher_qualification_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_teacher_qualification_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `validation_runs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `validation_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(40) NOT NULL,
  `previous_run_id` varchar(40) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` varchar(30) DEFAULT NULL,
  `schedule_type` enum('All','Regular','Special','Exam') NOT NULL DEFAULT 'All',
  `selected_count` int(10) unsigned NOT NULL DEFAULT 0,
  `findings_count` int(10) unsigned NOT NULL DEFAULT 0,
  `critical_count` int(10) unsigned NOT NULL DEFAULT 0,
  `warning_count` int(10) unsigned NOT NULL DEFAULT 0,
  `resolved_count` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('Running','Has Conflicts','Valid','Completed','Failed') NOT NULL DEFAULT 'Running',
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_validation_run_id` (`run_id`),
  KEY `idx_validation_runs_date` (`created_at`,`status`),
  KEY `fk_validation_run_user` (`created_by`),
  CONSTRAINT `fk_validation_run_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `validation_run_records`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `validation_run_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(40) NOT NULL,
  `record_key` varchar(60) NOT NULL,
  `schedule_type` enum('Regular','Special','Exam') NOT NULL,
  `reference_id` bigint(20) unsigned NOT NULL,
  `before_status` varchar(30) DEFAULT NULL,
  `result_status` enum('Valid','Conflict','Warning') NOT NULL DEFAULT 'Valid',
  `after_status` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_validation_run_record` (`run_id`,`record_key`),
  KEY `idx_validation_record_ref` (`schedule_type`,`reference_id`),
  CONSTRAINT `fk_validation_record_run` FOREIGN KEY (`run_id`) REFERENCES `validation_runs` (`run_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-26 14:10:21
