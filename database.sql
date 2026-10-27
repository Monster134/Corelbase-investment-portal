-- =============================================================================
-- CORELBASE PRIVATE LIMITED — INVESTMENT & INVESTOR MANAGEMENT PORTAL
-- Complete MySQL 8+ / MariaDB Production Schema (utf8mb4)
-- Timezone: UTC storage, Asia/Kolkata presentation
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1. Table: users (Investor Portal Login Accounts)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `investor_code` VARCHAR(32) NOT NULL COMMENT 'Matches CB-2026-XXXXXX',
    `email` VARCHAR(191) NOT NULL,
    `mobile` VARCHAR(20) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `last_login_ip` VARCHAR(45) NULL DEFAULT NULL,
    `reset_token_hash` VARCHAR(255) NULL DEFAULT NULL,
    `reset_expires_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`),
    UNIQUE KEY `uk_users_investor_code` (`investor_code`),
    KEY `idx_users_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Table: investors (Master Investor Profile & Encrypted KYC Storage)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `investors` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `investor_id` VARCHAR(32) NOT NULL COMMENT 'Unique ID e.g. CB-2026-000001',
    `full_name` VARCHAR(160) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `mobile` VARCHAR(20) NOT NULL,
    `dob` DATE NOT NULL,
    `address` TEXT NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `state` VARCHAR(100) NOT NULL,
    `pincode` VARCHAR(15) NOT NULL,
    `country` VARCHAR(100) NOT NULL DEFAULT 'India',
    `pan_encrypted` TEXT NOT NULL COMMENT 'AES-256-GCM encrypted PAN',
    `pan_masked` VARCHAR(20) NOT NULL COMMENT 'Masked format: ABCDE****F',
    `pan_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 HMAC for exact duplicate lookup',
    `aadhaar_encrypted` TEXT NOT NULL COMMENT 'AES-256-GCM encrypted Aadhaar',
    `aadhaar_masked` VARCHAR(20) NOT NULL COMMENT 'Masked format: ********1234',
    `aadhaar_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 HMAC for exact duplicate lookup',
    `kyc_status` ENUM('PENDING','VERIFIED','FLAGGED','REJECTED') NOT NULL DEFAULT 'PENDING',
    `is_duplicate_flagged` TINYINT(1) NOT NULL DEFAULT 0,
    `duplicate_notes` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_investors_investor_id` (`investor_id`),
    KEY `idx_investors_user_id` (`user_id`),
    KEY `idx_investors_email` (`email`),
    KEY `idx_investors_mobile` (`mobile`),
    KEY `idx_investors_pan_hash` (`pan_hash`),
    KEY `idx_investors_aadhaar_hash` (`aadhaar_hash`),
    KEY `idx_investors_created_at` (`created_at`),
    CONSTRAINT `fk_investors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Table: applications (Investment Applications & Strict Workflow State)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `applications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` VARCHAR(32) NOT NULL COMMENT 'Format: CB-2026-000001',
    `investor_ref_id` BIGINT UNSIGNED NOT NULL,
    `investor_id` VARCHAR(32) NOT NULL,
    `application_reference` VARCHAR(64) NOT NULL COMMENT 'Unique cryptographic reference token',
    `investment_amount` DECIMAL(15,2) NOT NULL,
    `indicative_shares` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `indicative_ownership_pct` DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
    `configured_valuation` DECIMAL(18,2) NOT NULL,
    `configured_issue_price` DECIMAL(12,2) NOT NULL,
    `configured_face_value` DECIMAL(12,2) NOT NULL,
    `configured_premium` DECIMAL(12,2) NOT NULL,
    `status` ENUM(
        'SUBMITTED',
        'PAYMENT UNDER VERIFICATION',
        'PAYMENT VERIFIED',
        'RE-UPLOAD REQUESTED',
        'REJECTED',
        'DUPLICATE',
        'APPROVED',
        'CANCELLED',
        'SHARES ALLOTTED',
        'CERTIFICATE GENERATED'
    ) NOT NULL DEFAULT 'PAYMENT UNDER VERIFICATION',
    `is_potential_duplicate` TINYINT(1) NOT NULL DEFAULT 0,
    `duplicate_reason` TEXT NULL DEFAULT NULL,
    `declaration_accepted` TINYINT(1) NOT NULL DEFAULT 1,
    `admin_notes` TEXT NULL DEFAULT NULL,
    `rejection_reason` TEXT NULL DEFAULT NULL,
    `reupload_reason` TEXT NULL DEFAULT NULL,
    `reupload_token` VARCHAR(128) NULL DEFAULT NULL,
    `submitted_ip` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `verified_by_admin_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `verified_at` DATETIME NULL DEFAULT NULL,
    `approved_by_admin_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `approved_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_applications_application_id` (`application_id`),
    UNIQUE KEY `uk_applications_reference` (`application_reference`),
    KEY `idx_applications_investor_ref` (`investor_ref_id`),
    KEY `idx_applications_investor_id` (`investor_id`),
    KEY `idx_applications_status` (`status`),
    KEY `idx_applications_created_at` (`created_at`),
    KEY `idx_applications_amount` (`investment_amount`),
    CONSTRAINT `fk_applications_investor` FOREIGN KEY (`investor_ref_id`) REFERENCES `investors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Table: payments (Manual Bank Transfer / UPI Records)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_ref_id` BIGINT UNSIGNED NOT NULL,
    `application_id` VARCHAR(32) NOT NULL,
    `investor_id` VARCHAR(32) NOT NULL,
    `payment_method` ENUM('Bank Transfer','UPI','Other') NOT NULL DEFAULT 'Bank Transfer',
    `payment_date` DATE NOT NULL,
    `utr_number` VARCHAR(100) NOT NULL,
    `amount_paid` DECIMAL(15,2) NOT NULL,
    `sender_bank_or_upi` VARCHAR(160) NOT NULL,
    `verification_status` ENUM('PENDING','UNDER_VERIFICATION','VERIFIED','REUPLOAD_REQUESTED','REJECTED','DUPLICATE') NOT NULL DEFAULT 'UNDER_VERIFICATION',
    `is_duplicate_utr` TINYINT(1) NOT NULL DEFAULT 0,
    `verified_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `verified_at` DATETIME NULL DEFAULT NULL,
    `admin_remarks` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payments_app_ref` (`application_ref_id`),
    KEY `idx_payments_application_id` (`application_id`),
    KEY `idx_payments_investor_id` (`investor_id`),
    KEY `idx_payments_utr` (`utr_number`),
    KEY `idx_payments_status` (`verification_status`),
    KEY `idx_payments_date` (`payment_date`),
    CONSTRAINT `fk_payments_application` FOREIGN KEY (`application_ref_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Table: payment_documents (Uploaded Payment Proof Screenshots & PDFs)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_id` BIGINT UNSIGNED NOT NULL,
    `application_ref_id` BIGINT UNSIGNED NOT NULL,
    `investor_id` VARCHAR(32) NOT NULL,
    `application_id` VARCHAR(32) NOT NULL,
    `utr_number` VARCHAR(100) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `stored_filename` VARCHAR(255) NOT NULL,
    `relative_path` VARCHAR(500) NOT NULL COMMENT 'e.g. uploads/payments/2026/CB-2026-000001/UTR12345.png',
    `mime_type` VARCHAR(100) NOT NULL,
    `file_extension` VARCHAR(16) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `sha256_checksum` CHAR(64) NULL DEFAULT NULL,
    `verification_status` ENUM('PENDING','VERIFIED','REJECTED','SUPERSEDED') NOT NULL DEFAULT 'PENDING',
    `admin_notes` TEXT NULL DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_paydocs_payment_id` (`payment_id`),
    KEY `idx_paydocs_application_id` (`application_id`),
    KEY `idx_paydocs_investor_id` (`investor_id`),
    KEY `idx_paydocs_utr` (`utr_number`),
    CONSTRAINT `fk_paydocs_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_paydocs_application` FOREIGN KEY (`application_ref_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Table: share_allocations (Actual Admin-Approved Share Allotment)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `share_allocations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_ref_id` BIGINT UNSIGNED NOT NULL,
    `application_id` VARCHAR(32) NOT NULL,
    `investor_ref_id` BIGINT UNSIGNED NOT NULL,
    `investor_id` VARCHAR(32) NOT NULL,
    `folio_number` VARCHAR(64) NOT NULL,
    `share_class` VARCHAR(80) NOT NULL DEFAULT 'Equity Shares',
    `shares_allotted` INT UNSIGNED NOT NULL,
    `distinctive_from` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `distinctive_to` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `face_value` DECIMAL(12,2) NOT NULL,
    `issue_price` DECIMAL(12,2) NOT NULL,
    `premium_per_share` DECIMAL(12,2) NOT NULL,
    `paid_up_value_per_share` DECIMAL(12,2) NOT NULL,
    `total_allotment_amount` DECIMAL(15,2) NOT NULL,
    `ownership_percentage` DECIMAL(10,6) NOT NULL,
    `allotment_date` DATE NOT NULL,
    `board_resolution_ref` VARCHAR(120) NULL DEFAULT NULL,
    `allotment_notes` TEXT NULL DEFAULT NULL,
    `allotted_by_admin_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_allocations_app_ref` (`application_ref_id`),
    UNIQUE KEY `uk_allocations_folio` (`folio_number`),
    KEY `idx_allocations_application_id` (`application_id`),
    KEY `idx_allocations_investor_id` (`investor_id`),
    KEY `idx_allocations_date` (`allotment_date`),
    CONSTRAINT `fk_allocations_application` FOREIGN KEY (`application_ref_id`) REFERENCES `applications` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_allocations_investor` FOREIGN KEY (`investor_ref_id`) REFERENCES `investors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Table: certificates (Digital Share Certificates — CB-SC-2026-XXXXXX)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `certificate_number` VARCHAR(32) NOT NULL COMMENT 'Format: CB-SC-2026-000001',
    `allocation_id` BIGINT UNSIGNED NOT NULL,
    `application_ref_id` BIGINT UNSIGNED NOT NULL,
    `application_id` VARCHAR(32) NOT NULL,
    `investor_ref_id` BIGINT UNSIGNED NOT NULL,
    `investor_id` VARCHAR(32) NOT NULL,
    `investor_name` VARCHAR(160) NOT NULL,
    `folio_number` VARCHAR(64) NOT NULL,
    `shares_count` INT UNSIGNED NOT NULL,
    `share_class` VARCHAR(80) NOT NULL DEFAULT 'Equity Shares',
    `face_value` DECIMAL(12,2) NOT NULL,
    `paid_up_value` DECIMAL(12,2) NOT NULL,
    `issue_price` DECIMAL(12,2) NOT NULL,
    `ownership_percentage` DECIMAL(10,6) NOT NULL,
    `allotment_date` DATE NOT NULL,
    `verification_token` VARCHAR(128) NOT NULL,
    `qr_verification_url` VARCHAR(500) NOT NULL,
    `pdf_relative_path` VARCHAR(500) NOT NULL,
    `status` ENUM('VALID','REVOKED','SUSPENDED','SUPERSEDED') NOT NULL DEFAULT 'VALID',
    `revocation_reason` TEXT NULL DEFAULT NULL,
    `issued_by` VARCHAR(160) NOT NULL DEFAULT 'Corelbase Private Limited',
    `generated_by_admin_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `regeneration_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_certificates_number` (`certificate_number`),
    UNIQUE KEY `uk_certificates_token` (`verification_token`),
    KEY `idx_certificates_application_id` (`application_id`),
    KEY `idx_certificates_investor_id` (`investor_id`),
    KEY `idx_certificates_status` (`status`),
    KEY `idx_certificates_generated_at` (`generated_at`),
    CONSTRAINT `fk_certificates_allocation` FOREIGN KEY (`allocation_id`) REFERENCES `share_allocations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_certificates_application` FOREIGN KEY (`application_ref_id`) REFERENCES `applications` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_certificates_investor` FOREIGN KEY (`investor_ref_id`) REFERENCES `investors` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Table: certificate_verification (Public Verification Scan Logs)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificate_verification` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `certificate_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `certificate_number_queried` VARCHAR(64) NOT NULL,
    `verification_result` ENUM('VALID','REVOKED','SUSPENDED','NOT_FOUND') NOT NULL,
    `visitor_ip` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_certverify_cert_id` (`certificate_id`),
    KEY `idx_certverify_number` (`certificate_number_queried`),
    KEY `idx_certverify_at` (`verified_at`),
    CONSTRAINT `fk_certverify_certificate` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Table: admin_users (Administrator Accounts — Argon2id / Bcrypt Hashed)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(80) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `role` ENUM('SUPER_ADMIN','COMPLIANCE_ADMIN','FINANCE_ADMIN','VIEWER') NOT NULL DEFAULT 'SUPER_ADMIN',
    `password_hash` VARCHAR(255) NOT NULL,
    `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `two_factor_secret` VARCHAR(128) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `failed_login_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL DEFAULT NULL,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `last_login_ip` VARCHAR(45) NULL DEFAULT NULL,
    `password_reset_token` VARCHAR(255) NULL DEFAULT NULL,
    `password_reset_expires` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_admin_username` (`username`),
    UNIQUE KEY `uk_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Table: admin_logs (Session & Access Security Logs)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `admin_identifier` VARCHAR(191) NOT NULL,
    `event_type` VARCHAR(80) NOT NULL COMMENT 'LOGIN_SUCCESS, LOGIN_FAILED, LOGOUT, PASSWORD_RESET, etc.',
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `details` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_adminlogs_admin_id` (`admin_id`),
    KEY `idx_adminlogs_event` (`event_type`),
    KEY `idx_adminlogs_created` (`created_at`),
    CONSTRAINT `fk_adminlogs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. Table: audit_logs (Immutable Trail of All Administrative Actions)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `admin_email` VARCHAR(191) NOT NULL DEFAULT 'SYSTEM',
    `action` VARCHAR(120) NOT NULL,
    `entity_type` VARCHAR(60) NOT NULL COMMENT 'APPLICATION, PAYMENT, INVESTOR, CERTIFICATE, SETTINGS, FILE',
    `entity_id` VARCHAR(64) NOT NULL COMMENT 'e.g. CB-2026-000001',
    `old_value` TEXT NULL DEFAULT NULL,
    `new_value` TEXT NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_admin_id` (`admin_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_created_at` (`created_at`),
    CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. Table: email_logs (Transactional Email History & Delivery Status)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_id` VARCHAR(32) NULL DEFAULT NULL,
    `investor_id` VARCHAR(32) NULL DEFAULT NULL,
    `recipient_email` VARCHAR(191) NOT NULL,
    `recipient_name` VARCHAR(160) NULL DEFAULT NULL,
    `template_key` VARCHAR(80) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `status` ENUM('SENT','QUEUED','FAILED','SIMULATED') NOT NULL DEFAULT 'SENT',
    `error_message` TEXT NULL DEFAULT NULL,
    `triggered_by_admin_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_emaillogs_app_id` (`application_id`),
    KEY `idx_emaillogs_recipient` (`recipient_email`),
    KEY `idx_emaillogs_status` (`status`),
    KEY `idx_emaillogs_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. Table: notifications (In-Portal Investor & Admin Alerts)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_type` ENUM('INVESTOR','ADMIN') NOT NULL DEFAULT 'INVESTOR',
    `investor_id` VARCHAR(32) NULL DEFAULT NULL,
    `application_id` VARCHAR(32) NULL DEFAULT NULL,
    `title` VARCHAR(180) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_target` (`target_type`, `investor_id`),
    KEY `idx_notifications_app` (`application_id`),
    KEY `idx_notifications_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. Table: system_settings (Central Configuration Store)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_group` VARCHAR(60) NOT NULL DEFAULT 'general',
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` LONGTEXT NULL DEFAULT NULL,
    `is_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Safe to expose on public frontend API',
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_settings_key` (`setting_key`),
    KEY `idx_settings_group` (`setting_group`),
    KEY `idx_settings_public` (`is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15. Table: login_attempts (IP & Identifier Throttling / Brute-Force Protection)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL,
    `identifier` VARCHAR(191) NOT NULL,
    `endpoint` VARCHAR(60) NOT NULL DEFAULT 'admin_login',
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempts_lookup` (`ip_address`, `endpoint`, `attempted_at`),
    KEY `idx_attempts_identifier` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DEFAULT SYSTEM SETTINGS — CORELBASE PRIVATE LIMITED
-- =============================================================================
INSERT INTO `system_settings` (`setting_group`, `setting_key`, `setting_value`, `is_public`, `description`) VALUES
-- 1. Company Information
('company', 'company_name', 'Corelbase Private Limited', 1, 'Legal Corporate Name'),
('company', 'company_short_name', 'Corelbase', 1, 'Brand Name'),
('company', 'company_cin', 'U62099UP2026PTC250888', 1, 'Corporate Identification Number (CIN)'),
('company', 'company_gstin', '09AAOCC1033C1Z1', 1, 'Goods and Services Tax Identification Number'),
('company', 'company_registered_office', 'HOUSE NO 173, BHARROH, GORAKHPUR, UTTAR PRADESH, Bharroh, Gola, Gorakhpur - 273408, Uttar Pradesh', 1, 'Registered Office Address'),
('company', 'company_email', 'support@corelbase.com', 1, 'Official Support & Investor Relations Email'),
('company', 'company_phone', '', 1, 'Official Phone Number (Leave empty if not disclosed)'),
('company', 'company_website', '', 1, 'Official Website URL'),
('company', 'portal_base_url', 'https://YOUR-DOMAIN', 1, 'Base URL used for QR Certificate Verification links'),
('company', 'timezone', 'Asia/Kolkata', 1, 'Display Timezone'),

-- 2. Investment Round Configuration
('round', 'round_name', '2026 Strategic Infrastructure Seed Round', 1, 'Current Fundraising Round Title'),
('round', 'round_status', 'OPEN', 1, 'OPEN, PAUSED, or CLOSED'),
('round', 'target_investment', '1000000', 1, 'Target Investment Raise in INR (₹10,00,000)'),
('round', 'company_valuation', '100000000', 1, 'Indicative Pre-Money Valuation in INR (₹10,00,00,000)'),
('round', 'min_investment', '5000', 1, 'Minimum Investment Application Amount in INR (₹5,000)'),
('round', 'max_investment', '100000', 1, 'Maximum Investment Application Amount in INR (₹1,00,000)'),

-- 3. Share Calculation & Current Share Capital Configuration
('capital', 'face_value_per_share', '10', 1, 'Face Value per Equity Share in INR'),
('capital', 'issue_price_per_share', '1000', 1, 'Indicative Issue Price per Share in INR'),
('capital', 'premium_per_share', '990', 1, 'Indicative Securities Premium per Share in INR'),
('capital', 'share_class_default', 'Equity Shares', 1, 'Default Class of Shares'),
('capital', 'authorized_capital', '100000', 1, 'Current Authorized Share Capital in INR (₹1,00,000)'),
('capital', 'authorized_capital_public', '1', 1, 'Show Authorized Capital publicly (1=Yes, 0=No)'),
('capital', 'paid_up_capital', '10000', 1, 'Current Paid-up Share Capital in INR (₹10,000)'),
('capital', 'paid_up_capital_public', '1', 1, 'Show Paid-up Capital publicly (1=Yes, 0=No)'),
('capital', 'issued_capital', '10000', 0, 'Current Issued Share Capital in INR'),
('capital', 'issued_capital_public', '0', 1, 'Show Issued Capital publicly (1=Yes, 0=No)'),
('capital', 'subscribed_capital', '10000', 0, 'Current Subscribed Share Capital in INR'),
('capital', 'subscribed_capital_public', '0', 1, 'Show Subscribed Capital publicly (1=Yes, 0=No)'),
('capital', 'authorized_shares', '10000', 0, 'Total Authorized Shares'),
('capital', 'authorized_shares_public', '0', 1, 'Show Authorized Shares publicly (1=Yes, 0=No)'),
('capital', 'total_issued_shares', '1000', 0, 'Total Issued Shares'),
('capital', 'total_issued_shares_public', '0', 1, 'Show Total Issued Shares publicly (1=Yes, 0=No)'),
('capital', 'available_shares', '1000', 1, 'Indicative Available Shares in Current Round'),
('capital', 'available_shares_public', '1', 1, 'Show Indicative Available Shares publicly (1=Yes, 0=No)'),

-- 4. Manual Payment System Configuration
('payment', 'bank_name', 'State Bank of India', 1, 'Official Beneficiary Bank Name'),
('payment', 'account_name', 'Corelbase Private Limited', 1, 'Official Beneficiary Account Name'),
('payment', 'account_number', '45516065276', 1, 'Official Beneficiary Account Number'),
('payment', 'ifsc_code', 'SBIN0001512', 1, 'Official Bank Branch IFSC Code'),
('payment', 'account_type', 'Current Account', 1, 'Bank Account Type'),
('payment', 'upi_id', '[CONFIGURE FROM ADMIN PANEL]', 1, 'Official Corporate UPI ID (Placeholder until configured by Admin)'),
('payment', 'payment_qr_image', 'assets/brand/payment_qr_placeholder.png', 1, 'Path to Official Payment QR Image'),
('payment', 'payment_instructions', 'Transfer the exact investment application amount from your own bank account via NEFT, RTGS, IMPS, or UPI to the official Corelbase Private Limited State Bank of India account listed above. Retain your 12-digit UTR / bank reference number and payment confirmation screenshot for Step 5.', 1, 'Public Payment Instructions'),
('payment', 'allowed_payment_methods', 'Bank Transfer,UPI,Other', 1, 'Comma-separated enabled manual payment methods'),

-- 5. Upload & Security Configuration
('uploads', 'max_upload_size_mb', '5', 1, 'Maximum Payment Proof Upload Size in MB'),
('uploads', 'allowed_extensions', 'jpg,jpeg,png,webp,pdf', 1, 'Allowed Payment Proof File Extensions'),
('security', 'session_timeout_minutes', '30', 0, 'Admin & Investor Session Inactivity Timeout'),
('security', 'max_login_attempts', '5', 0, 'Max failed login attempts before temporary lockout'),
('security', 'lockout_duration_minutes', '15', 0, 'Account/IP lockout duration in minutes'),

-- 6. Brand & Certificate Assets
('assets', 'company_logo', 'assets/brand/logo.png', 1, 'Company Logo Path'),
('assets', 'company_seal', 'assets/brand/seal.png', 1, 'Official Company Seal Image Path'),
('assets', 'director1_name', 'Anurag Singh', 1, 'Director 1 Full Name'),
('assets', 'director1_designation', 'Director', 1, 'Director 1 Official Designation'),
('assets', 'director1_signature', 'assets/brand/director1_sign.png', 0, 'Director 1 Signature Image Path'),
('assets', 'director2_name', 'Manabendra Singha', 1, 'Director 2 Full Name'),
('assets', 'director2_designation', 'Director', 1, 'Director 2 Official Designation'),
('assets', 'director2_signature', 'assets/brand/director2_sign.png', 0, 'Director 2 Signature Image Path'),
('assets', 'favicon', 'assets/brand/favicon.png', 1, 'Website Favicon Path'),

-- 7. SMTP Email Configuration (Placeholders — Configured via Admin Panel / Installer)
('smtp', 'smtp_enabled', '0', 0, 'Enable live SMTP transmission (1=Live SMTP, 0=Log/Simulation Mode)'),
('smtp', 'smtp_host', 'smtp.your-provider.com', 0, 'SMTP Server Hostname'),
('smtp', 'smtp_port', '587', 0, 'SMTP Server Port (587 for TLS, 465 for SSL)'),
('smtp', 'smtp_username', 'SMTP_USERNAME_PLACEHOLDER', 0, 'SMTP Auth Username'),
('smtp', 'smtp_password', 'SMTP_PASSWORD_PLACEHOLDER', 0, 'SMTP Auth Password (Stored encrypted/server-side)'),
('smtp', 'smtp_encryption', 'tls', 0, 'SMTP Encryption Protocol: tls, ssl, or none'),
('smtp', 'smtp_from_email', 'support@corelbase.com', 0, 'Sender Email Address'),
('smtp', 'smtp_from_name', 'Corelbase Investor Relations', 0, 'Sender Display Name')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS = 1;
