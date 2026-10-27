# Corelbase Private Limited — Investment Application & Investor Management Portal

A production-oriented, configuration-driven **Online Investment Application & Investor Management Portal** engineered for **Corelbase Private Limited** (`CIN: U62099UP2026PTC250888`, `GSTIN: 09AAOCC1033C1Z1`).

> **IMPORTANT LEGAL & ARCHITECTURAL NOTICE**
> This system is an **investment application and investor-management portal**, NOT a stock exchange, public securities marketplace, or automated share-trading platform. Submitting an application or transferring payment does **not** automatically confer shareholder status or generate a share certificate. Actual share allotment and digital share certificate issuance remain strictly administrative actions subject to payment verification, company approval, statutory compliance, and corporate documentation.

---

## 1. Project Architecture & Directory Structure

```text
corelbase-investor-portal/
├── index.html                  # Standalone Apple-inspired Black + Red Public Portal (20 Sections)
├── track.php                   # Public Application Tracking & Re-Upload Portal (/track)
├── verify.php                  # Public QR Share Certificate Verification Registry (/verify)
├── database.sql                # Complete MySQL 8+ / MariaDB Schema (15 Tables + Corelbase Defaults)
├── demo_seed.sql               # Optional Demo Data Seed (Clearly marked [DEMO RECORD])
├── .htaccess                   # Root Apache routing, security headers & file protection
├── README.md                   # Complete aaPanel Deployment & Operations Guide
├── admin/
│   ├── index.php               # Admin Command Center (11 KPIs, 5 Charts, Drawer, Allotment, Certs)
│   └── login.php               # Secure Administrator Login (password_verify + Rate Limiting)
├── investor/
│   └── index.php               # Authenticated Investor Dashboard (Profile, Timeline, Docs, Alerts)
├── api/
│   ├── public_config.php       # Public Settings & CSRF Token Endpoint
│   ├── apply.php               # 7-Step Investor Registration & Manual Payment Submission API
│   ├── track.php               # Sanitized JSON Application Status Endpoint
│   ├── verify.php              # Public JSON Certificate Verification Endpoint
│   ├── acknowledgement.php     # Application Acknowledgement PDF Generator & Streamer
│   ├── certificate_download.php# Share Certificate PDF Streamer
│   └── admin_api.php           # Master Administrative API (Search, Workflow, Allotment, Exports)
├── config/
│   ├── config.php              # Central Company, Round, Capital & Security Defaults
│   ├── database.php            # PDO Singleton Connection Manager with Server-Side Error Logging
│   └── .htaccess               # Denies direct HTTP access
├── includes/
│   ├── security.php            # Session Hardening, CSRF, AES-256-GCM KYC Encryption, Masking, Uploads
│   ├── functions.php           # Sequential IDs (CB-2026-000001), Share Calc, Workflow Matrix, Duplicates
│   ├── auth.php                # Admin & Investor Authentication & Brute-Force Protection
│   ├── mailer.php              # Native Socket SMTP Client (TLS/SSL) + 11 Branded Email Templates
│   ├── pdf_generator.php       # Pure-PHP Native PDF 1.4 Engine (Share Certificates, Receipts, Reports)
│   ├── qr_generator.php        # Pure-PHP ISO/IEC 18004 QR Code Generator (Inline SVG + GD JPEG)
│   └── .htaccess               # Denies direct HTTP access
├── assets/
│   └── brand/                  # Pre-bundled Official Corelbase Assets:
│       ├── logo.png / .jpg     # Official Corelbase Logo
│       ├── seal.png / .jpg     # Official Corelbase Corporate Seal
│       ├── director1_sign.png  # Director 1 Signature (Anurag Singh)
│       ├── director2_sign.png  # Director 2 Signature (Manabendra Singha)
│       ├── payment_qr_placeholder.png
│       └── favicon.png
├── css/
│   ├── style.css               # Public Portal Stylesheet (Dark Default + Light Mode, Bento, Glass)
│   └── admin.css               # Admin Command Center Stylesheet
├── js/
│   ├── app.js                  # Public Calculator, 7-Step Wizard, KYC Masking & Modals
│   └── admin.js                # Admin Dashboard, Charts, Global Search, Drawer & Exports
├── uploads/
│   ├── .htaccess               # Blocks script execution inside /uploads/
│   ├── assets/                 # Admin-uploaded replacement company assets
│   └── payments/
│       └── 2026/               # /uploads/payments/YEAR/INVESTOR-ID/UTR.ext
├── certificates/
│   └── .htaccess               # Generated Share Certificate PDFs (CB-SC-2026-XXXXXX.pdf)
├── logs/
│   └── .htaccess               # Server-side technical error logs
└── install/
    └── index.php               # 8-Step Web Installation Wizard with Lock File Mechanism
```

