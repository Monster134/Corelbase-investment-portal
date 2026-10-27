<?php
/**
 * CORELBASE PRIVATE LIMITED — CENTRAL CONFIGURATION FILE
 * -----------------------------------------------------------------------------
 * All company, round, share capital, manual bank details, security, and
 * environment settings fall back to these defaults and can be dynamically
 * overridden from the Admin Panel (`system_settings` table).
 *
 * IMPORTANT:
 * Do NOT expose database credentials, encryption keys, or SMTP secrets to the
 * public frontend (`index.html`).
 */

declare(strict_types=1);

// Prevent direct script execution if included improperly
if (basename($_SERVER['PHP_SELF'] ?? '') === 'config.php') {
    http_response_code(403);
    exit('Access denied.');
}

// Default Server Timezone for Display (Database stores UTC)
date_default_timezone_set('Asia/Kolkata');

// =============================================================================
// 1. ENVIRONMENT & DATABASE CREDENTIALS (Configure here or via /install/)
// =============================================================================
define('CB_ENV', getenv('CB_ENV') ?: 'production'); // 'production' or 'development'
define('CB_DEBUG', false); // Never display raw PHP errors to users in production

define('DB_HOST', getenv('CB_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('CB_DB_PORT') ?: '3306');
define('DB_NAME', getenv('CB_DB_NAME') ?: 'corelbase_investor_portal');
define('DB_USER', getenv('CB_DB_USER') ?: 'DB_USERNAME_PLACEHOLDER');
define('DB_PASS', getenv('CB_DB_PASS') ?: 'DB_PASSWORD_PLACEHOLDER');
define('DB_CHARSET', 'utf8mb4');

// =============================================================================
// 2. CRYPTOGRAPHIC KEYS (Auto-generated during /install/ or set via ENV)
// =============================================================================
// Used for AES-256-GCM encryption of PAN & Aadhaar numbers and HMAC indexing.
// Replace this placeholder in production or run the /install/ wizard.
define('CB_APP_SECRET_KEY', getenv('CB_APP_SECRET_KEY') ?: 'REPLACE_WITH_64_HEX_CHAR_RANDOM_KEY_FROM_INSTALLER_2026_CORELBASE');
define('CB_HMAC_PEPPER', getenv('CB_HMAC_PEPPER') ?: 'REPLACE_WITH_UNIQUE_HMAC_PEPPER_FROM_INSTALLER_2026_CORELBASE');

// =============================================================================
// 3. PATH CONSTANTS
// =============================================================================
define('CB_ROOT_PATH', dirname(__DIR__));
define('CB_CONFIG_PATH', __DIR__);
define('CB_INCLUDES_PATH', CB_ROOT_PATH . '/includes');
define('CB_UPLOADS_PATH', CB_ROOT_PATH . '/uploads');
define('CB_PAYMENTS_UPLOAD_PATH', CB_ROOT_PATH . '/uploads/payments');
define('CB_CERTIFICATES_PATH', CB_ROOT_PATH . '/certificates');
define('CB_LOGS_PATH', CB_ROOT_PATH . '/logs');
define('CB_INSTALL_LOCK_FILE', CB_CONFIG_PATH . '/installed.lock');

// =============================================================================
// 4. DEFAULT COMPANY & PORTAL CONFIGURATION (Fallback if DB not yet initialized)
// =============================================================================
const CB_DEFAULT_SETTINGS = [
    // 1. Company Information
    'company_name'               => 'Corelbase Private Limited',
    'company_short_name'         => 'Corelbase',
    'company_cin'                => 'U62099UP2026PTC250888',
    'company_gstin'              => '09AAOCC1033C1Z1',
    'company_registered_office'  => 'HOUSE NO 173, BHARROH, GORAKHPUR, UTTAR PRADESH, Bharroh, Gola, Gorakhpur - 273408, Uttar Pradesh',
    'company_email'              => 'support@corelbase.com',
    'company_phone'              => '', // Not provided — configurable in Admin Panel
    'company_website'            => '', // Not provided — configurable in Admin Panel
    'portal_base_url'            => 'https://YOUR-DOMAIN',
    'timezone'                   => 'Asia/Kolkata',

    // 2. Investment Round
    'round_name'                 => '2026 Strategic Infrastructure Seed Round',
    'round_status'               => 'OPEN',
    'target_investment'          => '1000000',     // ₹10,00,000
    'company_valuation'          => '100000000',   // ₹10,00,00,000 (₹10 Crore)
    'min_investment'             => '5000',        // ₹5,000
    'max_investment'             => '100000',      // ₹1,00,000

    // 3. Share Calculation & Current Capital Configuration
    'face_value_per_share'       => '10',          // ₹10
    'issue_price_per_share'      => '1000',        // ₹1,000
    'premium_per_share'          => '990',         // ₹990
    'share_class_default'        => 'Equity Shares',
    'authorized_capital'         => '100000',      // ₹1,00,000
    'authorized_capital_public'  => '1',
    'paid_up_capital'            => '10000',       // ₹10,000
    'paid_up_capital_public'     => '1',
    'issued_capital'             => '10000',
    'issued_capital_public'      => '0',
    'subscribed_capital'         => '10000',
    'subscribed_capital_public'  => '0',
    'authorized_shares'          => '10000',
    'authorized_shares_public'   => '0',
    'total_issued_shares'        => '1000',
    'total_issued_shares_public' => '0',
    'available_shares'           => '1000',
    'available_shares_public'    => '1',

    // 4. Manual Payment System
    'bank_name'                  => 'State Bank of India',
    'account_name'               => 'Corelbase Private Limited',
    'account_number'             => '45516065276',
    'ifsc_code'                  => 'SBIN0001512',
    'account_type'               => 'Current Account',
    'upi_id'                     => '[CONFIGURE FROM ADMIN PANEL]',
    'payment_qr_image'           => 'assets/brand/payment_qr_placeholder.png',
    'payment_instructions'       => 'Transfer the exact investment application amount from your own bank account via NEFT, RTGS, IMPS, or UPI to the official Corelbase Private Limited State Bank of India account listed above. Retain your UTR / transaction reference number and payment screenshot for verification.',
    'allowed_payment_methods'    => 'Bank Transfer,UPI,Other',

    // 5. Upload & Security Settings
    'max_upload_size_mb'         => '5',
    'allowed_extensions'         => 'jpg,jpeg,png,webp,pdf',
    'session_timeout_minutes'    => '30',
    'max_login_attempts'         => '5',
    'lockout_duration_minutes'   => '15',

    // 6. Company Assets
    'company_logo'               => 'assets/brand/logo.png',
    'company_seal'               => 'assets/brand/seal.png',
    'director1_name'             => 'Anurag Singh',
    'director1_designation'      => 'Director',
    'director1_signature'        => 'assets/brand/director1_sign.png',
    'director2_name'             => 'Manabendra Singha',
    'director2_designation'      => 'Director',
    'director2_signature'        => 'assets/brand/director2_sign.png',
    'favicon'                    => 'assets/brand/favicon.png',

    // 7. SMTP Settings (Server-side only)
    'smtp_enabled'               => '0',
    'smtp_host'                  => 'smtp.your-provider.com',
    'smtp_port'                  => '587',
    'smtp_username'              => 'SMTP_USERNAME_PLACEHOLDER',
    'smtp_password'              => 'SMTP_PASSWORD_PLACEHOLDER',
    'smtp_encryption'            => 'tls',
    'smtp_from_email'            => 'support@corelbase.com',
    'smtp_from_name'             => 'Corelbase Investor Relations',
];

// Legal Mandatory Disclaimer Text
const CB_LEGAL_DISCLAIMER = 'Corelbase Private Limited is a private limited company (CIN: U62099UP2026PTC250888). This website is an investment application and investor information portal and is not a stock exchange, securities trading platform, or open public securities marketplace. Submission of an application or payment does not by itself constitute allotment or confer shareholder status. Any issue or allotment of securities is subject to eligibility, company approvals, applicable corporate procedures, statutory requirements, documentation and applicable laws. Investors should independently evaluate the investment and obtain professional legal, financial and tax advice where appropriate.';
