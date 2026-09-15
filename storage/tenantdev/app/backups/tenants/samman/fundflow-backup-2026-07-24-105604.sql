/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: 127.0.0.1    Database: tenantsamman-
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

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
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `loan_id` bigint(20) unsigned DEFAULT NULL,
  `type` enum('cash','fund','bank','expense','fees','invest','loan','suspense') NOT NULL,
  `name` varchar(255) NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_master` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `accounts_member_id_type_index` (`member_id`,`type`),
  KEY `accounts_is_master_index` (`is_master`),
  KEY `accounts_loan_id_foreign` (`loan_id`),
  CONSTRAINT `accounts_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `accounts_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES
(1,NULL,NULL,'cash','Master Cash',0.00,1,'2026-07-24 07:51:51','2026-07-24 07:51:51'),
(2,NULL,NULL,'fund','Master Fund',0.00,1,'2026-07-24 07:51:51','2026-07-24 07:51:51'),
(3,NULL,NULL,'bank','Master Bank',0.00,1,'2026-07-24 07:51:51','2026-07-24 07:51:51'),
(4,NULL,NULL,'expense','Master Expense',0.00,1,'2026-07-24 07:51:51','2026-07-24 07:51:51'),
(5,NULL,NULL,'fees','Master Fees',0.00,1,'2026-07-24 07:51:51','2026-07-24 07:51:51'),
(6,NULL,NULL,'invest','Master Invest',0.00,1,'2026-07-24 07:51:51','2026-07-24 07:51:51'),
(7,NULL,NULL,'suspense','Master Suspense',0.00,1,'2026-07-24 07:51:51','2026-07-24 07:51:51');
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_statements`
--

DROP TABLE IF EXISTS `bank_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_statements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `statement_date` date DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `bank_template_id` bigint(20) unsigned DEFAULT NULL,
  `total_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `imported_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `duplicate_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `imported_by` bigint(20) unsigned DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bank_statements_imported_by_foreign` (`imported_by`),
  KEY `bank_statements_status_index` (`status`),
  KEY `bank_statements_statement_date_index` (`statement_date`),
  KEY `bank_statements_bank_template_id_foreign` (`bank_template_id`),
  CONSTRAINT `bank_statements_bank_template_id_foreign` FOREIGN KEY (`bank_template_id`) REFERENCES `bank_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_statements_imported_by_foreign` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_statements`
--