---

## 2. Step-by-Step Deployment Guide for aaPanel (VPS)

### Step 2.1: Server Requirements in aaPanel
1. Log into your **aaPanel** control panel.
2. From the **App Store**, ensure the following are installed:
   - **Web Server**: Apache 2.4+ (recommended for automatic `.htaccess` support) or Nginx/OpenLiteSpeed
   - **PHP**: **PHP 8.1, 8.2, or 8.3**
   - **MySQL**: **MySQL 8.0+** or MariaDB 10.6+
3. Required PHP Extensions (enabled by default in aaPanel PHP 8+):
   - `pdo`, `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `gd`, `iconv`, `json`

### Step 2.2: Create Website & Database in aaPanel
1. Go to **Website → Add site**:
   - Enter your domain (e.g. `invest.corelbase.com`).
   - Select **Create MySQL Database** (`utf8mb4`). Copy the generated **Database Name**, **Username**, and **Password**.
   - Select **PHP Version: PHP-81 / PHP-82 / PHP-83**.
2. Upload `corelbase-investor-portal.zip` to the site root directory (e.g. `/www/wwwroot/invest.corelbase.com/`) and click **Unzip** so `index.html`, `admin/`, `api/`, `install/`, etc. sit directly in the web root.

### Step 2.3: Folder Permissions
In aaPanel **Files**, set ownership to `www:www` and permissions as follows:
- Directories `uploads/`, `certificates/`, `logs/`, `config/`: `750` or `755` (writable by PHP `www` user)
- All `.php`, `.html`, `.css`, `.js` files: `644`

### Step 2.4: Enable SSL (HTTPS)
1. In aaPanel **Website → [Your Site] → SSL**, select **Let's Encrypt**, issue a certificate, and toggle **Force HTTPS** ON.

### Step 2.5: Run the 8-Step Web Installer (`/install/`)
1. Open `https://YOUR-DOMAIN/install/` in your browser.
2. Follow the 8 guided steps:
   - **Step 1**: Enter your aaPanel MySQL credentials (`config/env.local.php` is generated automatically).
   - **Step 2**: Click **Create All Database Tables** (imports `database.sql`; leave demo seed unchecked for production).
   - **Step 3**: Create your first **Super Administrator** account (passwords are hashed with `password_hash()`; weak passwords like `admin/admin` are rejected).
   - **Step 4**: Verify Corelbase company details and set `Portal Base Domain` (used for QR Certificate Verification links).
   - **Step 5**: Enter your SMTP credentials (or keep Simulation/Log mode active until ready).
   - **Step 6**: Confirm the State Bank of India account (`45516065276`, `SBIN0001512`) and enter your official Corporate UPI ID when ready.
   - **Step 7**: Verify pre-bundled Logo, Seal, and Director Signatures (Anurag Singh & Manabendra Singha).
   - **Step 8**: Click **Complete Installation & Lock Installer** (creates `config/installed.lock`).
3. **Production Hardening**: After completing Step 8, delete or rename the `/install/` directory from aaPanel Files.

---

## 3. Manual Configuration Placeholders (If Not Using `/install/`)

If you prefer manual configuration without the web installer:
1. Import `database.sql` into your MySQL database via phpMyAdmin.
2. Edit `config/config.php` (or create `config/env.local.php`) and replace:
   - `DB_USER` / `DB_PASS` with your MySQL credentials
   - `CB_APP_SECRET_KEY` and `CB_HMAC_PEPPER` with 64-character random hex strings
3. Configure SMTP settings and official UPI ID inside **Admin Dashboard → Settings** (`/admin/`).

---

## 4. Backup & Security Checklist

- **Database Backups**: Schedule daily MySQL backups in **aaPanel → Cron → Backup Database**.
- **Uploads & Certificates Backups**: Schedule daily directory backups of `/uploads/payments/` and `/certificates/`.
- **Upload Execution Protection**: Verify that `/uploads/.htaccess` is present so no executable script can ever run inside `/uploads/`.
