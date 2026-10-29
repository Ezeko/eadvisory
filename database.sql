-- ============================================================================
-- E'lique Advisory - Contact Enquiries Database Schema (cPanel / phpMyAdmin)
-- ============================================================================
-- Instructions for phpMyAdmin on cPanel:
-- 1. Open cPanel -> MySQL Databases and create a database & user (grant ALL PRIVILEGES).
-- 2. Open cPanel -> phpMyAdmin, select your database on the left sidebar.
-- 3. Click the "Import" or "SQL" tab and run this script.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `enquiries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `company` VARCHAR(191) NOT NULL,
    `country` VARCHAR(100) NOT NULL,
    `enquiry_type` VARCHAR(100) NOT NULL,
    `message` TEXT NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_email` (`email`),
    INDEX `idx_enquiry_type` (`enquiry_type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