LOCK TABLES `bank_statements` WRITE;
/*!40000 ALTER TABLE `bank_statements` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_statements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_templates`
--

DROP TABLE IF EXISTS `bank_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `encoding` varchar(255) NOT NULL DEFAULT 'UTF-8',
  `delimiter` varchar(255) NOT NULL DEFAULT ',',
  `has_header` tinyint(1) NOT NULL DEFAULT 1,
  `skip_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `date_format` varchar(255) NOT NULL DEFAULT 'Y-m-d',
  `date_column` varchar(255) NOT NULL DEFAULT '0',
  `amount_column` varchar(255) DEFAULT '2',
  `amount_mode` varchar(255) NOT NULL DEFAULT 'single',
  `credit_column` varchar(255) DEFAULT NULL,
  `debit_column` varchar(255) DEFAULT NULL,
  `extra_columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_columns`)),
  `duplicate_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`duplicate_fields`)),
  `duplicate_date_tolerance` int(10) unsigned NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_templates`
--

LOCK TABLES `bank_templates` WRITE;
/*!40000 ALTER TABLE `bank_templates` DISABLE KEYS */;
INSERT INTO `bank_templates` VALUES
(1,'Generic CSV','UTF-8',',',1,0,'[\"Y-m-d\"]','0','2','single',NULL,NULL,'[{\"key\":\"description\",\"column\":\"1\"},{\"key\":\"reference\",\"column\":\"3\"}]','[\"date\",\"amount\",\"description\",\"reference\"]',0,0,'2026-07-24 07:52:03','2026-07-24 07:52:03'),
(2,'Al Rajhi Bank','UTF-8',',',1,15,'[\"d-m-Y\",\"d\\/m\\/Y\"]','التاريخ الميلادي',NULL,'split','دائن','مدين','[{\"key\":\"\\u0627\\u0644\\u0628\\u064a\\u0627\\u0646\",\"column\":\"\\u0627\\u0644\\u0628\\u064a\\u0627\\u0646\"},{\"key\":\"\\u0645\\u0644\\u0627\\u062d\\u0638\\u0627\\u062a\",\"column\":\"\\u0645\\u0644\\u0627\\u062d\\u0638\\u0627\\u062a\"},{\"key\":\"\\u062a\\u0635\\u0646\\u064a\\u0641 \\u0627\\u0644\\u0639\\u0645\\u0644\\u064a\\u0629\",\"column\":\"\\u062a\\u0635\\u0646\\u064a\\u0641 \\u0627\\u0644\\u0639\\u0645\\u0644\\u064a\\u0629\"},{\"key\":\"\\u0627\\u0644\\u062a\\u0627\\u0631\\u064a\\u062e \\u0627\\u0644\\u0647\\u062c\\u0631\\u064a\",\"column\":\"\\u0627\\u0644\\u062a\\u0627\\u0631\\u064a\\u062e \\u0627\\u0644\\u0647\\u062c\\u0631\\u064a\"},{\"key\":\"\\u0627\\u0644\\u0631\\u0635\\u064a\\u062f\",\"column\":\"\\u0627\\u0644\\u0631\\u0635\\u064a\\u062f\"}]','[\"date\",\"amount\",\"\\u0627\\u0644\\u0628\\u064a\\u0627\\u0646\",\"\\u0645\\u0644\\u0627\\u062d\\u0638\\u0627\\u062a\",\"\\u062a\\u0635\\u0646\\u064a\\u0641 \\u0627\\u0644\\u0639\\u0645\\u0644\\u064a\\u0629\",\"\\u0627\\u0644\\u062a\\u0627\\u0631\\u064a\\u062e \\u0627\\u0644\\u0647\\u062c\\u0631\\u064a\",\"\\u0627\\u0644\\u0631\\u0635\\u064a\\u062f\"]',0,1,'2026-07-24 07:52:03','2026-07-24 07:52:03');
/*!40000 ALTER TABLE `bank_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_transactions`
--

DROP TABLE IF EXISTS `bank_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bank_statement_id` bigint(20) unsigned NOT NULL,
  `transaction_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `transaction_type` varchar(255) DEFAULT NULL,
  `status` enum('imported','mirrored','posted','ignored','duplicate') DEFAULT 'imported',
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `hash` varchar(255) NOT NULL,
  `duplicate_of_id` bigint(20) unsigned DEFAULT NULL,
  `raw_data` text DEFAULT NULL,
  `is_cleared` tinyint(1) NOT NULL DEFAULT 0,
  `cleared_at` timestamp NULL DEFAULT NULL,
  `fund_posting_id` bigint(20) unsigned DEFAULT NULL,
  `membership_application_id` bigint(20) unsigned DEFAULT NULL,
  `cash_out_request_id` bigint(20) unsigned DEFAULT NULL,
  `expense_disbursement_id` bigint(20) unsigned DEFAULT NULL,
  `fee_disbursement_id` bigint(20) unsigned DEFAULT NULL,
  `invest_disbursement_id` bigint(20) unsigned DEFAULT NULL,
  `invest_return_id` bigint(20) unsigned DEFAULT NULL,
  `master_cash_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `master_bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `master_fund_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_transactions_hash_unique` (`hash`),
  KEY `bank_transactions_member_id_foreign` (`member_id`),
  KEY `bank_transactions_status_index` (`status`),
  KEY `bank_transactions_transaction_date_index` (`transaction_date`),
  KEY `bank_transactions_bank_statement_id_status_index` (`bank_statement_id`,`status`),
  KEY `bank_transactions_fund_posting_id_foreign` (`fund_posting_id`),
  KEY `bank_transactions_is_cleared_index` (`is_cleared`),
  KEY `bank_transactions_duplicate_of_id_foreign` (`duplicate_of_id`),
  KEY `bank_transactions_master_cash_transaction_id_foreign` (`master_cash_transaction_id`),
  KEY `bank_transactions_membership_application_id_foreign` (`membership_application_id`),
  KEY `bank_transactions_cash_out_request_id_foreign` (`cash_out_request_id`),
  KEY `bank_transactions_master_bank_transaction_id_foreign` (`master_bank_transaction_id`),
  KEY `bank_transactions_master_fund_transaction_id_foreign` (`master_fund_transaction_id`),
  KEY `bank_transactions_expense_disbursement_id_foreign` (`expense_disbursement_id`),
  KEY `bank_transactions_fee_disbursement_id_foreign` (`fee_disbursement_id`),
  KEY `bank_transactions_invest_disbursement_id_foreign` (`invest_disbursement_id`),
  KEY `bank_transactions_invest_return_id_foreign` (`invest_return_id`),
  CONSTRAINT `bank_transactions_bank_statement_id_foreign` FOREIGN KEY (`bank_statement_id`) REFERENCES `bank_statements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bank_transactions_cash_out_request_id_foreign` FOREIGN KEY (`cash_out_request_id`) REFERENCES `cash_out_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_duplicate_of_id_foreign` FOREIGN KEY (`duplicate_of_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_expense_disbursement_id_foreign` FOREIGN KEY (`expense_disbursement_id`) REFERENCES `expense_disbursements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_fee_disbursement_id_foreign` FOREIGN KEY (`fee_disbursement_id`) REFERENCES `fee_disbursements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_fund_posting_id_foreign` FOREIGN KEY (`fund_posting_id`) REFERENCES `fund_postings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_invest_disbursement_id_foreign` FOREIGN KEY (`invest_disbursement_id`) REFERENCES `invest_disbursements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_invest_return_id_foreign` FOREIGN KEY (`invest_return_id`) REFERENCES `invest_returns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_master_bank_transaction_id_foreign` FOREIGN KEY (`master_bank_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_master_cash_transaction_id_foreign` FOREIGN KEY (`master_cash_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_master_fund_transaction_id_foreign` FOREIGN KEY (`master_fund_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transactions_membership_application_id_foreign` FOREIGN KEY (`membership_application_id`) REFERENCES `membership_applications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_transactions`
--

LOCK TABLES `bank_transactions` WRITE;
/*!40000 ALTER TABLE `bank_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_out_requests`
--

DROP TABLE IF EXISTS `cash_out_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_out_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cash_out_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `cash_out_requests_status_index` (`status`),
  KEY `cash_out_requests_member_id_status_index` (`member_id`,`status`),
  KEY `cash_out_requests_bank_transaction_id_foreign` (`bank_transaction_id`),
  CONSTRAINT `cash_out_requests_bank_transaction_id_foreign` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cash_out_requests_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cash_out_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_out_requests`
--

LOCK TABLES `cash_out_requests` WRITE;
/*!40000 ALTER TABLE `cash_out_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_out_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contributions`
--

DROP TABLE IF EXISTS `contributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contributions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `period` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `amount_due` decimal(12,2) DEFAULT NULL,
  `amount_collected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) NOT NULL DEFAULT 'cash_account',
  `reference_number` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_late` tinyint(1) NOT NULL DEFAULT 0,
  `late_fee_amount` decimal(15,2) DEFAULT NULL,
  `late_fee_tier` tinyint(3) unsigned DEFAULT NULL,
  `cycle_open_cash_balance` decimal(12,2) DEFAULT NULL,
  `status` enum('pending','posted','failed','waived') NOT NULL DEFAULT 'pending',
  `collection_status` varchar(32) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `overdue_since` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contributions_member_id_period_unique` (`member_id`,`period`),
  KEY `contributions_status_index` (`status`),
  KEY `contributions_period_index` (`period`),
  CONSTRAINT `contributions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contributions`
--

LOCK TABLES `contributions` WRITE;
/*!40000 ALTER TABLE `contributions` DISABLE KEYS */;
/*!40000 ALTER TABLE `contributions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `database_backups`
--

DROP TABLE IF EXISTS `database_backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `database_backups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(255) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `size_bytes` bigint(20) unsigned NOT NULL,
  `driver` varchar(32) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `database_backups_user_id_foreign` (`user_id`),
  KEY `database_backups_created_at_index` (`created_at`),
  CONSTRAINT `database_backups_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `database_backups`
--

LOCK TABLES `database_backups` WRITE;
/*!40000 ALTER TABLE `database_backups` DISABLE KEYS */;
/*!40000 ALTER TABLE `database_backups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dependent_allocation_changes`
--

DROP TABLE IF EXISTS `dependent_allocation_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dependent_allocation_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_member_id` bigint(20) unsigned NOT NULL,
  `dependent_member_id` bigint(20) unsigned NOT NULL,
  `old_amount` int(10) unsigned NOT NULL,
  `new_amount` int(10) unsigned NOT NULL,
  `changed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dependent_allocation_changes_changed_by_user_id_foreign` (`changed_by_user_id`),
  KEY `dependent_allocation_changes_parent_member_id_index` (`parent_member_id`),
  KEY `dependent_allocation_changes_dependent_member_id_index` (`dependent_member_id`),
  CONSTRAINT `dependent_allocation_changes_changed_by_user_id_foreign` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dependent_allocation_changes_dependent_member_id_foreign` FOREIGN KEY (`dependent_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dependent_allocation_changes_parent_member_id_foreign` FOREIGN KEY (`parent_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dependent_allocation_changes`
--

LOCK TABLES `dependent_allocation_changes` WRITE;
/*!40000 ALTER TABLE `dependent_allocation_changes` DISABLE KEYS */;
/*!40000 ALTER TABLE `dependent_allocation_changes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dependent_cash_allocations`
--

DROP TABLE IF EXISTS `dependent_cash_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dependent_cash_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_member_id` bigint(20) unsigned NOT NULL,
  `dependent_member_id` bigint(20) unsigned NOT NULL,
  `allocation_month` tinyint(3) unsigned NOT NULL,
  `allocation_year` smallint(5) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dependent_allocations_period_unique` (`dependent_member_id`,`allocation_month`,`allocation_year`),
  KEY `dependent_cash_allocations_parent_member_id_foreign` (`parent_member_id`),
  CONSTRAINT `dependent_cash_allocations_dependent_member_id_foreign` FOREIGN KEY (`dependent_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dependent_cash_allocations_parent_member_id_foreign` FOREIGN KEY (`parent_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dependent_cash_allocations`
--

LOCK TABLES `dependent_cash_allocations` WRITE;
/*!40000 ALTER TABLE `dependent_cash_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `dependent_cash_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `direct_messages`
--

DROP TABLE IF EXISTS `direct_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `direct_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_user_id` bigint(20) unsigned NOT NULL,
  `to_user_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `direct_messages_to_user_id_read_at_index` (`to_user_id`,`read_at`),
  KEY `direct_messages_from_user_id_index` (`from_user_id`),
  KEY `direct_messages_parent_id_index` (`parent_id`),
  CONSTRAINT `direct_messages_from_user_id_foreign` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `direct_messages_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `direct_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `direct_messages_to_user_id_foreign` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `direct_messages`
--

LOCK TABLES `direct_messages` WRITE;
/*!40000 ALTER TABLE `direct_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `direct_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_disbursements`
--

DROP TABLE IF EXISTS `expense_disbursements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_disbursements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `transacted_at` timestamp NOT NULL,
  `bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_disbursements_bank_transaction_id_foreign` (`bank_transaction_id`),
  CONSTRAINT `expense_disbursements_bank_transaction_id_foreign` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_disbursements`
--

LOCK TABLES `expense_disbursements` WRITE;
/*!40000 ALTER TABLE `expense_disbursements` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_disbursements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_deductions`
--

DROP TABLE IF EXISTS `fee_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_deductions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `transacted_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fee_deductions_member_id_foreign` (`member_id`),
  CONSTRAINT `fee_deductions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_deductions`
--

LOCK TABLES `fee_deductions` WRITE;
/*!40000 ALTER TABLE `fee_deductions` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_deductions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_disbursements`
--

DROP TABLE IF EXISTS `fee_disbursements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_disbursements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `transacted_at` timestamp NOT NULL,
  `bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fee_disbursements_bank_transaction_id_foreign` (`bank_transaction_id`),
  CONSTRAINT `fee_disbursements_bank_transaction_id_foreign` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_disbursements`
--

LOCK TABLES `fee_disbursements` WRITE;
/*!40000 ALTER TABLE `fee_disbursements` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_disbursements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fiscal_close_member_snapshots`
--

DROP TABLE IF EXISTS `fiscal_close_member_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fiscal_close_member_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_close_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `cash_balance` decimal(15,2) NOT NULL,
  `fund_balance` decimal(15,2) NOT NULL,
  `opening_cash_before` decimal(15,2) DEFAULT NULL,
  `opening_fund_before` decimal(15,2) DEFAULT NULL,
  `contribution_arrears_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`contribution_arrears_json`)),
  `loans_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`loans_json`)),
  `delinquency_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`delinquency_json`)),
  `eligibility_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`eligibility_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fiscal_close_member_snapshots_fiscal_close_id_member_id_unique` (`fiscal_close_id`,`member_id`),
  KEY `fiscal_close_member_snapshots_member_id_foreign` (`member_id`),
  CONSTRAINT `fiscal_close_member_snapshots_fiscal_close_id_foreign` FOREIGN KEY (`fiscal_close_id`) REFERENCES `fiscal_closes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fiscal_close_member_snapshots_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fiscal_close_member_snapshots`
--

LOCK TABLES `fiscal_close_member_snapshots` WRITE;
/*!40000 ALTER TABLE `fiscal_close_member_snapshots` DISABLE KEYS */;
/*!40000 ALTER TABLE `fiscal_close_member_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fiscal_close_waivers`
--

DROP TABLE IF EXISTS `fiscal_close_waivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fiscal_close_waivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_close_id` bigint(20) unsigned NOT NULL,
  `gate_code` varchar(64) NOT NULL,
  `reason` text NOT NULL,
  `waived_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fiscal_close_waivers_fiscal_close_id_foreign` (`fiscal_close_id`),
  KEY `fiscal_close_waivers_waived_by_foreign` (`waived_by`),
  CONSTRAINT `fiscal_close_waivers_fiscal_close_id_foreign` FOREIGN KEY (`fiscal_close_id`) REFERENCES `fiscal_closes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fiscal_close_waivers_waived_by_foreign` FOREIGN KEY (`waived_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fiscal_close_waivers`
--

LOCK TABLES `fiscal_close_waivers` WRITE;
/*!40000 ALTER TABLE `fiscal_close_waivers` DISABLE KEYS */;
/*!40000 ALTER TABLE `fiscal_close_waivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fiscal_closes`
--

DROP TABLE IF EXISTS `fiscal_closes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fiscal_closes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_year_label` varchar(16) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'draft',
  `readiness_report_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`readiness_report_json`)),
  `pool_snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pool_snapshot_json`)),
  `member_count` int(10) unsigned NOT NULL DEFAULT 0,
  `active_loan_count` int(10) unsigned NOT NULL DEFAULT 0,
  `open_arrears_period_count` int(10) unsigned NOT NULL DEFAULT 0,
  `export_manifest_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`export_manifest_json`)),
  `checksum` varchar(64) DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `purge_started_at` timestamp NULL DEFAULT NULL,
  `purge_completed_at` timestamp NULL DEFAULT NULL,
  `purge_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`purge_summary_json`)),
  `failure_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fiscal_closes_closed_by_foreign` (`closed_by`),
  KEY `fiscal_closes_approved_by_foreign` (`approved_by`),
  KEY `fiscal_closes_status_index` (`status`),
  KEY `fiscal_closes_period_end_index` (`period_end`),
  KEY `fiscal_closes_fiscal_year_label_index` (`fiscal_year_label`),
  CONSTRAINT `fiscal_closes_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fiscal_closes_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fiscal_closes`
--

LOCK TABLES `fiscal_closes` WRITE;
/*!40000 ALTER TABLE `fiscal_closes` DISABLE KEYS */;
/*!40000 ALTER TABLE `fiscal_closes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fund_audit_logs`
--

DROP TABLE IF EXISTS `fund_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fund_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) NOT NULL,
  `domain` varchar(32) DEFAULT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `operator_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `checksum` varchar(64) DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fund_audit_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `fund_audit_logs_operator_id_foreign` (`operator_id`),
  KEY `fund_audit_logs_event_type_occurred_at_index` (`event_type`,`occurred_at`),
  KEY `fund_audit_logs_member_id_index` (`member_id`),
  CONSTRAINT `fund_audit_logs_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fund_audit_logs_operator_id_foreign` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fund_audit_logs`
--

LOCK TABLES `fund_audit_logs` WRITE;
/*!40000 ALTER TABLE `fund_audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `fund_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fund_postings`
--

DROP TABLE IF EXISTS `fund_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fund_postings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `posting_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fund_postings_reviewed_by_foreign` (`reviewed_by`),
  KEY `fund_postings_bank_transaction_id_foreign` (`bank_transaction_id`),
  KEY `fund_postings_status_index` (`status`),
  KEY `fund_postings_member_id_status_index` (`member_id`,`status`),
  CONSTRAINT `fund_postings_bank_transaction_id_foreign` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fund_postings_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fund_postings_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fund_postings`
--

LOCK TABLES `fund_postings` WRITE;
/*!40000 ALTER TABLE `fund_postings` DISABLE KEYS */;
/*!40000 ALTER TABLE `fund_postings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fund_tiers`
--

DROP TABLE IF EXISTS `fund_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fund_tiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tier_number` tinyint(3) unsigned NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `percentage` decimal(5,2) NOT NULL DEFAULT 100.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fund_tiers_tier_number_unique` (`tier_number`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fund_tiers`
--

LOCK TABLES `fund_tiers` WRITE;
/*!40000 ALTER TABLE `fund_tiers` DISABLE KEYS */;
INSERT INTO `fund_tiers` VALUES
(1,0,'Emergency',100.00,1,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(2,1,'Tier 1',40.00,1,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(3,2,'Tier 2',30.00,1,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(4,3,'Tier 3',10.00,1,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(5,4,'Tier 4',10.00,1,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(6,5,'Tier 5',10.00,1,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL);
/*!40000 ALTER TABLE `fund_tiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `impersonation_audits`
--

DROP TABLE IF EXISTS `impersonation_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `impersonation_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `impersonator_user_id` bigint(20) unsigned NOT NULL,
  `impersonated_user_id` bigint(20) unsigned NOT NULL,
  `impersonated_member_id` bigint(20) unsigned DEFAULT NULL,
  `event` enum('started','stopped') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `impersonation_audits_impersonator_user_id_foreign` (`impersonator_user_id`),
  KEY `impersonation_audits_impersonated_user_id_foreign` (`impersonated_user_id`),
  KEY `impersonation_audits_impersonated_member_id_foreign` (`impersonated_member_id`),
  CONSTRAINT `impersonation_audits_impersonated_member_id_foreign` FOREIGN KEY (`impersonated_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `impersonation_audits_impersonated_user_id_foreign` FOREIGN KEY (`impersonated_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `impersonation_audits_impersonator_user_id_foreign` FOREIGN KEY (`impersonator_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `impersonation_audits`
--

LOCK TABLES `impersonation_audits` WRITE;
/*!40000 ALTER TABLE `impersonation_audits` DISABLE KEYS */;
/*!40000 ALTER TABLE `impersonation_audits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invest_disbursements`
--

DROP TABLE IF EXISTS `invest_disbursements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invest_disbursements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `transacted_at` timestamp NOT NULL,
  `bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invest_disbursements_bank_transaction_id_foreign` (`bank_transaction_id`),
  CONSTRAINT `invest_disbursements_bank_transaction_id_foreign` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invest_disbursements`
--

LOCK TABLES `invest_disbursements` WRITE;
/*!40000 ALTER TABLE `invest_disbursements` DISABLE KEYS */;
/*!40000 ALTER TABLE `invest_disbursements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invest_returns`
--

DROP TABLE IF EXISTS `invest_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invest_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `transacted_at` timestamp NOT NULL,
  `bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invest_returns_bank_transaction_id_foreign` (`bank_transaction_id`),
  CONSTRAINT `invest_returns_bank_transaction_id_foreign` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invest_returns`
--

LOCK TABLES `invest_returns` WRITE;
/*!40000 ALTER TABLE `invest_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `invest_returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_disbursements`
--

DROP TABLE IF EXISTS `loan_disbursements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_disbursements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `member_portion` decimal(15,2) NOT NULL DEFAULT 0.00,
  `master_portion` decimal(15,2) NOT NULL DEFAULT 0.00,
  `disbursed_at` timestamp NOT NULL,
  `disbursed_by_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_disbursements_loan_id_foreign` (`loan_id`),
  KEY `loan_disbursements_disbursed_by_id_foreign` (`disbursed_by_id`),
  CONSTRAINT `loan_disbursements_disbursed_by_id_foreign` FOREIGN KEY (`disbursed_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_disbursements_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_disbursements`
--

LOCK TABLES `loan_disbursements` WRITE;
/*!40000 ALTER TABLE `loan_disbursements` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_disbursements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_eligibility_override_requests`
--

DROP TABLE IF EXISTS `loan_eligibility_override_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_eligibility_override_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `failed_gates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`failed_gates`)),
  `member_message` text NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_eligibility_override_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `loan_eligibility_override_requests_status_index` (`status`),
  KEY `loan_eligibility_override_requests_member_id_status_index` (`member_id`,`status`),
  CONSTRAINT `loan_eligibility_override_requests_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_eligibility_override_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_eligibility_override_requests`
--

LOCK TABLES `loan_eligibility_override_requests` WRITE;
/*!40000 ALTER TABLE `loan_eligibility_override_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_eligibility_override_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_eligibility_overrides`
--

DROP TABLE IF EXISTS `loan_eligibility_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_eligibility_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint(20) unsigned DEFAULT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `gate` varchar(64) NOT NULL,
  `reason` text NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_eligibility_overrides_loan_id_foreign` (`loan_id`),
  KEY `loan_eligibility_overrides_approved_by_foreign` (`approved_by`),
  KEY `loan_eligibility_overrides_member_id_loan_id_index` (`member_id`,`loan_id`),
  CONSTRAINT `loan_eligibility_overrides_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loan_eligibility_overrides_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_eligibility_overrides_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_eligibility_overrides`
--

LOCK TABLES `loan_eligibility_overrides` WRITE;
/*!40000 ALTER TABLE `loan_eligibility_overrides` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_eligibility_overrides` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_installments`
--

DROP TABLE IF EXISTS `loan_installments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_installments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint(20) unsigned NOT NULL,
  `installment_number` int(10) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `amount_collected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_date` date NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `waived_at` timestamp NULL DEFAULT NULL,
  `overdue_since` timestamp NULL DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `collection_status` varchar(32) DEFAULT NULL,
  `is_late` tinyint(1) NOT NULL DEFAULT 0,
  `late_fee_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `late_fee_tier` tinyint(3) unsigned DEFAULT NULL,
  `paid_by_guarantor` tinyint(1) NOT NULL DEFAULT 0,
  `show_as_loan_repayment_in_collections` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_installments_loan_id_installment_number_unique` (`loan_id`,`installment_number`),
  CONSTRAINT `loan_installments_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_installments`
--

LOCK TABLES `loan_installments` WRITE;
/*!40000 ALTER TABLE `loan_installments` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_installments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_repayments`
--

DROP TABLE IF EXISTS `loan_repayments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_repayments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `paid_at` timestamp NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_repayments_loan_id_foreign` (`loan_id`),
  CONSTRAINT `loan_repayments_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_repayments`
--

LOCK TABLES `loan_repayments` WRITE;
/*!40000 ALTER TABLE `loan_repayments` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_repayments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_tiers`
--

DROP TABLE IF EXISTS `loan_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_tiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tier_number` tinyint(3) unsigned NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `min_amount` decimal(12,2) NOT NULL,
  `max_amount` decimal(12,2) NOT NULL,
  `min_monthly_installment` decimal(12,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `fund_tier_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_tiers_tier_number_unique` (`tier_number`),
  KEY `loan_tiers_fund_tier_id_foreign` (`fund_tier_id`),
  CONSTRAINT `loan_tiers_fund_tier_id_foreign` FOREIGN KEY (`fund_tier_id`) REFERENCES `fund_tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_tiers`
--

LOCK TABLES `loan_tiers` WRITE;
/*!40000 ALTER TABLE `loan_tiers` DISABLE KEYS */;
INSERT INTO `loan_tiers` VALUES
(1,0,'1K->5K - 500',0.00,5000.00,500.00,1,2,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(2,1,'6K->30K - 1K',6000.00,30000.00,1000.00,1,2,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(3,2,'31K->60K - 1.5K',31000.00,60000.00,1500.00,1,3,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(4,3,'61K->90K - 2K',61000.00,90000.00,2000.00,1,3,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(5,4,'91K->120K - 2.5K',91000.00,120000.00,2500.00,1,4,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(6,5,'121K->150K - 3K',121000.00,150000.00,3000.00,1,4,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(7,6,'151K->180K - 3.5K',151000.00,180000.00,3500.00,1,5,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(8,7,'181K->210K - 4K',181000.00,210000.00,4000.00,1,5,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(9,8,'211K->240K - 4.5K',211000.00,240000.00,4500.00,1,6,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(10,9,'241K->270K - 5K',241000.00,270000.00,5000.00,1,6,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL),
(11,10,'271K->300K - 5.5K',271000.00,300000.00,5500.00,1,6,'2026-07-24 07:51:48','2026-07-24 07:51:48',NULL);
/*!40000 ALTER TABLE `loan_tiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loans`
--

DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `original_borrower_member_id` bigint(20) unsigned DEFAULT NULL,
  `amount_requested` decimal(15,2) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `purpose` text DEFAULT NULL,
  `application_form_path` varchar(255) DEFAULT NULL,
  `interest_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `term_months` int(10) unsigned NOT NULL,
  `monthly_repayment` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_repaid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','partially_disbursed','active','transferred','completed','early_settled','rejected','cancelled','disbursed','repaying','defaulted') NOT NULL DEFAULT 'pending',
  `lifecycle_stage` varchar(32) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `disbursed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `amount_approved` decimal(15,2) DEFAULT NULL,
  `amount_disbursed` decimal(15,2) NOT NULL DEFAULT 0.00,
  `loan_tier_id` bigint(20) unsigned DEFAULT NULL,
  `fund_tier_id` bigint(20) unsigned DEFAULT NULL,
  `queue_position` int(10) unsigned DEFAULT NULL,
  `member_portion` decimal(15,2) DEFAULT NULL,
  `master_portion` decimal(15,2) DEFAULT NULL,
  `repaid_to_master` decimal(15,2) NOT NULL DEFAULT 0.00,
  `installments_count` int(10) unsigned NOT NULL DEFAULT 0,
  `approved_by_id` bigint(20) unsigned DEFAULT NULL,
  `has_grace_cycle` tinyint(1) NOT NULL DEFAULT 1,
  `grace_cycles` tinyint(3) unsigned DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `threshold_waived_at` timestamp NULL DEFAULT NULL,
  `threshold_waiver_reason` text DEFAULT NULL,
  `threshold_waived_by_id` bigint(20) unsigned DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `guarantor_member_id` bigint(20) unsigned DEFAULT NULL,
  `guarantor_name` varchar(255) DEFAULT NULL,
  `guarantor_released_at` timestamp NULL DEFAULT NULL,
  `guarantor_liability_transferred_at` timestamp NULL DEFAULT NULL,
  `transferred_to_guarantor_at` timestamp NULL DEFAULT NULL,
  `witness1_name` varchar(255) DEFAULT NULL,
  `witness1_phone` varchar(50) DEFAULT NULL,
  `witness2_name` varchar(255) DEFAULT NULL,
  `witness2_phone` varchar(50) DEFAULT NULL,
  `exempted_month` tinyint(3) unsigned DEFAULT NULL,
  `exempted_year` smallint(5) unsigned DEFAULT NULL,
  `first_repayment_month` tinyint(3) unsigned DEFAULT NULL,
  `first_repayment_year` smallint(5) unsigned DEFAULT NULL,
  `settlement_threshold` decimal(8,4) DEFAULT NULL,
  `late_repayment_count` int(10) unsigned NOT NULL DEFAULT 0,
  `late_repayment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cancellation_reason` text DEFAULT NULL,
  `is_emergency` tinyint(1) NOT NULL DEFAULT 0,
  `funding_strategy` varchar(32) NOT NULL DEFAULT 'member_fund_topup',
  `cash_out_excess_fund` tinyint(1) NOT NULL DEFAULT 0,
  `member_fund_balance_at_disbursement` decimal(15,2) DEFAULT NULL,
  `payout_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loans_member_id_foreign` (`member_id`),
  KEY `loans_status_index` (`status`),
  KEY `loans_loan_tier_id_foreign` (`loan_tier_id`),
  KEY `loans_fund_tier_id_foreign` (`fund_tier_id`),
  KEY `loans_approved_by_id_foreign` (`approved_by_id`),
  KEY `loans_guarantor_member_id_foreign` (`guarantor_member_id`),
  KEY `loans_original_borrower_member_id_foreign` (`original_borrower_member_id`),
  KEY `loans_threshold_waived_by_id_foreign` (`threshold_waived_by_id`),
  CONSTRAINT `loans_approved_by_id_foreign` FOREIGN KEY (`approved_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_fund_tier_id_foreign` FOREIGN KEY (`fund_tier_id`) REFERENCES `fund_tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_guarantor_member_id_foreign` FOREIGN KEY (`guarantor_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_loan_tier_id_foreign` FOREIGN KEY (`loan_tier_id`) REFERENCES `loan_tiers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loans_original_borrower_member_id_foreign` FOREIGN KEY (`original_borrower_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `loans_threshold_waived_by_id_foreign` FOREIGN KEY (`threshold_waived_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loans`
--

LOCK TABLES `loans` WRITE;
/*!40000 ALTER TABLE `loans` DISABLE KEYS */;
/*!40000 ALTER TABLE `loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_announcements`
--

DROP TABLE IF EXISTS `member_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `audience` varchar(64) NOT NULL,
  `title_en` varchar(150) NOT NULL,
  `title_ar` varchar(150) DEFAULT NULL,
  `body_en` text NOT NULL,
  `body_ar` text DEFAULT NULL,
  `channels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`channels`)),
  `recipient_count` int(10) unsigned NOT NULL DEFAULT 0,
  `delivered_count` int(10) unsigned NOT NULL DEFAULT 0,
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_announcements_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `member_announcements_scheduled_for_index` (`scheduled_for`),
  KEY `member_announcements_sent_at_index` (`sent_at`),
  CONSTRAINT `member_announcements_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_announcements`
--

LOCK TABLES `member_announcements` WRITE;
/*!40000 ALTER TABLE `member_announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_communication_preferences`
--

DROP TABLE IF EXISTS `member_communication_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_communication_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `channels` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`channels`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_comm_prefs_user_type_unique` (`user_id`,`notification_type`),
  KEY `member_communication_preferences_user_id_index` (`user_id`),
  CONSTRAINT `member_communication_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_communication_preferences`
--

LOCK TABLES `member_communication_preferences` WRITE;
/*!40000 ALTER TABLE `member_communication_preferences` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_communication_preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `member_requests`
--

DROP TABLE IF EXISTS `member_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `requester_member_id` bigint(20) unsigned NOT NULL,
  `type` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `admin_note` text DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_requests_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `member_requests_requester_member_id_status_index` (`requester_member_id`,`status`),
  KEY `member_requests_type_index` (`type`),
  CONSTRAINT `member_requests_requester_member_id_foreign` FOREIGN KEY (`requester_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_requests_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `member_requests`
--

LOCK TABLES `member_requests` WRITE;
/*!40000 ALTER TABLE `member_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `members`
--

DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `parent_member_id` bigint(20) unsigned DEFAULT NULL,
  `member_number` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `household_email` varchar(255) DEFAULT NULL,
  `is_separated` tinyint(1) NOT NULL DEFAULT 0,
  `direct_login_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `portal_pin` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `monthly_contribution_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `exclude_from_household_contribution_funding` tinyint(1) NOT NULL DEFAULT 0,
  `joined_at` date NOT NULL,
  `contribution_arrears_cutoff_date` date DEFAULT NULL,
  `opening_cash_balance` decimal(14,2) DEFAULT NULL,
  `opening_fund_balance` decimal(14,2) DEFAULT NULL,
  `opening_balances_posted_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','inactive','withdrawn') NOT NULL DEFAULT 'active',
  `contribution_cycles_active` tinyint(1) NOT NULL DEFAULT 1,
  `payout_frozen_at` timestamp NULL DEFAULT NULL,
  `status_reason` varchar(500) DEFAULT NULL,
  `status_changed_at` timestamp NULL DEFAULT NULL,
  `frozen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `late_repayment_count` int(10) unsigned NOT NULL DEFAULT 0,
  `late_repayment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `members_member_number_unique` (`member_number`),
  KEY `members_user_id_foreign` (`user_id`),
  KEY `members_parent_member_id_foreign` (`parent_member_id`),
  KEY `members_status_index` (`status`),
  KEY `members_joined_at_index` (`joined_at`),
  KEY `members_household_email_index` (`household_email`),
  CONSTRAINT `members_parent_member_id_foreign` FOREIGN KEY (`parent_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `members`
--

LOCK TABLES `members` WRITE;
/*!40000 ALTER TABLE `members` DISABLE KEYS */;
/*!40000 ALTER TABLE `members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `membership_applications`
--

DROP TABLE IF EXISTS `membership_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `membership_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `household_email` varchar(255) DEFAULT NULL,
  `parent_application_id` bigint(20) unsigned DEFAULT NULL,
  `parent_member_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `home_phone` varchar(30) DEFAULT NULL,
  `work_phone` varchar(30) DEFAULT NULL,
  `mobile_phone` varchar(30) DEFAULT NULL,
  `occupation` varchar(150) DEFAULT NULL,
  `employer` varchar(150) DEFAULT NULL,
  `work_place` varchar(255) DEFAULT NULL,
  `residency_place` varchar(255) DEFAULT NULL,
  `monthly_income` decimal(12,2) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `iban` varchar(34) DEFAULT NULL,
  `membership_date` date DEFAULT NULL,
  `next_of_kin_name` varchar(150) DEFAULT NULL,
  `next_of_kin_phone` varchar(30) DEFAULT NULL,
  `application_type` varchar(20) NOT NULL DEFAULT 'new',
  `gender` varchar(20) DEFAULT NULL,
  `marital_status` varchar(30) DEFAULT NULL,
  `national_id` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `application_form_path` varchar(255) DEFAULT NULL,
  `membership_fee_amount` decimal(12,2) DEFAULT NULL,
  `membership_fee_transfer_date` date DEFAULT NULL,
  `membership_fee_transfer_reference` varchar(120) DEFAULT NULL,
  `membership_fee_required_amount` decimal(12,2) DEFAULT NULL,
  `membership_fee_receipt_path` varchar(255) DEFAULT NULL,
  `import_arrears_cutoff_date` date DEFAULT NULL,
  `import_cutoff_cash_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `import_cutoff_fund_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `membership_applications_status_index` (`status`),
  KEY `membership_applications_member_id_foreign` (`member_id`),
  KEY `membership_applications_household_email_index` (`household_email`),
  KEY `membership_applications_parent_application_id_index` (`parent_application_id`),
  KEY `membership_applications_parent_member_id_index` (`parent_member_id`),
  KEY `membership_applications_submitted_by_user_id_index` (`submitted_by_user_id`),
  CONSTRAINT `membership_applications_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `membership_applications_parent_application_id_foreign` FOREIGN KEY (`parent_application_id`) REFERENCES `membership_applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `membership_applications_parent_member_id_foreign` FOREIGN KEY (`parent_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `membership_applications_submitted_by_user_id_foreign` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `membership_applications`
--

LOCK TABLES `membership_applications` WRITE;
/*!40000 ALTER TABLE `membership_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `membership_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'2026_05_12_145014_create_cache_table',1),
(2,'2026_05_12_145014_create_users_table',1),
(3,'2026_05_12_145015_create_jobs_table',1),
(4,'2026_05_13_000001_create_members_table',1),
(5,'2026_05_13_000002_create_accounts_table',1),
(6,'2026_05_13_000003_create_transactions_table',1),
(7,'2026_05_13_000004_create_contributions_table',1),
(8,'2026_05_13_000005_create_loans_table',1),
(9,'2026_05_13_000006_create_loan_repayments_table',1),
(10,'2026_05_13_000007_create_membership_applications_table',1),
(11,'2026_05_13_144644_add_is_admin_to_users_table',1),
(12,'2026_05_13_153336_expand_account_types',1),
(13,'2026_05_13_155122_create_settings_table',1),
(14,'2026_05_14_064012_create_bank_statements_table',1),
(15,'2026_05_14_064013_create_bank_transactions_table',1),
(16,'2026_05_14_134551_create_fund_postings_table',1),
(17,'2026_05_14_134552_add_cleared_at_to_bank_transactions',1),
(18,'2026_05_14_134656_create_notifications_table',1),
(19,'2026_05_14_145945_create_bank_templates_table',1),
(20,'2026_05_14_153920_expand_bank_templates_table',1),
(21,'2026_05_14_174106_add_duplicate_status_to_bank_transactions',1),
(22,'2026_05_15_102715_add_master_cash_transaction_id_to_bank_transactions_table',1),
(23,'2026_05_15_103623_add_bank_template_id_to_bank_statements_table',1),
(24,'2026_05_15_164259_add_application_type_to_membership_applications_table',1),
(25,'2026_05_15_171510_add_password_to_membership_applications_table',1),
(26,'2026_05_15_172431_add_enrollment_profile_fields_to_membership_applications_table',1),
(27,'2026_05_15_183504_add_rejection_reason_to_membership_applications_table',1),
(28,'2026_05_15_193355_add_household_profile_fields_to_members_table',1),
(29,'2026_05_15_193355_add_profile_fields_to_users_table',1),
(30,'2026_05_15_193356_create_impersonation_audits_table',1),
(31,'2026_05_16_092001_create_direct_messages_table',1),
(32,'2026_05_16_092552_add_delinquent_and_terminated_member_statuses',1),
(33,'2026_05_16_112029_add_member_id_to_transactions_table',1),
(34,'2026_05_16_122306_add_loan_workflow_fields_to_loans_table',1),
(35,'2026_05_16_165305_upgrade_loans_to_legacy_full_schema',1),
(36,'2026_05_16_170500_add_loan_account_type',1),
(37,'2026_05_16_170600_expand_loan_status_enum',1),
(38,'2026_05_16_174825_upgrade_contributions_and_add_statements_tables',1),
(39,'2026_05_17_172453_add_household_fields_to_membership_applications_table',1),
(40,'2026_05_17_200000_create_member_communication_preferences_table',1),
(41,'2026_05_17_200001_create_support_requests_table',1),
(42,'2026_05_19_105821_add_membership_fee_transfer_date_to_membership_applications_table',1),
(43,'2026_05_19_122821_add_membership_application_id_to_bank_transactions_table',1),
(44,'2026_05_19_122821_add_membership_subscription_fee_fields_to_membership_applications_table',1),
(45,'2026_05_21_070121_add_collection_cycle_fields_to_contributions_table',1),
(46,'2026_05_21_071247_add_compliance_tables_and_fields',1),
(47,'2026_05_21_073336_add_system_job_runs_and_migration_depth_tables',1),
(48,'2026_05_21_080000_add_spec_completion_fields',1),
(49,'2026_05_21_083449_add_reconciliation_resolution_and_partial_clearance_fields',1),
(50,'2026_05_23_095624_add_import_cutoff_fields_to_membership_applications_table',1),
(51,'2026_05_23_095625_add_contribution_arrears_cutoff_date_to_members_table',1),
(52,'2026_05_31_092636_create_cash_out_requests_table',1),
(53,'2026_05_31_100000_add_bank_transaction_to_cash_out_requests',1),
(54,'2026_05_31_100333_add_partially_disbursed_and_transferred_to_loan_status_enum',1),
(55,'2026_05_31_112543_remove_migration_support_tables_and_columns',1),
(56,'2026_05_31_170602_add_master_bank_and_fund_transaction_ids_to_bank_transactions_table',1),
(57,'2026_05_31_172918_refresh_bank_statement_row_counts',1),
(58,'2026_06_02_173627_add_suspense_to_account_types',1),
(59,'2026_06_02_183735_create_expense_disbursements_table',1),
(60,'2026_06_03_075751_create_fee_deductions_and_fee_disbursements_tables',1),
(61,'2026_06_03_094117_create_invest_disbursements_and_invest_returns_tables',1),
(62,'2026_06_03_201755_create_loan_eligibility_override_requests_table',1),
(63,'2026_06_04_082318_create_fiscal_closes_tables',1),
(64,'2026_06_04_170002_create_dependent_allocation_changes_table',1),
(65,'2026_06_04_170003_add_on_behalf_fields_to_membership_applications_table',1),
(66,'2026_06_04_172807_add_waived_status_to_contributions_table',1),
(67,'2026_06_04_180000_add_loan_funding_strategy_fields',1),
(68,'2026_06_06_152623_create_member_requests_table',1),
(69,'2026_06_06_153205_create_database_backups_table',1),
(70,'2026_06_06_153205_create_notification_logs_table',1),
(71,'2026_06_06_154837_create_reconciliation_snapshots_table',1),
(72,'2026_06_06_180537_create_sms_import_templates_table',1),
(73,'2026_06_06_181500_create_sms_import_sessions_and_transactions_tables',1),
(74,'2026_06_13_172647_ensure_master_suspense_account_exists',1),
(75,'2026_06_18_095939_add_late_repayment_fields_to_members_table',1),
(76,'2026_06_20_101719_add_workflow_fields_to_support_requests_table',1),
(77,'2026_06_20_101720_create_member_announcements_table',1),
(78,'2026_06_20_101720_create_support_request_replies_table',1),
(79,'2026_06_20_120000_create_push_subscriptions_table',1),
(80,'2026_06_25_075803_add_inactive_status_and_membership_flags_to_members_table',1),
(81,'2026_06_28_112156_add_frozen_at_to_members_table',1),
(82,'2026_06_28_143313_simplify_member_statuses_to_three_values',1),
(83,'2026_06_29_110015_add_member_fund_balance_at_disbursement_to_loans_table',1),
(84,'2026_07_03_074440_make_users_preferred_locale_nullable',1),
(85,'2026_07_03_082935_add_guarantor_name_and_application_form_to_loans_table',1),
(86,'2026_07_03_084550_add_threshold_waiver_fields_to_loans_and_installments',1),
(87,'2026_07_08_153455_add_exclude_from_household_contribution_funding_to_members_table',1),
(88,'2026_07_10_084700_drop_contribution_cycle_allocations_table',1),
(89,'2026_07_16_081855_flip_fund_tier_loan_tier_relationship',1),
(90,'2026_07_18_123424_align_monthly_statement_balances_with_fund_ledger',1),
(91,'2026_07_23_075830_create_notification_templates_table',1),
(92,'2026_07_24_091514_create_portal_access_logs_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `monthly_statements`
--

DROP TABLE IF EXISTS `monthly_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monthly_statements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `period` varchar(7) NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_contributions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_repayments` decimal(15,2) NOT NULL DEFAULT 0.00,
  `closing_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `generated_at` timestamp NULL DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `monthly_statements_member_id_period_unique` (`member_id`,`period`),
  KEY `monthly_statements_period_index` (`period`),
  CONSTRAINT `monthly_statements_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `monthly_statements`
--

LOCK TABLES `monthly_statements` WRITE;
/*!40000 ALTER TABLE `monthly_statements` DISABLE KEYS */;
/*!40000 ALTER TABLE `monthly_statements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_logs`
--

DROP TABLE IF EXISTS `notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `channel` varchar(32) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notification_logs_user_id_foreign` (`user_id`),
  KEY `notification_logs_sent_at_index` (`sent_at`),
  KEY `notification_logs_status_index` (`status`),
  KEY `notification_logs_channel_index` (`channel`),
  CONSTRAINT `notification_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_logs`
--

LOCK TABLES `notification_logs` WRITE;
/*!40000 ALTER TABLE `notification_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_templates`
--

DROP TABLE IF EXISTS `notification_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `locale` varchar(8) NOT NULL,
  `channel_family` varchar(32) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body_markdown` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_templates_key_locale_channel_family_unique` (`key`,`locale`,`channel_family`),
  KEY `notification_templates_key_index` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_templates`
--

LOCK TABLES `notification_templates` WRITE;
/*!40000 ALTER TABLE `notification_templates` DISABLE KEYS */;
INSERT INTO `notification_templates` VALUES
(1,'contribution_due','en','email','Contribution due','{{amount}} due for {{period}} by {{deadline}}. Cash balance: {{balance}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(2,'contribution_due','en','sms_push','Contribution due','{{amount}} due for {{period}} by {{deadline}}. Cash balance: {{balance}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(3,'contribution_due','en','in_app','Contribution due','{{amount}} due for {{period}} by {{deadline}}. Cash balance: {{balance}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(4,'contribution_due','ar','email','مساهمة مستحقة','{{amount}} مستحقة عن {{period}} بحلول {{deadline}}. رصيد النقد: {{balance}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(5,'contribution_due','ar','sms_push','مساهمة مستحقة','{{amount}} مستحقة عن {{period}} بحلول {{deadline}}. رصيد النقد: {{balance}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(6,'contribution_due','ar','in_app','مساهمة مستحقة','{{amount}} مستحقة عن {{period}} بحلول {{deadline}}. رصيد النقد: {{balance}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(7,'contribution_posted','en','email','Contribution posted','Your contribution of {{amount}} for {{period}} has been posted.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(8,'contribution_posted','en','sms_push','Contribution posted','Your contribution of {{amount}} for {{period}} has been posted.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(9,'contribution_posted','en','in_app','Contribution posted','Your contribution of {{amount}} for {{period}} has been posted.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(10,'contribution_posted','ar','email','تم ترحيل المساهمة','تم ترحيل مساهمتك بمبلغ {{amount}} عن {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(11,'contribution_posted','ar','sms_push','تم ترحيل المساهمة','تم ترحيل مساهمتك بمبلغ {{amount}} عن {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(12,'contribution_posted','ar','in_app','تم ترحيل المساهمة','تم ترحيل مساهمتك بمبلغ {{amount}} عن {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(13,'contribution_late_fee','en','email','Late fee applied','A late fee of {{amount}} was applied for {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(14,'contribution_late_fee','en','sms_push','Late fee applied','A late fee of {{amount}} was applied for {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(15,'contribution_late_fee','en','in_app','Late fee applied','A late fee of {{amount}} was applied for {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(16,'contribution_late_fee','ar','email','تم تطبيق رسوم تأخير','طُبّقت رسوم تأخير بمبلغ {{amount}} عن {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(17,'contribution_late_fee','ar','sms_push','تم تطبيق رسوم تأخير','طُبّقت رسوم تأخير بمبلغ {{amount}} عن {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(18,'contribution_late_fee','ar','in_app','تم تطبيق رسوم تأخير','طُبّقت رسوم تأخير بمبلغ {{amount}} عن {{period}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(19,'fund_posting_accepted','en','email','Deposit accepted','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(20,'fund_posting_accepted','en','sms_push','Deposit accepted','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(21,'fund_posting_accepted','en','in_app','Deposit accepted','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(22,'fund_posting_accepted','ar','email','تم قبول الإيداع','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(23,'fund_posting_accepted','ar','sms_push','تم قبول الإيداع','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(24,'fund_posting_accepted','ar','in_app','تم قبول الإيداع','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(25,'fund_posting_rejected','en','email','Deposit rejected','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(26,'fund_posting_rejected','en','sms_push','Deposit rejected','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(27,'fund_posting_rejected','en','in_app','Deposit rejected','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(28,'fund_posting_rejected','ar','email','تم رفض الإيداع','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(29,'fund_posting_rejected','ar','sms_push','تم رفض الإيداع','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(30,'fund_posting_rejected','ar','in_app','تم رفض الإيداع','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(31,'fund_posting_bank_cleared','en','email','Deposit matched to bank','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(32,'fund_posting_bank_cleared','en','sms_push','Deposit matched to bank','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(33,'fund_posting_bank_cleared','en','in_app','Deposit matched to bank','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(34,'fund_posting_bank_cleared','ar','email','تمت مطابقة الإيداع مع البنك','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(35,'fund_posting_bank_cleared','ar','sms_push','تمت مطابقة الإيداع مع البنك','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(36,'fund_posting_bank_cleared','ar','in_app','تمت مطابقة الإيداع مع البنك','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(37,'member_direct_message','en','email','{{subject}}','{{sender_name}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(38,'member_direct_message','en','sms_push','{{subject}}','{{sender_name}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(39,'member_direct_message','en','in_app','{{subject}}','{{sender_name}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(40,'member_direct_message','ar','email','{{subject}}','{{sender_name}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(41,'member_direct_message','ar','sms_push','{{subject}}','{{sender_name}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(42,'member_direct_message','ar','in_app','{{subject}}','{{sender_name}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(43,'member_announcement','en','email','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(44,'member_announcement','en','sms_push','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(45,'member_announcement','en','in_app','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(46,'member_announcement','ar','email','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(47,'member_announcement','ar','sms_push','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(48,'member_announcement','ar','in_app','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(49,'loan_repayment_due','en','email','Loan repayment due','Installment of {{amount}} is due by {{deadline}} for loan #{{loan_id}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(50,'loan_repayment_due','en','sms_push','Loan repayment due','Installment of {{amount}} is due by {{deadline}} for loan #{{loan_id}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(51,'loan_repayment_due','en','in_app','Loan repayment due','Installment of {{amount}} is due by {{deadline}} for loan #{{loan_id}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(52,'loan_repayment_due','ar','email','قسط قرض مستحق','قسط بمبلغ {{amount}} مستحق بحلول {{deadline}} للقرض #{{loan_id}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(53,'loan_repayment_due','ar','sms_push','قسط قرض مستحق','قسط بمبلغ {{amount}} مستحق بحلول {{deadline}} للقرض #{{loan_id}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(54,'loan_repayment_due','ar','in_app','قسط قرض مستحق','قسط بمبلغ {{amount}} مستحق بحلول {{deadline}} للقرض #{{loan_id}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(55,'dependent_allocation_changed','en','email','Allocation updated','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(56,'dependent_allocation_changed','en','sms_push','Allocation updated','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(57,'dependent_allocation_changed','en','in_app','Allocation updated','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(58,'dependent_allocation_changed','ar','email','تم تحديث التخصيص','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(59,'dependent_allocation_changed','ar','sms_push','تم تحديث التخصيص','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(60,'dependent_allocation_changed','ar','in_app','تم تحديث التخصيص','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(61,'monthly_statement','en','email','Monthly statement ready','Your statement for {{period}} is ready.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(62,'monthly_statement','en','sms_push','Monthly statement ready','Your statement for {{period}} is ready.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(63,'monthly_statement','en','in_app','Monthly statement ready','Your statement for {{period}} is ready.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(64,'monthly_statement','ar','email','كشف الحساب الشهري جاهز','كشف حسابك عن {{period}} جاهز.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(65,'monthly_statement','ar','sms_push','كشف الحساب الشهري جاهز','كشف حسابك عن {{period}} جاهز.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(66,'monthly_statement','ar','in_app','كشف الحساب الشهري جاهز','كشف حسابك عن {{period}} جاهز.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(67,'membership_approved','en','email','Membership approved','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(68,'membership_approved','en','sms_push','Membership approved','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(69,'membership_approved','en','in_app','Membership approved','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(70,'membership_approved','ar','email','تمت الموافقة على العضوية','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(71,'membership_approved','ar','sms_push','تمت الموافقة على العضوية','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(72,'membership_approved','ar','in_app','تمت الموافقة على العضوية','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(73,'member_onboarding_greeting','en','email','Welcome to {{fund_name}}','Hello {{member_name}},\n\nWelcome to **{{fund_name}}**.\n\n## About the fund\n\n{{fund_name}} is a family fund where members contribute regularly, build a shared pool, and access loans and support according to the fund’s rules. Our goals are mutual support, clear balances, and orderly monthly collections.\n\n## Your accounts\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">💵</div>\n<strong style=\"color:#0e7490;\">Cash</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">Money available to spend inside the fund — pay contributions, repay loans, or request a cash-out.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">🏦</div>\n<strong style=\"color:#15803d;\">Fund</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">Your share of the pool. Monthly contributions increase this balance and reflect your standing.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">📄</div>\n<strong style=\"color:#c2410c;\">Loan</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">What you still owe if you have an approved loan. It goes down as EMI repayments are applied.</span>\n</td>\n</tr>\n</table>\n\nParents may also manage **dependents** and set how much of the household contribution is allocated to each dependent.\n\n## How money moves\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #0284c7;border-radius:12px;padding:14px;\">\n<strong style=\"color:#0369a1;\">Into your cash (then the fund)</strong>\n<ul style=\"margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>Deposits</strong> — bank transfer accepted → cash rises</li>\n<li><strong>Contributions</strong> — cash → fund share each cycle</li>\n<li><strong>EMI repayments</strong> — cash reduces your loan balance</li>\n<li><strong>Dependent allocation</strong> — parent splits household contribution</li>\n</ul>\n</td>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #059669;border-radius:12px;padding:14px;\">\n<strong style=\"color:#047857;\">From the fund to you</strong>\n<ul style=\"margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>Loan disbursement</strong> — payout credited to your cash</li>\n<li><strong>Cash-outs</strong> — approved withdrawal leaves your cash</li>\n</ul>\n</td>\n</tr>\n</table>\n\nKeep enough **cash** before collection windows so contributions and EMI can clear without arrears.\n\n## How to access the fund\n\n1. Open the member portal with the button below (or your fund’s member login page).\n2. Sign in with the **login credentials** shown in this email.\n3. **Change your password as soon as you sign in** (Settings in the member portal).\n4. On first visit, install the portal as an app for quicker access (see below).\n\n## Install the app (PWA)\n\n### On a computer (Chrome or Edge)\n\n1. Open the member portal in **Chrome** or **Microsoft Edge**.\n2. Look for the install icon in the address bar, or open the browser menu → **Install app** / **Apps** → **Install this site as an app**.\n3. Confirm. The fund opens in its own window from your desktop or Start menu.\n\n### On Android\n\n1. Open the member portal in **Chrome**.\n2. Tap the menu (⋮) → **Install app** or **Add to Home screen**.\n3. Confirm. An icon appears on your home screen.\n\n### On iPhone or iPad\n\n1. Open the member portal in **Safari** (required on iOS).\n2. Tap the **Share** button.\n3. Choose **Add to Home Screen**, then **Add**.\n\n## Permissions to accept\n\nWhen prompted, please allow:\n\n- **Notifications** — contribution due dates, loan reminders, and important fund messages\n- **Camera** only if the portal asks for document uploads (not required for basic use)\n\nYou can change notification preferences anytime under **Settings → Notifications** in the member portal.\n\n## Using the member portal\n\n- **Home** — balances, due items, and quick actions\n- **Contributions** — what is due and your history\n- **Loans** — request or track repayments when eligible\n- **Statements** — download monthly statements\n- **Messages / Alerts** — admin messages and system notifications\n- **Settings** — profile, language, and notification preferences\n\nIf you need help, reply to this email or contact your fund administrators.\n\nWelcome aboard!','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(74,'member_onboarding_greeting','en','sms_push','Welcome to {{fund_name}}','Hello {{member_name}},\n\nWelcome to **{{fund_name}}**.\n\n## About the fund\n\n{{fund_name}} is a family fund where members contribute regularly, build a shared pool, and access loans and support according to the fund’s rules. Our goals are mutual support, clear balances, and orderly monthly collections.\n\n## Your accounts\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">💵</div>\n<strong style=\"color:#0e7490;\">Cash</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">Money available to spend inside the fund — pay contributions, repay loans, or request a cash-out.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">🏦</div>\n<strong style=\"color:#15803d;\">Fund</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">Your share of the pool. Monthly contributions increase this balance and reflect your standing.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">📄</div>\n<strong style=\"color:#c2410c;\">Loan</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">What you still owe if you have an approved loan. It goes down as EMI repayments are applied.</span>\n</td>\n</tr>\n</table>\n\nParents may also manage **dependents** and set how much of the household contribution is allocated to each dependent.\n\n## How money moves\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #0284c7;border-radius:12px;padding:14px;\">\n<strong style=\"color:#0369a1;\">Into your cash (then the fund)</strong>\n<ul style=\"margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>Deposits</strong> — bank transfer accepted → cash rises</li>\n<li><strong>Contributions</strong> — cash → fund share each cycle</li>\n<li><strong>EMI repayments</strong> — cash reduces your loan balance</li>\n<li><strong>Dependent allocation</strong> — parent splits household contribution</li>\n</ul>\n</td>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #059669;border-radius:12px;padding:14px;\">\n<strong style=\"color:#047857;\">From the fund to you</strong>\n<ul style=\"margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>Loan disbursement</strong> — payout credited to your cash</li>\n<li><strong>Cash-outs</strong> — approved withdrawal leaves your cash</li>\n</ul>\n</td>\n</tr>\n</table>\n\nKeep enough **cash** before collection windows so contributions and EMI can clear without arrears.\n\n## How to access the fund\n\n1. Open the member portal with the button below (or your fund’s member login page).\n2. Sign in with the **login credentials** shown in this email.\n3. **Change your password as soon as you sign in** (Settings in the member portal).\n4. On first visit, install the portal as an app for quicker access (see below).\n\n## Install the app (PWA)\n\n### On a computer (Chrome or Edge)\n\n1. Open the member portal in **Chrome** or **Microsoft Edge**.\n2. Look for the install icon in the address bar, or open the browser menu → **Install app** / **Apps** → **Install this site as an app**.\n3. Confirm. The fund opens in its own window from your desktop or Start menu.\n\n### On Android\n\n1. Open the member portal in **Chrome**.\n2. Tap the menu (⋮) → **Install app** or **Add to Home screen**.\n3. Confirm. An icon appears on your home screen.\n\n### On iPhone or iPad\n\n1. Open the member portal in **Safari** (required on iOS).\n2. Tap the **Share** button.\n3. Choose **Add to Home Screen**, then **Add**.\n\n## Permissions to accept\n\nWhen prompted, please allow:\n\n- **Notifications** — contribution due dates, loan reminders, and important fund messages\n- **Camera** only if the portal asks for document uploads (not required for basic use)\n\nYou can change notification preferences anytime under **Settings → Notifications** in the member portal.\n\n## Using the member portal\n\n- **Home** — balances, due items, and quick actions\n- **Contributions** — what is due and your history\n- **Loans** — request or track repayments when eligible\n- **Statements** — download monthly statements\n- **Messages / Alerts** — admin messages and system notifications\n- **Settings** — profile, language, and notification preferences\n\nIf you need help, reply to this email or contact your fund administrators.\n\nWelcome aboard!','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(75,'member_onboarding_greeting','en','in_app','Welcome to {{fund_name}}','Hello {{member_name}},\n\nWelcome to **{{fund_name}}**.\n\n## About the fund\n\n{{fund_name}} is a family fund where members contribute regularly, build a shared pool, and access loans and support according to the fund’s rules. Our goals are mutual support, clear balances, and orderly monthly collections.\n\n## Your accounts\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">💵</div>\n<strong style=\"color:#0e7490;\">Cash</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">Money available to spend inside the fund — pay contributions, repay loans, or request a cash-out.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">🏦</div>\n<strong style=\"color:#15803d;\">Fund</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">Your share of the pool. Monthly contributions increase this balance and reflect your standing.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">📄</div>\n<strong style=\"color:#c2410c;\">Loan</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">What you still owe if you have an approved loan. It goes down as EMI repayments are applied.</span>\n</td>\n</tr>\n</table>\n\nParents may also manage **dependents** and set how much of the household contribution is allocated to each dependent.\n\n## How money moves\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #0284c7;border-radius:12px;padding:14px;\">\n<strong style=\"color:#0369a1;\">Into your cash (then the fund)</strong>\n<ul style=\"margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>Deposits</strong> — bank transfer accepted → cash rises</li>\n<li><strong>Contributions</strong> — cash → fund share each cycle</li>\n<li><strong>EMI repayments</strong> — cash reduces your loan balance</li>\n<li><strong>Dependent allocation</strong> — parent splits household contribution</li>\n</ul>\n</td>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #059669;border-radius:12px;padding:14px;\">\n<strong style=\"color:#047857;\">From the fund to you</strong>\n<ul style=\"margin:10px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>Loan disbursement</strong> — payout credited to your cash</li>\n<li><strong>Cash-outs</strong> — approved withdrawal leaves your cash</li>\n</ul>\n</td>\n</tr>\n</table>\n\nKeep enough **cash** before collection windows so contributions and EMI can clear without arrears.\n\n## How to access the fund\n\n1. Open the member portal with the button below (or your fund’s member login page).\n2. Sign in with the **login credentials** shown in this email.\n3. **Change your password as soon as you sign in** (Settings in the member portal).\n4. On first visit, install the portal as an app for quicker access (see below).\n\n## Install the app (PWA)\n\n### On a computer (Chrome or Edge)\n\n1. Open the member portal in **Chrome** or **Microsoft Edge**.\n2. Look for the install icon in the address bar, or open the browser menu → **Install app** / **Apps** → **Install this site as an app**.\n3. Confirm. The fund opens in its own window from your desktop or Start menu.\n\n### On Android\n\n1. Open the member portal in **Chrome**.\n2. Tap the menu (⋮) → **Install app** or **Add to Home screen**.\n3. Confirm. An icon appears on your home screen.\n\n### On iPhone or iPad\n\n1. Open the member portal in **Safari** (required on iOS).\n2. Tap the **Share** button.\n3. Choose **Add to Home Screen**, then **Add**.\n\n## Permissions to accept\n\nWhen prompted, please allow:\n\n- **Notifications** — contribution due dates, loan reminders, and important fund messages\n- **Camera** only if the portal asks for document uploads (not required for basic use)\n\nYou can change notification preferences anytime under **Settings → Notifications** in the member portal.\n\n## Using the member portal\n\n- **Home** — balances, due items, and quick actions\n- **Contributions** — what is due and your history\n- **Loans** — request or track repayments when eligible\n- **Statements** — download monthly statements\n- **Messages / Alerts** — admin messages and system notifications\n- **Settings** — profile, language, and notification preferences\n\nIf you need help, reply to this email or contact your fund administrators.\n\nWelcome aboard!','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(76,'member_onboarding_greeting','ar','email','مرحبًا بك في {{fund_name}}','مرحبًا {{member_name}}،\n\nأهلًا بك في **{{fund_name}}**.\n\n## عن الصندوق\n\n{{fund_name}} صندوق عائلي يساهم فيه الأعضاء بانتظام، ويبنيون رصيدًا مشتركًا، ويحصلون على القروض والدعم وفق قواعد الصندوق. أهدافنا هي الدعم المتبادل، ووضوح الأرصدة، وتحصيل الاشتراكات الشهرية بشكل منظم.\n\n## حساباتك\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">💵</div>\n<strong style=\"color:#0e7490;\">النقد</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">المال المتاح للاستخدام داخل الصندوق — دفع الاشتراكات، سداد القروض، أو طلب سحب نقدي.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">🏦</div>\n<strong style=\"color:#15803d;\">الصندوق</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">حصتك في المجموع المشترك. تزيد الاشتراكات الشهرية هذا الرصيد وتعكس مكانتك.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">📄</div>\n<strong style=\"color:#c2410c;\">القرض</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">ما لا يزال مستحقًا عليك إذا كان لديك قرض معتمد. ينخفض مع تطبيق الأقساط.</span>\n</td>\n</tr>\n</table>\n\nقد يدير ولي الأمر أيضًا **التابعين** ويحدد مقدار ما يُخصَّص من اشتراك الأسرة لكل تابع.\n\n## كيف تتحرك الأموال\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #0284c7;border-radius:12px;padding:14px;\">\n<strong style=\"color:#0369a1;\">إلى نقدك (ثم إلى الصندوق)</strong>\n<ul style=\"margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>الإيداعات</strong> — بعد قبول التحويل البنكي يرتفع النقد</li>\n<li><strong>الاشتراكات</strong> — من النقد إلى حصة الصندوق كل دورة</li>\n<li><strong>سداد الأقساط</strong> — النقد يخفض رصيد القرض</li>\n<li><strong>تخصيص التابعين</strong> — ولي الأمر يقسّم اشتراك الأسرة</li>\n</ul>\n</td>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #059669;border-radius:12px;padding:14px;\">\n<strong style=\"color:#047857;\">من الصندوق إليك</strong>\n<ul style=\"margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>صرف القرض</strong> — يُضاف المبلغ إلى نقدك</li>\n<li><strong>السحب النقدي</strong> — بعد الموافقة ينخفض رصيد النقد</li>\n</ul>\n</td>\n</tr>\n</table>\n\nاحرص على وجود **نقد** كافٍ قبل نوافذ التحصيل حتى تُسدَّد الاشتراكات والأقساط دون متأخرات.\n\n## كيفية الدخول إلى الصندوق\n\n1. افتح بوابة العضو عبر الزر أدناه (أو صفحة تسجيل دخول الأعضاء لصندوقك).\n2. سجّل الدخول باستخدام **بيانات الدخول** الظاهرة في هذه الرسالة.\n3. **غيّر كلمة المرور فور تسجيل الدخول** (من الإعدادات داخل بوابة العضو).\n4. في الزيارة الأولى، ثبّت البوابة كتطبيق لوصول أسرع (انظر أدناه).\n\n## تثبيت التطبيق (PWA)\n\n### على الحاسوب (Chrome أو Edge)\n\n1. افتح بوابة العضو في **Chrome** أو **Microsoft Edge**.\n2. ابحث عن أيقونة التثبيت في شريط العنوان، أو من قائمة المتصفح ← **تثبيت التطبيق** / **التطبيقات** ← **تثبيت هذا الموقع كتطبيق**.\n3. أكّد. يفتح الصندوق في نافذة مستقلة من سطح المكتب أو قائمة ابدأ.\n\n### على Android\n\n1. افتح بوابة العضو في **Chrome**.\n2. من القائمة (⋮) ← **تثبيت التطبيق** أو **إضافة إلى الشاشة الرئيسية**.\n3. أكّد. تظهر أيقونة على الشاشة الرئيسية.\n\n### على iPhone أو iPad\n\n1. افتح بوابة العضو في **Safari** (مطلوب على iOS).\n2. اضغط زر **المشاركة**.\n3. اختر **إضافة إلى الشاشة الرئيسية** ثم **إضافة**.\n\n## الأذونات التي يُفضّل قبولها\n\nعند ظهور الطلب، يُرجى السماح بـ:\n\n- **الإشعارات** — مواعيد الاشتراكات، تذكيرات القروض، ورسائل الصندوق المهمة\n- **الكاميرا** فقط إذا طلبت البوابة رفع مستندات (غير مطلوبة للاستخدام الأساسي)\n\nيمكنك تعديل تفضيلات الإشعارات في أي وقت من **الإعدادات ← الإشعارات** داخل بوابة العضو.\n\n## استخدام بوابة العضو\n\n- **الرئيسية** — الأرصدة والمستحقات والإجراءات السريعة\n- **الاشتراكات** — المستحق وسجل الدفعات\n- **القروض** — طلب القرض أو متابعة السداد عند الأهلية\n- **الكشوف** — تنزيل كشوف الحساب الشهرية\n- **الرسائل / التنبيهات** — رسائل الإدارة وإشعارات النظام\n- **الإعدادات** — الملف الشخصي واللغة وتفضيلات الإشعارات\n\nإذا احتجت مساعدة، اردّ على هذا البريد أو تواصل مع إدارة الصندوق.\n\nمرحبًا بك معنا!','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(77,'member_onboarding_greeting','ar','sms_push','مرحبًا بك في {{fund_name}}','مرحبًا {{member_name}}،\n\nأهلًا بك في **{{fund_name}}**.\n\n## عن الصندوق\n\n{{fund_name}} صندوق عائلي يساهم فيه الأعضاء بانتظام، ويبنيون رصيدًا مشتركًا، ويحصلون على القروض والدعم وفق قواعد الصندوق. أهدافنا هي الدعم المتبادل، ووضوح الأرصدة، وتحصيل الاشتراكات الشهرية بشكل منظم.\n\n## حساباتك\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">💵</div>\n<strong style=\"color:#0e7490;\">النقد</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">المال المتاح للاستخدام داخل الصندوق — دفع الاشتراكات، سداد القروض، أو طلب سحب نقدي.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">🏦</div>\n<strong style=\"color:#15803d;\">الصندوق</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">حصتك في المجموع المشترك. تزيد الاشتراكات الشهرية هذا الرصيد وتعكس مكانتك.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">📄</div>\n<strong style=\"color:#c2410c;\">القرض</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">ما لا يزال مستحقًا عليك إذا كان لديك قرض معتمد. ينخفض مع تطبيق الأقساط.</span>\n</td>\n</tr>\n</table>\n\nقد يدير ولي الأمر أيضًا **التابعين** ويحدد مقدار ما يُخصَّص من اشتراك الأسرة لكل تابع.\n\n## كيف تتحرك الأموال\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #0284c7;border-radius:12px;padding:14px;\">\n<strong style=\"color:#0369a1;\">إلى نقدك (ثم إلى الصندوق)</strong>\n<ul style=\"margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>الإيداعات</strong> — بعد قبول التحويل البنكي يرتفع النقد</li>\n<li><strong>الاشتراكات</strong> — من النقد إلى حصة الصندوق كل دورة</li>\n<li><strong>سداد الأقساط</strong> — النقد يخفض رصيد القرض</li>\n<li><strong>تخصيص التابعين</strong> — ولي الأمر يقسّم اشتراك الأسرة</li>\n</ul>\n</td>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #059669;border-radius:12px;padding:14px;\">\n<strong style=\"color:#047857;\">من الصندوق إليك</strong>\n<ul style=\"margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>صرف القرض</strong> — يُضاف المبلغ إلى نقدك</li>\n<li><strong>السحب النقدي</strong> — بعد الموافقة ينخفض رصيد النقد</li>\n</ul>\n</td>\n</tr>\n</table>\n\nاحرص على وجود **نقد** كافٍ قبل نوافذ التحصيل حتى تُسدَّد الاشتراكات والأقساط دون متأخرات.\n\n## كيفية الدخول إلى الصندوق\n\n1. افتح بوابة العضو عبر الزر أدناه (أو صفحة تسجيل دخول الأعضاء لصندوقك).\n2. سجّل الدخول باستخدام **بيانات الدخول** الظاهرة في هذه الرسالة.\n3. **غيّر كلمة المرور فور تسجيل الدخول** (من الإعدادات داخل بوابة العضو).\n4. في الزيارة الأولى، ثبّت البوابة كتطبيق لوصول أسرع (انظر أدناه).\n\n## تثبيت التطبيق (PWA)\n\n### على الحاسوب (Chrome أو Edge)\n\n1. افتح بوابة العضو في **Chrome** أو **Microsoft Edge**.\n2. ابحث عن أيقونة التثبيت في شريط العنوان، أو من قائمة المتصفح ← **تثبيت التطبيق** / **التطبيقات** ← **تثبيت هذا الموقع كتطبيق**.\n3. أكّد. يفتح الصندوق في نافذة مستقلة من سطح المكتب أو قائمة ابدأ.\n\n### على Android\n\n1. افتح بوابة العضو في **Chrome**.\n2. من القائمة (⋮) ← **تثبيت التطبيق** أو **إضافة إلى الشاشة الرئيسية**.\n3. أكّد. تظهر أيقونة على الشاشة الرئيسية.\n\n### على iPhone أو iPad\n\n1. افتح بوابة العضو في **Safari** (مطلوب على iOS).\n2. اضغط زر **المشاركة**.\n3. اختر **إضافة إلى الشاشة الرئيسية** ثم **إضافة**.\n\n## الأذونات التي يُفضّل قبولها\n\nعند ظهور الطلب، يُرجى السماح بـ:\n\n- **الإشعارات** — مواعيد الاشتراكات، تذكيرات القروض، ورسائل الصندوق المهمة\n- **الكاميرا** فقط إذا طلبت البوابة رفع مستندات (غير مطلوبة للاستخدام الأساسي)\n\nيمكنك تعديل تفضيلات الإشعارات في أي وقت من **الإعدادات ← الإشعارات** داخل بوابة العضو.\n\n## استخدام بوابة العضو\n\n- **الرئيسية** — الأرصدة والمستحقات والإجراءات السريعة\n- **الاشتراكات** — المستحق وسجل الدفعات\n- **القروض** — طلب القرض أو متابعة السداد عند الأهلية\n- **الكشوف** — تنزيل كشوف الحساب الشهرية\n- **الرسائل / التنبيهات** — رسائل الإدارة وإشعارات النظام\n- **الإعدادات** — الملف الشخصي واللغة وتفضيلات الإشعارات\n\nإذا احتجت مساعدة، اردّ على هذا البريد أو تواصل مع إدارة الصندوق.\n\nمرحبًا بك معنا!','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(78,'member_onboarding_greeting','ar','in_app','مرحبًا بك في {{fund_name}}','مرحبًا {{member_name}}،\n\nأهلًا بك في **{{fund_name}}**.\n\n## عن الصندوق\n\n{{fund_name}} صندوق عائلي يساهم فيه الأعضاء بانتظام، ويبنيون رصيدًا مشتركًا، ويحصلون على القروض والدعم وفق قواعد الصندوق. أهدافنا هي الدعم المتبادل، ووضوح الأرصدة، وتحصيل الاشتراكات الشهرية بشكل منظم.\n\n## حساباتك\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">💵</div>\n<strong style=\"color:#0e7490;\">النقد</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">المال المتاح للاستخدام داخل الصندوق — دفع الاشتراكات، سداد القروض، أو طلب سحب نقدي.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">🏦</div>\n<strong style=\"color:#15803d;\">الصندوق</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">حصتك في المجموع المشترك. تزيد الاشتراكات الشهرية هذا الرصيد وتعكس مكانتك.</span>\n</td>\n<td width=\"33%\" valign=\"top\" style=\"width:33%;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;\">\n<div style=\"font-size:20px;line-height:1;margin-bottom:6px;\">📄</div>\n<strong style=\"color:#c2410c;\">القرض</strong><br>\n<span style=\"color:#475569;font-size:13px;line-height:1.45;\">ما لا يزال مستحقًا عليك إذا كان لديك قرض معتمد. ينخفض مع تطبيق الأقساط.</span>\n</td>\n</tr>\n</table>\n\nقد يدير ولي الأمر أيضًا **التابعين** ويحدد مقدار ما يُخصَّص من اشتراك الأسرة لكل تابع.\n\n## كيف تتحرك الأموال\n\n<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0 16px;\">\n<tr>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #0284c7;border-radius:12px;padding:14px;\">\n<strong style=\"color:#0369a1;\">إلى نقدك (ثم إلى الصندوق)</strong>\n<ul style=\"margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>الإيداعات</strong> — بعد قبول التحويل البنكي يرتفع النقد</li>\n<li><strong>الاشتراكات</strong> — من النقد إلى حصة الصندوق كل دورة</li>\n<li><strong>سداد الأقساط</strong> — النقد يخفض رصيد القرض</li>\n<li><strong>تخصيص التابعين</strong> — ولي الأمر يقسّم اشتراك الأسرة</li>\n</ul>\n</td>\n<td width=\"50%\" valign=\"top\" style=\"width:50%;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #059669;border-radius:12px;padding:14px;\">\n<strong style=\"color:#047857;\">من الصندوق إليك</strong>\n<ul style=\"margin:10px 0 0;padding-right:18px;color:#475569;font-size:13px;line-height:1.5;\">\n<li><strong>صرف القرض</strong> — يُضاف المبلغ إلى نقدك</li>\n<li><strong>السحب النقدي</strong> — بعد الموافقة ينخفض رصيد النقد</li>\n</ul>\n</td>\n</tr>\n</table>\n\nاحرص على وجود **نقد** كافٍ قبل نوافذ التحصيل حتى تُسدَّد الاشتراكات والأقساط دون متأخرات.\n\n## كيفية الدخول إلى الصندوق\n\n1. افتح بوابة العضو عبر الزر أدناه (أو صفحة تسجيل دخول الأعضاء لصندوقك).\n2. سجّل الدخول باستخدام **بيانات الدخول** الظاهرة في هذه الرسالة.\n3. **غيّر كلمة المرور فور تسجيل الدخول** (من الإعدادات داخل بوابة العضو).\n4. في الزيارة الأولى، ثبّت البوابة كتطبيق لوصول أسرع (انظر أدناه).\n\n## تثبيت التطبيق (PWA)\n\n### على الحاسوب (Chrome أو Edge)\n\n1. افتح بوابة العضو في **Chrome** أو **Microsoft Edge**.\n2. ابحث عن أيقونة التثبيت في شريط العنوان، أو من قائمة المتصفح ← **تثبيت التطبيق** / **التطبيقات** ← **تثبيت هذا الموقع كتطبيق**.\n3. أكّد. يفتح الصندوق في نافذة مستقلة من سطح المكتب أو قائمة ابدأ.\n\n### على Android\n\n1. افتح بوابة العضو في **Chrome**.\n2. من القائمة (⋮) ← **تثبيت التطبيق** أو **إضافة إلى الشاشة الرئيسية**.\n3. أكّد. تظهر أيقونة على الشاشة الرئيسية.\n\n### على iPhone أو iPad\n\n1. افتح بوابة العضو في **Safari** (مطلوب على iOS).\n2. اضغط زر **المشاركة**.\n3. اختر **إضافة إلى الشاشة الرئيسية** ثم **إضافة**.\n\n## الأذونات التي يُفضّل قبولها\n\nعند ظهور الطلب، يُرجى السماح بـ:\n\n- **الإشعارات** — مواعيد الاشتراكات، تذكيرات القروض، ورسائل الصندوق المهمة\n- **الكاميرا** فقط إذا طلبت البوابة رفع مستندات (غير مطلوبة للاستخدام الأساسي)\n\nيمكنك تعديل تفضيلات الإشعارات في أي وقت من **الإعدادات ← الإشعارات** داخل بوابة العضو.\n\n## استخدام بوابة العضو\n\n- **الرئيسية** — الأرصدة والمستحقات والإجراءات السريعة\n- **الاشتراكات** — المستحق وسجل الدفعات\n- **القروض** — طلب القرض أو متابعة السداد عند الأهلية\n- **الكشوف** — تنزيل كشوف الحساب الشهرية\n- **الرسائل / التنبيهات** — رسائل الإدارة وإشعارات النظام\n- **الإعدادات** — الملف الشخصي واللغة وتفضيلات الإشعارات\n\nإذا احتجت مساعدة، اردّ على هذا البريد أو تواصل مع إدارة الصندوق.\n\nمرحبًا بك معنا!','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(79,'membership_rejected','en','email','Membership application update','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(80,'membership_rejected','en','sms_push','Membership application update','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(81,'membership_rejected','en','in_app','Membership application update','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(82,'membership_rejected','ar','email','تحديث طلب العضوية','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(83,'membership_rejected','ar','sms_push','تحديث طلب العضوية','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(84,'membership_rejected','ar','in_app','تحديث طلب العضوية','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(85,'generic_member_alert','en','email','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(86,'generic_member_alert','en','sms_push','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(87,'generic_member_alert','en','in_app','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(88,'generic_member_alert','ar','email','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(89,'generic_member_alert','ar','sms_push','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(90,'generic_member_alert','ar','in_app','{{title}}','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(91,'reconciliation_digest','en','email','{{title}}','{{summary}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(92,'reconciliation_digest','en','sms_push','{{title}}','{{summary}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(93,'reconciliation_digest','en','in_app','{{title}}','{{summary}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(94,'reconciliation_digest','ar','email','{{title}}','{{summary}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(95,'reconciliation_digest','ar','sms_push','{{title}}','{{summary}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(96,'reconciliation_digest','ar','in_app','{{title}}','{{summary}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(97,'delinquency_digest','en','email','Delinquency digest','{{overdue}} overdue installment(s) · {{arrears}} contribution period(s) in arrears · {{delinquent}} delinquent member(s).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(98,'delinquency_digest','en','sms_push','Delinquency digest','{{overdue}} overdue installment(s) · {{arrears}} contribution period(s) in arrears · {{delinquent}} delinquent member(s).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(99,'delinquency_digest','en','in_app','Delinquency digest','{{overdue}} overdue installment(s) · {{arrears}} contribution period(s) in arrears · {{delinquent}} delinquent member(s).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(100,'delinquency_digest','ar','email','ملخص التعثر','{{overdue}} قسط(أقساط) متأخر · {{arrears}} فترة(فترات) مساهمات متأخرة · {{delinquent}} عضو(أعضاء) متعثر.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(101,'delinquency_digest','ar','sms_push','ملخص التعثر','{{overdue}} قسط(أقساط) متأخر · {{arrears}} فترة(فترات) مساهمات متأخرة · {{delinquent}} عضو(أعضاء) متعثر.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(102,'delinquency_digest','ar','in_app','ملخص التعثر','{{overdue}} قسط(أقساط) متأخر · {{arrears}} فترة(فترات) مساهمات متأخرة · {{delinquent}} عضو(أعضاء) متعثر.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(103,'reconciliation_exception','en','email','Reconciliation exception','{{severity}} exception {{code}} in {{domain}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(104,'reconciliation_exception','en','sms_push','Reconciliation exception','{{severity}} exception {{code}} in {{domain}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(105,'reconciliation_exception','en','in_app','Reconciliation exception','{{severity}} exception {{code}} in {{domain}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(106,'reconciliation_exception','ar','email','استثناء مطابقة','استثناء {{severity}}: {{code}} في {{domain}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(107,'reconciliation_exception','ar','sms_push','استثناء مطابقة','استثناء {{severity}}: {{code}} في {{domain}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(108,'reconciliation_exception','ar','in_app','استثناء مطابقة','استثناء {{severity}}: {{code}} في {{domain}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(109,'new_loan_application','en','email','New loan application','{{member_name}} applied for {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(110,'new_loan_application','en','sms_push','New loan application','{{member_name}} applied for {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(111,'new_loan_application','en','in_app','New loan application','{{member_name}} applied for {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(112,'new_loan_application','ar','email','طلب قرض جديد','{{member_name}} تقدّم بطلب بمبلغ {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(113,'new_loan_application','ar','sms_push','طلب قرض جديد','{{member_name}} تقدّم بطلب بمبلغ {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(114,'new_loan_application','ar','in_app','طلب قرض جديد','{{member_name}} تقدّم بطلب بمبلغ {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(115,'new_fund_posting','en','email','New deposit request','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(116,'new_fund_posting','en','sms_push','New deposit request','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(117,'new_fund_posting','en','in_app','New deposit request','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(118,'new_fund_posting','ar','email','طلب إيداع جديد','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(119,'new_fund_posting','ar','sms_push','طلب إيداع جديد','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(120,'new_fund_posting','ar','in_app','طلب إيداع جديد','{{body}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(121,'new_cash_out_request','en','email','New cash-out request','{{member_name}} requested {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(122,'new_cash_out_request','en','sms_push','New cash-out request','{{member_name}} requested {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(123,'new_cash_out_request','en','in_app','New cash-out request','{{member_name}} requested {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(124,'new_cash_out_request','ar','email','طلب سحب نقدي جديد','{{member_name}} طلب {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(125,'new_cash_out_request','ar','sms_push','طلب سحب نقدي جديد','{{member_name}} طلب {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(126,'new_cash_out_request','ar','in_app','طلب سحب نقدي جديد','{{member_name}} طلب {{amount}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(127,'new_membership_application','en','email','New membership application','{{member_name}} submitted a membership application.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(128,'new_membership_application','en','sms_push','New membership application','{{member_name}} submitted a membership application.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(129,'new_membership_application','en','in_app','New membership application','{{member_name}} submitted a membership application.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(130,'new_membership_application','ar','email','طلب عضوية جديد','{{member_name}} قدّم طلب عضوية.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(131,'new_membership_application','ar','sms_push','طلب عضوية جديد','{{member_name}} قدّم طلب عضوية.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(132,'new_membership_application','ar','in_app','طلب عضوية جديد','{{member_name}} قدّم طلب عضوية.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(133,'new_support_request','en','email','Support request #{{request_id}}: {{subject}}','Request #{{request_id}} from {{from}}\\nCategory: {{category}}\\n\\n{{message}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(134,'new_support_request','en','sms_push','Support request #{{request_id}}: {{subject}}','Request #{{request_id}} from {{from}}\\nCategory: {{category}}\\n\\n{{message}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(135,'new_support_request','en','in_app','Support request #{{request_id}}: {{subject}}','Request #{{request_id}} from {{from}}\\nCategory: {{category}}\\n\\n{{message}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(136,'new_support_request','ar','email','طلب دعم #{{request_id}}: {{subject}}','طلب #{{request_id}} من {{from}}\\nالتصنيف: {{category}}\\n\\n{{message}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(137,'new_support_request','ar','sms_push','طلب دعم #{{request_id}}: {{subject}}','طلب #{{request_id}} من {{from}}\\nالتصنيف: {{category}}\\n\\n{{message}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(138,'new_support_request','ar','in_app','طلب دعم #{{request_id}}: {{subject}}','طلب #{{request_id}} من {{from}}\\nالتصنيف: {{category}}\\n\\n{{message}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(139,'new_member_request','en','email','New member request','{{member_name}} — {{request_type}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(140,'new_member_request','en','sms_push','New member request','{{member_name}} — {{request_type}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(141,'new_member_request','en','in_app','New member request','{{member_name}} — {{request_type}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(142,'new_member_request','ar','email','طلب عضو جديد','{{member_name}} — {{request_type}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(143,'new_member_request','ar','sms_push','طلب عضو جديد','{{member_name}} — {{request_type}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(144,'new_member_request','ar','in_app','طلب عضو جديد','{{member_name}} — {{request_type}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(145,'new_loan_eligibility_override','en','email','Loan eligibility review requested','{{member_name}} requested an eligibility review ({{gate_count}} blocked rule(s), first: {{first_gate}}).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(146,'new_loan_eligibility_override','en','sms_push','Loan eligibility review requested','{{member_name}} requested an eligibility review ({{gate_count}} blocked rule(s), first: {{first_gate}}).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(147,'new_loan_eligibility_override','en','in_app','Loan eligibility review requested','{{member_name}} requested an eligibility review ({{gate_count}} blocked rule(s), first: {{first_gate}}).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(148,'new_loan_eligibility_override','ar','email','طلب مراجعة أهلية القرض','{{member_name}} طلب مراجعة الأهلية ({{gate_count}} قاعدة محظورة، الأولى: {{first_gate}}).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(149,'new_loan_eligibility_override','ar','sms_push','طلب مراجعة أهلية القرض','{{member_name}} طلب مراجعة الأهلية ({{gate_count}} قاعدة محظورة، الأولى: {{first_gate}}).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(150,'new_loan_eligibility_override','ar','in_app','طلب مراجعة أهلية القرض','{{member_name}} طلب مراجعة الأهلية ({{gate_count}} قاعدة محظورة، الأولى: {{first_gate}}).','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(151,'loan_guarantor_transfer_admin','en','email','Loan transferred to guarantor','Loan #{{loan_id}} moved from {{borrower_name}} to guarantor {{guarantor_name}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(152,'loan_guarantor_transfer_admin','en','sms_push','Loan transferred to guarantor','Loan #{{loan_id}} moved from {{borrower_name}} to guarantor {{guarantor_name}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(153,'loan_guarantor_transfer_admin','en','in_app','Loan transferred to guarantor','Loan #{{loan_id}} moved from {{borrower_name}} to guarantor {{guarantor_name}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(154,'loan_guarantor_transfer_admin','ar','email','نقل القرض إلى الكفيل','القرض #{{loan_id}} نُقل من {{borrower_name}} إلى الكفيل {{guarantor_name}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(155,'loan_guarantor_transfer_admin','ar','sms_push','نقل القرض إلى الكفيل','القرض #{{loan_id}} نُقل من {{borrower_name}} إلى الكفيل {{guarantor_name}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(156,'loan_guarantor_transfer_admin','ar','in_app','نقل القرض إلى الكفيل','القرض #{{loan_id}} نُقل من {{borrower_name}} إلى الكفيل {{guarantor_name}}.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(157,'admin_direct_message','en','email','{{title}}','{{subject}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(158,'admin_direct_message','en','sms_push','{{title}}','{{subject}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(159,'admin_direct_message','en','in_app','{{title}}','{{subject}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(160,'admin_direct_message','ar','email','{{title}}','{{subject}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(161,'admin_direct_message','ar','sms_push','{{title}}','{{subject}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(162,'admin_direct_message','ar','in_app','{{title}}','{{subject}}: {{preview}}','2026-07-24 07:52:03','2026-07-24 07:52:03');
/*!40000 ALTER TABLE `notification_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `portal_access_logs`
--

DROP TABLE IF EXISTS `portal_access_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `member_name` varchar(255) DEFAULT NULL,
  `panel` varchar(16) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `portal_access_logs_user_id_foreign` (`user_id`),
  KEY `portal_access_logs_member_id_foreign` (`member_id`),
  KEY `portal_access_logs_accessed_at_index` (`accessed_at`),
  KEY `portal_access_logs_panel_index` (`panel`),
  KEY `portal_access_logs_member_name_index` (`member_name`),
  CONSTRAINT `portal_access_logs_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portal_access_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `portal_access_logs`
--

LOCK TABLES `portal_access_logs` WRITE;
/*!40000 ALTER TABLE `portal_access_logs` DISABLE KEYS */;
INSERT INTO `portal_access_logs` VALUES
(1,1,NULL,'Fund Admin','admin','176.224.243.118','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','2026-07-24 07:54:13','2026-07-24 07:54:13','2026-07-24 07:54:13',NULL);
/*!40000 ALTER TABLE `portal_access_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `push_subscriptions`
--

DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscribable_type` varchar(255) NOT NULL,
  `subscribable_id` bigint(20) unsigned NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `public_key` varchar(255) DEFAULT NULL,
  `auth_token` varchar(255) DEFAULT NULL,
  `content_encoding` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_subscriptions_endpoint_unique` (`endpoint`),
  KEY `push_subscriptions_subscribable_morph_idx` (`subscribable_type`,`subscribable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `push_subscriptions`
--

LOCK TABLES `push_subscriptions` WRITE;
/*!40000 ALTER TABLE `push_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `push_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reconciliation_exceptions`
--

DROP TABLE IF EXISTS `reconciliation_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_exceptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `exception_code` varchar(64) NOT NULL,
  `exception_type` varchar(32) DEFAULT NULL,
  `domain` varchar(32) NOT NULL,
  `severity` varchar(16) NOT NULL DEFAULT 'medium',
  `amount_delta` decimal(14,2) DEFAULT NULL,
  `affected_entities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`affected_entities`)),
  `auto_resolve_attempted` tinyint(1) NOT NULL DEFAULT 0,
  `auto_resolve_reason` text DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'open',
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `resolution_action` varchar(32) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `sla_deadline` timestamp NULL DEFAULT NULL,
  `deferred_until` timestamp NULL DEFAULT NULL,
  `raised_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reconciliation_exceptions_assigned_to_foreign` (`assigned_to`),
  KEY `reconciliation_exceptions_status_severity_index` (`status`,`severity`),
  KEY `reconciliation_exceptions_domain_exception_code_index` (`domain`,`exception_code`),
  CONSTRAINT `reconciliation_exceptions_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reconciliation_exceptions`
--

LOCK TABLES `reconciliation_exceptions` WRITE;
/*!40000 ALTER TABLE `reconciliation_exceptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `reconciliation_exceptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reconciliation_snapshots`
--

DROP TABLE IF EXISTS `reconciliation_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mode` varchar(32) NOT NULL,
  `as_of` timestamp NOT NULL,
  `period_start` timestamp NULL DEFAULT NULL,
  `period_end` timestamp NULL DEFAULT NULL,
  `is_passing` tinyint(1) NOT NULL DEFAULT 0,
  `critical_issues` smallint(5) unsigned NOT NULL DEFAULT 0,
  `warnings` smallint(5) unsigned NOT NULL DEFAULT 0,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`summary`)),
  `report` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`report`)),
  `created_by_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reconciliation_snapshots_created_by_id_foreign` (`created_by_id`),
  KEY `reconciliation_snapshots_mode_as_of_index` (`mode`,`as_of`),
  KEY `reconciliation_snapshots_created_at_index` (`created_at`),
  CONSTRAINT `reconciliation_snapshots_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reconciliation_snapshots`
--

LOCK TABLES `reconciliation_snapshots` WRITE;
/*!40000 ALTER TABLE `reconciliation_snapshots` DISABLE KEYS */;
/*!40000 ALTER TABLE `reconciliation_snapshots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES
('XfF5VLXcQFvyIF7ypnhJN6Qv2o0vqLJH2iqLa88T',1,'176.224.243.118','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36','YTo4OntzOjY6Il90b2tlbiI7czo0MDoidUlIOGNzdnVzdzZCODhBQlgyRmpTaHZHSldHWU1NSjNmU0dNeDRkYSI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NTk6Imh0dHBzOi8vc2FtbWFuLmZ1bmRmbG93LXNhYXMub3NhbW1hbi5jb20vYWRtaW4vYXVkaXQtc3lzdGVtIjtzOjU6InJvdXRlIjtzOjM0OiJmaWxhbWVudC50ZW5hbnQucGFnZXMuYXVkaXQtc3lzdGVtIjt9czo1MzoibG9naW5fdGVuYW50XzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MTtzOjY6ImxvY2FsZSI7czoyOiJlbiI7czoyMDoicGFzc3dvcmRfaGFzaF90ZW5hbnQiO3M6NjQ6ImE1NDI0NjQ3Mjg4ZGQ5ZWJlOWQ2M2YxMmVhYjA5MGYxY2M3ZWI4NjVlNWI5ZDNmM2Q5MGEwNzlkNTVkM2RiMjciO3M6NjoidGFibGVzIjthOjI6e3M6NDA6IjYyMmRlZjZhOTBlZDA2YTRiOWNlOTNkNzE3OTI3ZWNhX2NvbHVtbnMiO2E6Nzp7aTowO2E6Nzp7czo0OiJ0eXBlIjtzOjY6ImNvbHVtbiI7czo0OiJuYW1lIjtzOjExOiJvY2N1cnJlZF9hdCI7czo1OiJsYWJlbCI7czo1NjM6IjxzcGFuIGNsYXNzPSJmaS1mZi1sYWJlbC13aXRoLWljb24gaW5saW5lLWZsZXggaXRlbXMtY2VudGVyIGdhcC0xLjUiPjxzdmcgY2xhc3M9ImZpLWljb24gZmktc2l6ZS1zbSBmaS1mZi1sYWJlbC1pY29uIHNocmluay0wIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9Im5vbmUiIHZpZXdCb3g9IjAgMCAyNCAyNCIgc3Ryb2tlLXdpZHRoPSIxLjUiIHN0cm9rZT0iY3VycmVudENvbG9yIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZGF0YS1zbG90PSJpY29uIj4KICA8cGF0aCBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGQ9Ik05IDQuNXYxNW02LTE1djE1bS0xMC44NzUgMGgxNS43NWMuNjIxIDAgMS4xMjUtLjUwNCAxLjEyNS0xLjEyNVY1LjYyNWMwLS42MjEtLjUwNC0xLjEyNS0xLjEyNS0xLjEyNUg0LjEyNUMzLjUwNCA0LjUgMyA1LjAwNCAzIDUuNjI1djEyLjc1YzAgLjYyMS41MDQgMS4xMjUgMS4xMjUgMS4xMjVaIi8+Cjwvc3ZnPjxzcGFuIGNsYXNzPSJmaS1mZi1sYWJlbC10ZXh0Ij5PY2N1cnJlZCBBdDwvc3Bhbj48L3NwYW4+IjtzOjg6ImlzSGlkZGVuIjtiOjA7czo5OiJpc1RvZ2dsZWQiO2I6MTtzOjEyOiJpc1RvZ2dsZWFibGUiO2I6MTtzOjI0OiJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiO2I6MDt9aToxO2E6Nzp7czo0OiJ0eXBlIjtzOjY6ImNvbHVtbiI7czo0OiJuYW1lIjtzOjEwOiJldmVudF90eXBlIjtzOjU6ImxhYmVsIjtzOjY3NjoiPHNwYW4gY2xhc3M9ImZpLWZmLWxhYmVsLXdpdGgtaWNvbiBpbmxpbmUtZmxleCBpdGVtcy1jZW50ZXIgZ2FwLTEuNSI+PHN2ZyBjbGFzcz0iZmktaWNvbiBmaS1zaXplLXNtIGZpLWZmLWxhYmVsLWljb24gc2hyaW5rLTAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgZmlsbD0ibm9uZSIgdmlld0JveD0iMCAwIDI0IDI0IiBzdHJva2Utd2lkdGg9IjEuNSIgc3Ryb2tlPSJjdXJyZW50Q29sb3IiIGFyaWEtaGlkZGVuPSJ0cnVlIiBkYXRhLXNsb3Q9Imljb24iPgogIDxwYXRoIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgZD0iTTkuNTY4IDNINS4yNUEyLjI1IDIuMjUgMCAwIDAgMyA1LjI1djQuMzE4YzAgLjU5Ny4yMzcgMS4xNy42NTkgMS41OTFsOS41ODEgOS41ODFjLjY5OS42OTkgMS43OC44NzIgMi42MDcuMzNhMTguMDk1IDE4LjA5NSAwIDAgMCA1LjIyMy01LjIyM2MuNTQyLS44MjcuMzY5LTEuOTA4LS4zMy0yLjYwN0wxMS4xNiAzLjY2QTIuMjUgMi4yNSAwIDAgMCA5LjU2OCAzWiIvPgogIDxwYXRoIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgZD0iTTYgNmguMDA4di4wMDhINlY2WiIvPgo8L3N2Zz48c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtdGV4dCI+RXZlbnQ8L3NwYW4+PC9zcGFuPiI7czo4OiJpc0hpZGRlbiI7YjowO3M6OToiaXNUb2dnbGVkIjtiOjE7czoxMjoiaXNUb2dnbGVhYmxlIjtiOjE7czoyNDoiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjtiOjA7fWk6MjthOjc6e3M6NDoidHlwZSI7czo2OiJjb2x1bW4iO3M6NDoibmFtZSI7czo2OiJkb21haW4iO3M6NToibGFiZWwiO3M6NTU4OiI8c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtd2l0aC1pY29uIGlubGluZS1mbGV4IGl0ZW1zLWNlbnRlciBnYXAtMS41Ij48c3ZnIGNsYXNzPSJmaS1pY29uIGZpLXNpemUtc20gZmktZmYtbGFiZWwtaWNvbiBzaHJpbmstMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBmaWxsPSJub25lIiB2aWV3Qm94PSIwIDAgMjQgMjQiIHN0cm9rZS13aWR0aD0iMS41IiBzdHJva2U9ImN1cnJlbnRDb2xvciIgYXJpYS1oaWRkZW49InRydWUiIGRhdGEtc2xvdD0iaWNvbiI+CiAgPHBhdGggc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBkPSJNOSA0LjV2MTVtNi0xNXYxNW0tMTAuODc1IDBoMTUuNzVjLjYyMSAwIDEuMTI1LS41MDQgMS4xMjUtMS4xMjVWNS42MjVjMC0uNjIxLS41MDQtMS4xMjUtMS4xMjUtMS4xMjVINC4xMjVDMy41MDQgNC41IDMgNS4wMDQgMyA1LjYyNXYxMi43NWMwIC42MjEuNTA0IDEuMTI1IDEuMTI1IDEuMTI1WiIvPgo8L3N2Zz48c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtdGV4dCI+RG9tYWluPC9zcGFuPjwvc3Bhbj4iO3M6ODoiaXNIaWRkZW4iO2I6MDtzOjk6ImlzVG9nZ2xlZCI7YjoxO3M6MTI6ImlzVG9nZ2xlYWJsZSI7YjoxO3M6MjQ6ImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI7YjowO31pOjM7YTo3OntzOjQ6InR5cGUiO3M6NjoiY29sdW1uIjtzOjQ6Im5hbWUiO3M6MjA6Im1lbWJlci5tZW1iZXJfbnVtYmVyIjtzOjU6ImxhYmVsIjtzOjU0NDoiPHNwYW4gY2xhc3M9ImZpLWZmLWxhYmVsLXdpdGgtaWNvbiBpbmxpbmUtZmxleCBpdGVtcy1jZW50ZXIgZ2FwLTEuNSI+PHN2ZyBjbGFzcz0iZmktaWNvbiBmaS1zaXplLXNtIGZpLWZmLWxhYmVsLWljb24gc2hyaW5rLTAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgZmlsbD0ibm9uZSIgdmlld0JveD0iMCAwIDI0IDI0IiBzdHJva2Utd2lkdGg9IjEuNSIgc3Ryb2tlPSJjdXJyZW50Q29sb3IiIGFyaWEtaGlkZGVuPSJ0cnVlIiBkYXRhLXNsb3Q9Imljb24iPgogIDxwYXRoIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgZD0iTTE1Ljc1IDZhMy43NSAzLjc1IDAgMSAxLTcuNSAwIDMuNzUgMy43NSAwIDAgMSA3LjUgMFpNNC41MDEgMjAuMTE4YTcuNSA3LjUgMCAwIDEgMTQuOTk4IDBBMTcuOTMzIDE3LjkzMyAwIDAgMSAxMiAyMS43NWMtMi42NzYgMC01LjIxNi0uNTg0LTcuNDk5LTEuNjMyWiIvPgo8L3N2Zz48c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtdGV4dCI+TWVtYmVyICM8L3NwYW4+PC9zcGFuPiI7czo4OiJpc0hpZGRlbiI7YjowO3M6OToiaXNUb2dnbGVkIjtiOjE7czoxMjoiaXNUb2dnbGVhYmxlIjtiOjE7czoyNDoiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjtiOjA7fWk6NDthOjc6e3M6NDoidHlwZSI7czo2OiJjb2x1bW4iO3M6NDoibmFtZSI7czoxMToibWVtYmVyLm5hbWUiO3M6NToibGFiZWwiO3M6NTQyOiI8c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtd2l0aC1pY29uIGlubGluZS1mbGV4IGl0ZW1zLWNlbnRlciBnYXAtMS41Ij48c3ZnIGNsYXNzPSJmaS1pY29uIGZpLXNpemUtc20gZmktZmYtbGFiZWwtaWNvbiBzaHJpbmstMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBmaWxsPSJub25lIiB2aWV3Qm94PSIwIDAgMjQgMjQiIHN0cm9rZS13aWR0aD0iMS41IiBzdHJva2U9ImN1cnJlbnRDb2xvciIgYXJpYS1oaWRkZW49InRydWUiIGRhdGEtc2xvdD0iaWNvbiI+CiAgPHBhdGggc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBkPSJNMTUuNzUgNmEzLjc1IDMuNzUgMCAxIDEtNy41IDAgMy43NSAzLjc1IDAgMCAxIDcuNSAwWk00LjUwMSAyMC4xMThhNy41IDcuNSAwIDAgMSAxNC45OTggMEExNy45MzMgMTcuOTMzIDAgMCAxIDEyIDIxLjc1Yy0yLjY3NiAwLTUuMjE2LS41ODQtNy40OTktMS42MzJaIi8+Cjwvc3ZnPjxzcGFuIGNsYXNzPSJmaS1mZi1sYWJlbC10ZXh0Ij5NZW1iZXI8L3NwYW4+PC9zcGFuPiI7czo4OiJpc0hpZGRlbiI7YjowO3M6OToiaXNUb2dnbGVkIjtiOjE7czoxMjoiaXNUb2dnbGVhYmxlIjtiOjE7czoyNDoiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjtiOjA7fWk6NTthOjc6e3M6NDoidHlwZSI7czo2OiJjb2x1bW4iO3M6NDoibmFtZSI7czoxMzoib3BlcmF0b3IubmFtZSI7czo1OiJsYWJlbCI7czo1NDQ6IjxzcGFuIGNsYXNzPSJmaS1mZi1sYWJlbC13aXRoLWljb24gaW5saW5lLWZsZXggaXRlbXMtY2VudGVyIGdhcC0xLjUiPjxzdmcgY2xhc3M9ImZpLWljb24gZmktc2l6ZS1zbSBmaS1mZi1sYWJlbC1pY29uIHNocmluay0wIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9Im5vbmUiIHZpZXdCb3g9IjAgMCAyNCAyNCIgc3Ryb2tlLXdpZHRoPSIxLjUiIHN0cm9rZT0iY3VycmVudENvbG9yIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZGF0YS1zbG90PSJpY29uIj4KICA8cGF0aCBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGQ9Ik0xNS43NSA2YTMuNzUgMy43NSAwIDEgMS03LjUgMCAzLjc1IDMuNzUgMCAwIDEgNy41IDBaTTQuNTAxIDIwLjExOGE3LjUgNy41IDAgMCAxIDE0Ljk5OCAwQTE3LjkzMyAxNy45MzMgMCAwIDEgMTIgMjEuNzVjLTIuNjc2IDAtNS4yMTYtLjU4NC03LjQ5OS0xLjYzMloiLz4KPC9zdmc+PHNwYW4gY2xhc3M9ImZpLWZmLWxhYmVsLXRleHQiPk9wZXJhdG9yPC9zcGFuPjwvc3Bhbj4iO3M6ODoiaXNIaWRkZW4iO2I6MDtzOjk6ImlzVG9nZ2xlZCI7YjoxO3M6MTI6ImlzVG9nZ2xlYWJsZSI7YjoxO3M6MjQ6ImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI7YjowO31pOjY7YTo3OntzOjQ6InR5cGUiO3M6NjoiY29sdW1uIjtzOjQ6Im5hbWUiO3M6ODoiY2hlY2tzdW0iO3M6NToibGFiZWwiO3M6NTYwOiI8c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtd2l0aC1pY29uIGlubGluZS1mbGV4IGl0ZW1zLWNlbnRlciBnYXAtMS41Ij48c3ZnIGNsYXNzPSJmaS1pY29uIGZpLXNpemUtc20gZmktZmYtbGFiZWwtaWNvbiBzaHJpbmstMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBmaWxsPSJub25lIiB2aWV3Qm94PSIwIDAgMjQgMjQiIHN0cm9rZS13aWR0aD0iMS41IiBzdHJva2U9ImN1cnJlbnRDb2xvciIgYXJpYS1oaWRkZW49InRydWUiIGRhdGEtc2xvdD0iaWNvbiI+CiAgPHBhdGggc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBkPSJNOSA0LjV2MTVtNi0xNXYxNW0tMTAuODc1IDBoMTUuNzVjLjYyMSAwIDEuMTI1LS41MDQgMS4xMjUtMS4xMjVWNS42MjVjMC0uNjIxLS41MDQtMS4xMjUtMS4xMjUtMS4xMjVINC4xMjVDMy41MDQgNC41IDMgNS4wMDQgMyA1LjYyNXYxMi43NWMwIC42MjEuNTA0IDEuMTI1IDEuMTI1IDEuMTI1WiIvPgo8L3N2Zz48c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtdGV4dCI+Q2hlY2tzdW08L3NwYW4+PC9zcGFuPiI7czo4OiJpc0hpZGRlbiI7YjowO3M6OToiaXNUb2dnbGVkIjtiOjA7czoxMjoiaXNUb2dnbGVhYmxlIjtiOjE7czoyNDoiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjtiOjE7fX1zOjQwOiI5MTE5N2E5NWVmNGJiZjJhMzY4YWNhYTAxNDJlYmEwMl9jb2x1bW5zIjthOjU6e2k6MDthOjc6e3M6NDoidHlwZSI7czo2OiJjb2x1bW4iO3M6NDoibmFtZSI7czo4OiJmaWxlbmFtZSI7czo1OiJsYWJlbCI7czo2NTA6IjxzcGFuIGNsYXNzPSJmaS1mZi1sYWJlbC13aXRoLWljb24gaW5saW5lLWZsZXggaXRlbXMtY2VudGVyIGdhcC0xLjUiPjxzdmcgY2xhc3M9ImZpLWljb24gZmktc2l6ZS1zbSBmaS1mZi1sYWJlbC1pY29uIHNocmluay0wIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9Im5vbmUiIHZpZXdCb3g9IjAgMCAyNCAyNCIgc3Ryb2tlLXdpZHRoPSIxLjUiIHN0cm9rZT0iY3VycmVudENvbG9yIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZGF0YS1zbG90PSJpY29uIj4KICA8cGF0aCBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGQ9Ik0xOS41IDE0LjI1di0yLjYyNWEzLjM3NSAzLjM3NSAwIDAgMC0zLjM3NS0zLjM3NWgtMS41QTEuMTI1IDEuMTI1IDAgMCAxIDEzLjUgNy4xMjV2LTEuNWEzLjM3NSAzLjM3NSAwIDAgMC0zLjM3NS0zLjM3NUg4LjI1bTIuMjUgMEg1LjYyNWMtLjYyMSAwLTEuMTI1LjUwNC0xLjEyNSAxLjEyNXYxNy4yNWMwIC42MjEuNTA0IDEuMTI1IDEuMTI1IDEuMTI1aDEyLjc1Yy42MjEgMCAxLjEyNS0uNTA0IDEuMTI1LTEuMTI1VjExLjI1YTkgOSAwIDAgMC05LTlaIi8+Cjwvc3ZnPjxzcGFuIGNsYXNzPSJmaS1mZi1sYWJlbC10ZXh0Ij5GaWxlbmFtZTwvc3Bhbj48L3NwYW4+IjtzOjg6ImlzSGlkZGVuIjtiOjA7czo5OiJpc1RvZ2dsZWQiO2I6MTtzOjEyOiJpc1RvZ2dsZWFibGUiO2I6MTtzOjI0OiJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiO2I6MDt9aToxO2E6Nzp7czo0OiJ0eXBlIjtzOjY6ImNvbHVtbiI7czo0OiJuYW1lIjtzOjEwOiJzaXplX2J5dGVzIjtzOjU6ImxhYmVsIjtzOjU1NjoiPHNwYW4gY2xhc3M9ImZpLWZmLWxhYmVsLXdpdGgtaWNvbiBpbmxpbmUtZmxleCBpdGVtcy1jZW50ZXIgZ2FwLTEuNSI+PHN2ZyBjbGFzcz0iZmktaWNvbiBmaS1zaXplLXNtIGZpLWZmLWxhYmVsLWljb24gc2hyaW5rLTAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgZmlsbD0ibm9uZSIgdmlld0JveD0iMCAwIDI0IDI0IiBzdHJva2Utd2lkdGg9IjEuNSIgc3Ryb2tlPSJjdXJyZW50Q29sb3IiIGFyaWEtaGlkZGVuPSJ0cnVlIiBkYXRhLXNsb3Q9Imljb24iPgogIDxwYXRoIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgZD0iTTkgNC41djE1bTYtMTV2MTVtLTEwLjg3NSAwaDE1Ljc1Yy42MjEgMCAxLjEyNS0uNTA0IDEuMTI1LTEuMTI1VjUuNjI1YzAtLjYyMS0uNTA0LTEuMTI1LTEuMTI1LTEuMTI1SDQuMTI1QzMuNTA0IDQuNSAzIDUuMDA0IDMgNS42MjV2MTIuNzVjMCAuNjIxLjUwNCAxLjEyNSAxLjEyNSAxLjEyNVoiLz4KPC9zdmc+PHNwYW4gY2xhc3M9ImZpLWZmLWxhYmVsLXRleHQiPlNpemU8L3NwYW4+PC9zcGFuPiI7czo4OiJpc0hpZGRlbiI7YjowO3M6OToiaXNUb2dnbGVkIjtiOjE7czoxMjoiaXNUb2dnbGVhYmxlIjtiOjE7czoyNDoiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjtiOjA7fWk6MjthOjc6e3M6NDoidHlwZSI7czo2OiJjb2x1bW4iO3M6NDoibmFtZSI7czo2OiJkcml2ZXIiO3M6NToibGFiZWwiO3M6NTU4OiI8c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtd2l0aC1pY29uIGlubGluZS1mbGV4IGl0ZW1zLWNlbnRlciBnYXAtMS41Ij48c3ZnIGNsYXNzPSJmaS1pY29uIGZpLXNpemUtc20gZmktZmYtbGFiZWwtaWNvbiBzaHJpbmstMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBmaWxsPSJub25lIiB2aWV3Qm94PSIwIDAgMjQgMjQiIHN0cm9rZS13aWR0aD0iMS41IiBzdHJva2U9ImN1cnJlbnRDb2xvciIgYXJpYS1oaWRkZW49InRydWUiIGRhdGEtc2xvdD0iaWNvbiI+CiAgPHBhdGggc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBkPSJNOSA0LjV2MTVtNi0xNXYxNW0tMTAuODc1IDBoMTUuNzVjLjYyMSAwIDEuMTI1LS41MDQgMS4xMjUtMS4xMjVWNS42MjVjMC0uNjIxLS41MDQtMS4xMjUtMS4xMjUtMS4xMjVINC4xMjVDMy41MDQgNC41IDMgNS4wMDQgMyA1LjYyNXYxMi43NWMwIC42MjEuNTA0IDEuMTI1IDEuMTI1IDEuMTI1WiIvPgo8L3N2Zz48c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtdGV4dCI+RHJpdmVyPC9zcGFuPjwvc3Bhbj4iO3M6ODoiaXNIaWRkZW4iO2I6MDtzOjk6ImlzVG9nZ2xlZCI7YjoxO3M6MTI6ImlzVG9nZ2xlYWJsZSI7YjoxO3M6MjQ6ImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI7YjowO31pOjM7YTo3OntzOjQ6InR5cGUiO3M6NjoiY29sdW1uIjtzOjQ6Im5hbWUiO3M6MTA6ImNyZWF0ZWRfYXQiO3M6NToibGFiZWwiO3M6NTYyOiI8c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtd2l0aC1pY29uIGlubGluZS1mbGV4IGl0ZW1zLWNlbnRlciBnYXAtMS41Ij48c3ZnIGNsYXNzPSJmaS1pY29uIGZpLXNpemUtc20gZmktZmYtbGFiZWwtaWNvbiBzaHJpbmstMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBmaWxsPSJub25lIiB2aWV3Qm94PSIwIDAgMjQgMjQiIHN0cm9rZS13aWR0aD0iMS41IiBzdHJva2U9ImN1cnJlbnRDb2xvciIgYXJpYS1oaWRkZW49InRydWUiIGRhdGEtc2xvdD0iaWNvbiI+CiAgPHBhdGggc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIiBkPSJNOSA0LjV2MTVtNi0xNXYxNW0tMTAuODc1IDBoMTUuNzVjLjYyMSAwIDEuMTI1LS41MDQgMS4xMjUtMS4xMjVWNS42MjVjMC0uNjIxLS41MDQtMS4xMjUtMS4xMjUtMS4xMjVINC4xMjVDMy41MDQgNC41IDMgNS4wMDQgMyA1LjYyNXYxMi43NWMwIC42MjEuNTA0IDEuMTI1IDEuMTI1IDEuMTI1WiIvPgo8L3N2Zz48c3BhbiBjbGFzcz0iZmktZmYtbGFiZWwtdGV4dCI+Q3JlYXRlZCBBdDwvc3Bhbj48L3NwYW4+IjtzOjg6ImlzSGlkZGVuIjtiOjA7czo5OiJpc1RvZ2dsZWQiO2I6MTtzOjEyOiJpc1RvZ2dsZWFibGUiO2I6MTtzOjI0OiJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiO2I6MDt9aTo0O2E6Nzp7czo0OiJ0eXBlIjtzOjY6ImNvbHVtbiI7czo0OiJuYW1lIjtzOjk6InVzZXIubmFtZSI7czo1OiJsYWJlbCI7czo1NDY6IjxzcGFuIGNsYXNzPSJmaS1mZi1sYWJlbC13aXRoLWljb24gaW5saW5lLWZsZXggaXRlbXMtY2VudGVyIGdhcC0xLjUiPjxzdmcgY2xhc3M9ImZpLWljb24gZmktc2l6ZS1zbSBmaS1mZi1sYWJlbC1pY29uIHNocmluay0wIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9Im5vbmUiIHZpZXdCb3g9IjAgMCAyNCAyNCIgc3Ryb2tlLXdpZHRoPSIxLjUiIHN0cm9rZT0iY3VycmVudENvbG9yIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZGF0YS1zbG90PSJpY29uIj4KICA8cGF0aCBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiIGQ9Ik0xNS43NSA2YTMuNzUgMy43NSAwIDEgMS03LjUgMCAzLjc1IDMuNzUgMCAwIDEgNy41IDBaTTQuNTAxIDIwLjExOGE3LjUgNy41IDAgMCAxIDE0Ljk5OCAwQTE3LjkzMyAxNy45MzMgMCAwIDEgMTIgMjEuNzVjLTIuNjc2IDAtNS4yMTYtLjU4NC03LjQ5OS0xLjYzMloiLz4KPC9zdmc+PHNwYW4gY2xhc3M9ImZpLWZmLWxhYmVsLXRleHQiPkNyZWF0ZWQgQnk8L3NwYW4+PC9zcGFuPiI7czo4OiJpc0hpZGRlbiI7YjowO3M6OToiaXNUb2dnbGVkIjtiOjE7czoxMjoiaXNUb2dnbGVhYmxlIjtiOjE7czoyNDoiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjtiOjA7fX19czo2OiJ0ZW5hbnQiO2E6MTp7czoxMToiYWR2YW5jZWRfdWkiO2I6MTt9fQ==',1784879758);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(255) NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_group_key_unique` (`group`,`key`),
  KEY `settings_group_index` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=154 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES
(1,'general','currency','SAR','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(2,'general','fund_name','Samman Family Fund','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(3,'contribution','cycle_start_day','6','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(4,'automation','auto_accept_deposits','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(5,'automation','auto_apply_collections','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(6,'automation','contribution_due_notify_days','0,7,14,21','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(7,'automation','contribution_due_notify_time','09:00','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(8,'automation','loan_due_notify_days','0,7,14,21','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(9,'automation','loan_due_notify_time','09:00','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(10,'automation','contribution_apply_times','06:00','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(11,'automation','loan_apply_times','06:00','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(12,'automation','month_boundary_day','6','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(13,'delinquency','consecutive_miss_threshold','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(14,'delinquency','total_miss_threshold','15','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(15,'delinquency','total_miss_lookback_months','60','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(16,'late_fee','contribution_day_10','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(17,'late_fee','contribution_day_20','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(18,'late_fee','contribution_day_30','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(19,'late_fee','repayment_day_10','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(20,'late_fee','repayment_day_20','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(21,'late_fee','repayment_day_30','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(22,'late_fee','contribution_day_1','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(23,'late_fee','repayment_day_1','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(24,'subscription','annual_fee','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(25,'collection','late_fee_reminder_days','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(26,'collection','late_fee_tier_1_day','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(27,'collection','late_fee_tier_2_day','10','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(28,'collection','late_fee_tier_3_day','20','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(29,'collection','late_fee_tier_4_day','30','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(30,'collection','late_fee_model','replacement','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(31,'collection','recon_tolerance','0.01','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(32,'collection','bank_match_date_range_days','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(33,'collection','bank_match_manual_date_range_days','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(34,'collection','stale_pending_days','30','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(35,'collection','cash_deposit_unbanked_days','14','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(36,'collection','timing_diff_defer_hours','24','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(37,'collection','timing_diff_escalate_hours','48','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(38,'loan','eligibility_months','12','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(39,'loan','min_fund_balance','6000','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(40,'loan','max_borrow_multiplier','2','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(41,'loan','default_interest_rate','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(42,'loan','default_term_months','12','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(43,'loan','max_loan_amount','300000','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(44,'loan','settlement_threshold_pct','0.2','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(45,'loan','default_grace_cycles','2','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(46,'loan','max_allowed_grace_cycles','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(47,'loan','guarantor_transfer_missed_threshold','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(48,'loan','max_active_loans','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(49,'loan','require_guarantor_above_fund_balance','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(50,'loan','member_funding_split_pct','50','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(51,'loan','allow_funding_strategy_member_topup','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(52,'loan','allow_funding_strategy_split_percentage','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(53,'loan','allow_excess_fund_cash_out','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(54,'loan','auto_allocate_loan_repayment','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(55,'loan','late_payment_consecutive_threshold','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(56,'loan','late_payment_rolling_threshold','15','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(57,'loan','late_payment_lookback_months','60','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(58,'loan_queue_projection','queued_demand_scope','within_tier','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(59,'loan_queue_projection','pending_demand_scope','within_tier','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(60,'loan_queue_projection','include_open_period_contributions','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(61,'loan_queue_projection','include_contribution_arrears','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(62,'loan_queue_projection','emi_forecast_months','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(63,'loan_queue_projection','use_forward_inflow','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(64,'loan_queue_projection','use_historical_inflow','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(65,'loan_queue_projection','historical_lookback_months','3','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(66,'loan_queue_projection','apply_tier_allocation_percent','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(67,'loan_queue_projection','max_months_display','6','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(68,'localization','default_admin_locale','en','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(69,'localization','default_member_locale','ar','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(70,'ledger','show_manual_credit_debit','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(71,'ledger','show_split_reverse','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(72,'ledger','show_edit_delete','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(73,'member_number','format','sequential','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(74,'member_number','prefix','MEM','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(75,'member_number','separator','-','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(76,'member_number','padding','4','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(77,'member_number','include_year','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(78,'fiscal','fiscal_year_start_month','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(79,'fiscal','fiscal_year_start_day','1','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(80,'fiscal','purge_policy','archive_then_delete','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(81,'fiscal','current_fiscal_year_label','FY2026','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(82,'public','fund_name_en','Samman Family Fund','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(83,'public','fund_name_ar','صندوق عائلة آل سمان','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(84,'public','fund_logo','','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(85,'public','membership_no_limit','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(86,'public','membership_max_members','100','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(87,'public','fee_new','150','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(88,'public','fee_resume','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(89,'public','fee_renew','0','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(90,'public','rules_and_conditions_url','','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(91,'public','membership_application_document_url','','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(92,'public','fee_transfer_bank_name','Al Rajhi Bank','2026-07-24 07:52:02','2026-07-24 07:52:02'),
(93,'public','fee_transfer_iban','SA761234560000123101','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(94,'public','contact_email','admin@fundflow.sa','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(95,'public','contact_phone','+966 557744668','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(96,'public','arabic_display_font','noto_sans','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(97,'public','arabic_enhanced_name_style','0','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(98,'statement','brand_name','Samman Family Fund','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(99,'statement','tagline','Member fund statement','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(100,'statement','accent_color','#059669','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(101,'statement','footer_disclaimer','Computer-generated statement. Confidential.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(102,'statement','signature_line','Fund administration','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(103,'statement','auto_email','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(104,'statement','attach_pdf','0','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(105,'statement','include_transactions','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(106,'statement','include_loan_section','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(107,'statement','include_compliance','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(108,'statement','font_en','dejavu_sans','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(109,'statement','font_ar','dejavu_sans','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(110,'communication','in_app_enabled','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(111,'communication','email_enabled','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(112,'communication_brand','from_name',NULL,'2026-07-24 07:52:03','2026-07-24 07:52:03'),
(113,'communication_brand','primary_color','#0f766e','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(114,'communication_brand','footer_en','This message was sent by your fund administration.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(115,'communication_brand','footer_ar','أُرسلت هذه الرسالة من إدارة الصندوق.','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(116,'communication_brand','logo_path',NULL,'2026-07-24 07:52:03','2026-07-24 07:52:03'),
(117,'push_events','contribution_due','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(118,'push_events','contribution_posted','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(119,'push_events','contribution_late_fee','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(120,'push_events','fund_posting_accepted','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(121,'push_events','fund_posting_rejected','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(122,'push_events','fund_posting_bank_cleared','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(123,'push_events','member_direct_message','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(124,'push_events','member_announcement','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(125,'push_events','loan_repayment_due','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(126,'push_events','dependent_allocation_changed','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(127,'push_events','monthly_statement','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(128,'push_events','membership_approved','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(129,'push_events','member_onboarding_greeting','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(130,'push_events','membership_rejected','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(131,'push_events','generic_member_alert','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(132,'push_events','reconciliation_digest','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(133,'push_events','delinquency_digest','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(134,'push_events','reconciliation_exception','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(135,'push_events','new_loan_application','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(136,'push_events','new_fund_posting','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(137,'push_events','new_cash_out_request','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(138,'push_events','new_membership_application','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(139,'push_events','new_support_request','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(140,'push_events','new_member_request','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(141,'push_events','new_loan_eligibility_override','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(142,'push_events','loan_guarantor_transfer_admin','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(143,'push_events','admin_direct_message','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(144,'notifications','sms_enabled','0','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(145,'notifications','whatsapp_enabled','0','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(146,'notifications','twilio_account_sid','','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(147,'notifications','twilio_auth_token','','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(148,'notifications','twilio_sms_from','','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(149,'notifications','twilio_whatsapp_from','','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(150,'reconciliation','digest_push_enabled','1','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(151,'reconciliation','bank_variance_critical','0','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(152,'reconciliation','bank_statement_balance','','2026-07-24 07:52:03','2026-07-24 07:52:03'),
(153,'reconciliation','bank_statement_date','','2026-07-24 07:52:03','2026-07-24 07:52:03');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sms_import_sessions`
--

DROP TABLE IF EXISTS `sms_import_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_import_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(255) DEFAULT NULL,
  `template_id` bigint(20) unsigned NOT NULL,
  `imported_by` bigint(20) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `total_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `imported_count` int(10) unsigned NOT NULL DEFAULT 0,
  `duplicate_count` int(10) unsigned NOT NULL DEFAULT 0,
  `error_count` int(10) unsigned NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `error_log` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`error_log`)),
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sms_import_sessions_template_id_foreign` (`template_id`),
  KEY `sms_import_sessions_imported_by_foreign` (`imported_by`),
  CONSTRAINT `sms_import_sessions_imported_by_foreign` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`),
  CONSTRAINT `sms_import_sessions_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `sms_import_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_import_sessions`
--

LOCK TABLES `sms_import_sessions` WRITE;
/*!40000 ALTER TABLE `sms_import_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sms_import_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sms_import_templates`
--

DROP TABLE IF EXISTS `sms_import_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_import_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(255) DEFAULT NULL COMMENT 'Optional bank label for duplicate scoping',
  `name` varchar(255) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `delimiter` varchar(255) NOT NULL DEFAULT ',',
  `encoding` varchar(255) NOT NULL DEFAULT 'UTF-8',
  `has_header` tinyint(1) NOT NULL DEFAULT 1,
  `skip_rows` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sms_column` varchar(255) NOT NULL,
  `date_column` varchar(255) DEFAULT NULL,
  `date_format` varchar(255) NOT NULL DEFAULT 'Y-m-d H:i:s',
  `amount_pattern` varchar(255) DEFAULT NULL,
  `date_pattern` varchar(255) DEFAULT NULL,
  `date_pattern_format` varchar(255) DEFAULT NULL,
  `reference_pattern` varchar(255) DEFAULT NULL,
  `credit_keywords` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`credit_keywords`)),
  `debit_keywords` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`debit_keywords`)),
  `default_transaction_type` varchar(255) NOT NULL DEFAULT 'credit',
  `duplicate_match_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`duplicate_match_fields`)),
  `duplicate_date_tolerance` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `member_match_pattern` varchar(255) DEFAULT NULL,
  `member_match_field` varchar(255) DEFAULT 'member_number',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_import_templates`
--

LOCK TABLES `sms_import_templates` WRITE;
/*!40000 ALTER TABLE `sms_import_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `sms_import_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sms_transactions`
--

DROP TABLE IF EXISTS `sms_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(255) DEFAULT NULL,
  `import_session_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `transaction_type` varchar(255) NOT NULL DEFAULT 'credit',
  `reference` varchar(255) DEFAULT NULL,
  `raw_sms` text NOT NULL,
  `raw_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_data`)),
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `is_duplicate` tinyint(1) NOT NULL DEFAULT 0,
  `duplicate_of_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sms_transactions_import_session_id_foreign` (`import_session_id`),
  KEY `sms_transactions_member_id_foreign` (`member_id`),
  KEY `sms_transactions_posted_by_foreign` (`posted_by`),
  KEY `sms_transactions_duplicate_of_id_foreign` (`duplicate_of_id`),
  KEY `sms_transactions_bank_name_transaction_date_index` (`bank_name`,`transaction_date`),
  KEY `sms_transactions_bank_name_reference_index` (`bank_name`,`reference`),
  KEY `sms_transactions_is_duplicate_index` (`is_duplicate`),
  KEY `sms_transactions_posted_at_index` (`posted_at`),
  CONSTRAINT `sms_transactions_duplicate_of_id_foreign` FOREIGN KEY (`duplicate_of_id`) REFERENCES `sms_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sms_transactions_import_session_id_foreign` FOREIGN KEY (`import_session_id`) REFERENCES `sms_import_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sms_transactions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sms_transactions_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_transactions`
--

LOCK TABLES `sms_transactions` WRITE;
/*!40000 ALTER TABLE `sms_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sms_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_request_replies`
--

DROP TABLE IF EXISTS `support_request_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_request_replies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `support_request_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_request_replies_user_id_foreign` (`user_id`),
  KEY `support_request_replies_support_request_id_created_at_index` (`support_request_id`,`created_at`),
  CONSTRAINT `support_request_replies_support_request_id_foreign` FOREIGN KEY (`support_request_id`) REFERENCES `support_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_request_replies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_request_replies`
--

LOCK TABLES `support_request_replies` WRITE;
/*!40000 ALTER TABLE `support_request_replies` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_request_replies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_requests`
--

DROP TABLE IF EXISTS `support_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(64) NOT NULL,
  `subject` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `escalated_at` timestamp NULL DEFAULT NULL,
  `assigned_to_user_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_requests_user_id_foreign` (`user_id`),
  KEY `support_requests_member_id_foreign` (`member_id`),
  KEY `support_requests_created_at_index` (`created_at`),
  KEY `support_requests_category_index` (`category`),
  KEY `support_requests_assigned_to_user_id_foreign` (`assigned_to_user_id`),
  KEY `support_requests_status_index` (`status`),
  CONSTRAINT `support_requests_assigned_to_user_id_foreign` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_requests_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_requests`
--

LOCK TABLES `support_requests` WRITE;
/*!40000 ALTER TABLE `support_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_job_runs`
--

DROP TABLE IF EXISTS `system_job_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_job_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_key` varchar(64) NOT NULL,
  `command` varchar(128) NOT NULL,
  `trigger` varchar(24) NOT NULL DEFAULT 'manual',
  `status` varchar(24) NOT NULL DEFAULT 'running',
  `exit_code` smallint(5) unsigned DEFAULT NULL,
  `started_at` timestamp NOT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `triggered_by` bigint(20) unsigned DEFAULT NULL,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`summary`)),
  `output` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_job_runs_triggered_by_foreign` (`triggered_by`),
  KEY `system_job_runs_job_key_started_at_index` (`job_key`,`started_at`),
  KEY `system_job_runs_status_started_at_index` (`status`,`started_at`),
  CONSTRAINT `system_job_runs_triggered_by_foreign` FOREIGN KEY (`triggered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_job_runs`
--

LOCK TABLES `system_job_runs` WRITE;
/*!40000 ALTER TABLE `system_job_runs` DISABLE KEYS */;
INSERT INTO `system_job_runs` VALUES
(1,'announcements:dispatch-scheduled','announcements:dispatch-scheduled','schedule','success',0,'2026-07-24 07:52:05','2026-07-24 07:52:05',11,NULL,'{\"exit_code\":0}',NULL,'2026-07-24 07:52:05','2026-07-24 07:52:05'),
(2,'announcements:dispatch-scheduled','announcements:dispatch-scheduled','schedule','success',0,'2026-07-24 07:53:04','2026-07-24 07:53:04',25,NULL,'{\"exit_code\":0}',NULL,'2026-07-24 07:53:04','2026-07-24 07:53:04'),
(3,'announcements:dispatch-scheduled','announcements:dispatch-scheduled','schedule','success',0,'2026-07-24 07:54:04','2026-07-24 07:54:04',19,NULL,'{\"exit_code\":0}',NULL,'2026-07-24 07:54:04','2026-07-24 07:54:04'),
(4,'announcements:dispatch-scheduled','announcements:dispatch-scheduled','schedule','success',0,'2026-07-24 07:55:05','2026-07-24 07:55:05',23,NULL,'{\"exit_code\":0}',NULL,'2026-07-24 07:55:05','2026-07-24 07:55:05');
/*!40000 ALTER TABLE `system_job_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `type` enum('credit','debit') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `reference_type` varchar(255) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `transacted_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transactions_account_id_foreign` (`account_id`),
  KEY `transactions_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  KEY `transactions_transacted_at_index` (`transacted_at`),
  KEY `transactions_member_id_index` (`member_id`),
  CONSTRAINT `transactions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `preferred_locale` varchar(5) DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `users_email_index` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Fund Admin','admin@fundflow.sa',NULL,NULL,NULL,1,'2026-07-24 07:52:03','$2y$12$kDjKx6HF7zlvZ15lIdVu2OGh3nKgoaWJxATRZARuhnc3kwKu6gC86',NULL,'2026-07-24 07:52:04','2026-07-24 07:52:04');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'tenantsamman-'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-24 10:56:04
