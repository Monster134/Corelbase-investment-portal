<?php
/**
 * CORELBASE PRIVATE LIMITED — ADMINISTRATIVE COMMAND CENTER (/admin/index.php)
 * -----------------------------------------------------------------------------
 * Sections 12–16, 18–20, 27–30, 35, 47–51, 58–61, 67–68:
 * Provides complete administrative management of:
 * - 11 Real-time KPI Cards & 5 Visual Charts
 * - Global Instant Search & Status Filtering
 * - Complete 6-Section Investor Profile Drawer & Payment Proof Viewer
 * - Strict Status Workflow Actions with Confirmation Dialogs
 * - Manual Share Allotment Recording & Digital Share Certificate Generator
 * - Share Capital Configuration (with Public/Private visibility toggles)
 * - Company, Round, Bank/UPI, QR, Asset & SMTP Settings + Test SMTP
 * - CSV, Excel-CSV & PDF Report Exports + Immutable Audit & Email Logs
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

Security::initSecureSession();
Security::sendSecurityHeaders(false);

$admin = Auth::requireAdmin(false);
$csrfToken = Security::getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Command Center — Corelbase Investor Portal</title>
  <link rel="icon" type="image/png" href="../assets/brand/favicon.png">
  <link rel="stylesheet" href="../css/style.css">
  <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
  <input type="hidden" id="adminCsrfToken" value="<?= Security::e($csrfToken) ?>">

  <div class="admin-shell">
    <!-- SIDEBAR NAVIGATION -->
    <aside class="admin-sidebar" id="adminSidebar">
      <div>
        <a href="../index.html" class="brand-link" style="padding:0 8px;">
          <img src="../assets/brand/logo.png" alt="Corelbase" class="brand-logo">
          <div>
            <span class="brand-text">COREL<span>BASE</span></span>
            <span class="brand-sub">Admin Command</span>
          </div>
        </a>

        <ul class="sidebar-nav">
          <li><button type="button" class="sidebar-btn active" data-tab="dashboard">📊 Dashboard &amp; KPIs</button></li>
          <li><button type="button" class="sidebar-btn" data-tab="applications">📁 Applications &amp; Search</button></li>
          <li><button type="button" class="sidebar-btn" data-tab="capital">🏛️ Share Capital &amp; Round</button></li>
          <li><button type="button" class="sidebar-btn" data-tab="payment_settings">🏦 Bank, UPI &amp; QR Setup</button></li>
          <li><button type="button" class="sidebar-btn" data-tab="assets">🖋️ Brand, Seal &amp; Signatures</button></li>
          <li><button type="button" class="sidebar-btn" data-tab="smtp">✉️ SMTP &amp; Email Logs</button></li>
          <li><button type="button" class="sidebar-btn" data-tab="reports">📈 Reports &amp; Exports</button></li>
          <li><button type="button" class="sidebar-btn" data-tab="audit">🛡️ Security Audit Logs</button></li>
        </ul>
      </div>

      <div style="border-top:1px solid var(--border-subtle);padding-top:14px;font-size:0.8rem;color:var(--text-secondary);">
        <div style="font-weight:700;color:var(--text-primary);"><?= Security::e($admin['full_name']) ?></div>
        <div style="color:var(--text-muted);margin-bottom:10px;"><?= Security::e($admin['email']) ?> (<?= Security::e($admin['role']) ?>)</div>
        <a href="login.php?action=logout" class="btn btn-secondary btn-sm" style="width:100%;">Sign Out</a>
      </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="admin-main">
      <!-- TOPBAR -->
      <header class="admin-topbar">
        <div style="display:flex;align-items:center;gap:12px;flex:1;">
          <button type="button" class="mobile-menu-btn" id="adminSidebarToggle">☰</button>
          <div class="topbar-search-wrap">
            <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-muted);">🔍</span>
            <input type="search" id="globalAdminSearch" class="topbar-search-input" placeholder="Search Name, Investor ID, Application ID, Email, Mobile, PAN, UTR, Certificate No...">
          </div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;">
          <button type="button" class="theme-toggle" id="adminThemeToggle">
            <span id="adminThemeIcon">☾</span> <span id="adminThemeLabel">Dark</span>
          </button>
          <a href="../index.html" target="_blank" class="btn btn-ghost btn-sm">Public Site ↗</a>
          <a href="../verify.php" target="_blank" class="btn btn-secondary btn-sm">Verify Registry ↗</a>
        </div>
      </header>

      <div class="admin-content">
        <!-- TAB 1: DASHBOARD OVERVIEW (11 KPI Cards + 5 Charts) -->
        <section class="admin-tab-view active" id="tab-dashboard">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <div>
              <h1 style="font-size:1.65rem;">Executive Investment Dashboard</h1>
              <p style="font-size:0.88rem;color:var(--text-secondary);">Real-time telemetry across applications, manual bank verifications, share allotments, and certificates.</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.loadDashboard()">↻ Refresh Data</button>
          </div>

          <!-- 11 Required KPI Cards (Section 12) -->
          <div class="kpi-grid">
            <div class="kpi-card accent"><div class="kpi-title">Total Applications</div><div class="kpi-num" id="kpiTotalApps">0</div></div>
            <div class="kpi-card"><div class="kpi-title">Pending Verification</div><div class="kpi-num" id="kpiPendingVerif" style="color:var(--status-warning);">0</div></div>
            <div class="kpi-card"><div class="kpi-title">Verified Payments</div><div class="kpi-num" id="kpiVerifiedPay" style="color:var(--status-success);">0</div></div>
            <div class="kpi-card"><div class="kpi-title">Approved Applications</div><div class="kpi-num" id="kpiApprovedApps" style="color:var(--status-success);">0</div></div>
            <div class="kpi-card"><div class="kpi-title">Shares Allotted</div><div class="kpi-num" id="kpiSharesAllotted">0</div></div>
            <div class="kpi-card accent"><div class="kpi-title">Certificates Generated</div><div class="kpi-num" id="kpiCertsGen">0</div></div>
            <div class="kpi-card"><div class="kpi-title">Total Investment Applied</div><div class="kpi-num" id="kpiTotalApplied">₹0</div></div>
            <div class="kpi-card"><div class="kpi-title">Total Verified Investment</div><div class="kpi-num" id="kpiTotalVerified" style="color:var(--status-success);">₹0</div></div>
            <div class="kpi-card"><div class="kpi-title">Rejected Applications</div><div class="kpi-num" id="kpiRejectedApps" style="color:var(--status-danger);">0</div></div>
            <div class="kpi-card"><div class="kpi-title">Duplicate / Flagged</div><div class="kpi-num" id="kpiDuplicateApps" style="color:var(--status-danger);">0</div></div>
            <div class="kpi-card"><div class="kpi-title">Cancelled Applications</div><div class="kpi-num" id="kpiCancelledApps">0</div></div>
          </div>

          <!-- 5 Required Charts (Section 12) -->
          <div class="charts-grid">
            <div class="chart-card">
              <div class="metric-label">Chart 01 • Applications Over Time</div>
              <div class="chart-bars" id="chartAppsOverTime"></div>
            </div>
            <div class="chart-card">
              <div class="metric-label">Chart 02 • Investment Amount Over Time</div>
              <div class="chart-bars" id="chartAmountOverTime"></div>
            </div>
            <div class="chart-card">
              <div class="metric-label">Chart 03 • Application Status Distribution</div>
              <div class="chart-bars" id="chartStatusDist"></div>
            </div>
            <div class="chart-card">
              <div class="metric-label">Chart 04 &amp; 05 • Investor State &amp; Monthly Investment</div>
              <div class="chart-bars" id="chartMonthlyInv"></div>
            </div>
          </div>
        </section>

        <!-- TAB 2: APPLICATIONS & ADVANCED GLOBAL SEARCH (Section 13) -->
        <section class="admin-tab-view" id="tab-applications">
          <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px;">
            <div>
              <h2 style="font-size:1.5rem;">Applications &amp; Investor Registry</h2>
              <p style="font-size:0.86rem;color:var(--text-secondary);">Click any application row to open the full 6-section Investor Profile, Payment Screenshot, Allotment &amp; Certificate Drawer.</p>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
              <input type="date" id="filterDateFrom" class="form-control" style="padding:7px 10px;font-size:0.8rem;">
              <input type="date" id="filterDateTo" class="form-control" style="padding:7px 10px;font-size:0.8rem;">
              <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.searchApplications()">Apply Dates</button>
            </div>
          </div>

          <!-- Status Filter Pills (Section 13) -->
          <div class="filter-strip" id="statusFilterStrip">
            <button type="button" class="filter-pill active" data-status="ALL">All Records</button>
            <button type="button" class="filter-pill" data-status="PENDING">Pending</button>
            <button type="button" class="filter-pill" data-status="PAYMENT UNDER VERIFICATION">Payment Under Verification</button>
            <button type="button" class="filter-pill" data-status="PAYMENT VERIFIED">Payment Verified</button>
            <button type="button" class="filter-pill" data-status="APPROVED">Approved</button>
            <button type="button" class="filter-pill" data-status="RE-UPLOAD REQUESTED">Re-upload Requested</button>
            <button type="button" class="filter-pill" data-status="REJECTED">Rejected</button>
            <button type="button" class="filter-pill" data-status="DUPLICATE">Duplicate / Flagged</button>
            <button type="button" class="filter-pill" data-status="CANCELLED">Cancelled</button>
            <button type="button" class="filter-pill" data-status="SHARES ALLOTTED">Shares Allotted</button>
            <button type="button" class="filter-pill" data-status="CERTIFICATE GENERATED">Certificate Generated</button>
          </div>

          <div class="table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Application ID</th>
                  <th>Investor Name</th>
                  <th>Contact (Email / Mobile)</th>
                  <th>PAN (Masked)</th>
                  <th>Investment</th>
                  <th>UTR Reference</th>
                  <th>Workflow Status</th>
                  <th>Allotted / Cert</th>
                  <th>Submitted (IST)</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="applicationsTableBody">
                <tr><td colspan="10" style="text-align:center;padding:28px;color:var(--text-muted);">Loading applications...</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- TAB 3: SHARE CAPITAL, ROUND & COMPANY CONFIGURATION (Sections 1, 2, 3, 4, 50) -->
        <section class="admin-tab-view" id="tab-capital">
          <h2 style="font-size:1.5rem;margin-bottom:6px;">Company, Investment Round &amp; Share Capital Configuration</h2>
          <p style="font-size:0.86rem;color:var(--text-secondary);margin-bottom:22px;">
            Manage all corporate details, fundraising targets, share pricing, and toggle which Share Capital figures are marked as <strong>Public</strong> on the website.
          </p>

          <form id="capitalConfigForm" class="bento-grid">
            <!-- Company Info -->
            <div class="glass-card col-6">
              <div class="profile-section-title">1. Company Information</div>
              <div class="form-grid">
                <div class="form-group full-width"><label class="form-label">Company Legal Name</label><input type="text" class="form-control" name="settings[company_name]" data-setting="company_name"></div>
                <div class="form-group"><label class="form-label">CIN</label><input type="text" class="form-control" name="settings[company_cin]" data-setting="company_cin"></div>
                <div class="form-group"><label class="form-label">GSTIN</label><input type="text" class="form-control" name="settings[company_gstin]" data-setting="company_gstin"></div>
                <div class="form-group full-width"><label class="form-label">Registered Office Address</label><input type="text" class="form-control" name="settings[company_registered_office]" data-setting="company_registered_office"></div>
                <div class="form-group"><label class="form-label">Official Support Email</label><input type="email" class="form-control" name="settings[company_email]" data-setting="company_email"></div>
                <div class="form-group"><label class="form-label">Official Phone (Optional)</label><input type="text" class="form-control" name="settings[company_phone]" data-setting="company_phone"></div>
                <div class="form-group full-width"><label class="form-label">Portal Base Domain (Used for QR Verification Links)</label><input type="text" class="form-control" name="settings[portal_base_url]" data-setting="portal_base_url" placeholder="https://YOUR-DOMAIN"></div>
              </div>
            </div>

            <!-- Investment Round & Pricing -->
            <div class="glass-card col-6">
              <div class="profile-section-title">2. Investment Round &amp; Share Calculation</div>
              <div class="form-grid">
                <div class="form-group"><label class="form-label">Target Investment (INR)</label><input type="number" class="form-control" name="settings[target_investment]" data-setting="target_investment"></div>
                <div class="form-group"><label class="form-label">Indicative Valuation (INR)</label><input type="number" class="form-control" name="settings[company_valuation]" data-setting="company_valuation"></div>
                <div class="form-group"><label class="form-label">Min Investment (INR)</label><input type="number" class="form-control" name="settings[min_investment]" data-setting="min_investment"></div>
                <div class="form-group"><label class="form-label">Max Investment (INR)</label><input type="number" class="form-control" name="settings[max_investment]" data-setting="max_investment"></div>
                <div class="form-group"><label class="form-label">Face Value / Share (INR)</label><input type="number" step="0.01" class="form-control" name="settings[face_value_per_share]" data-setting="face_value_per_share"></div>
                <div class="form-group"><label class="form-label">Issue Price / Share (INR)</label><input type="number" step="0.01" class="form-control" name="settings[issue_price_per_share]" data-setting="issue_price_per_share"></div>
                <div class="form-group"><label class="form-label">Round Status</label>
                  <select class="form-control" name="settings[round_status]" data-setting="round_status">
                    <option value="OPEN">OPEN</option>
                    <option value="PAUSED">PAUSED</option>
                    <option value="CLOSED">CLOSED</option>
                  </select>
                </div>
                <div class="form-group"><label class="form-label">Default Share Class</label><input type="text" class="form-control" name="settings[share_class_default]" data-setting="share_class_default"></div>
              </div>
            </div>

            <!-- Section 4: Current Share Capital Configuration with Public Toggles -->
            <div class="glass-card col-12">
              <div class="profile-section-title">3. Current Share Capital Configuration (Public Visibility Controls)</div>
              <p style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:16px;">
                The public website displays <strong>only</strong> the capital items marked as "Public (Show on Website)".
              </p>
              <div class="form-grid" style="grid-template-columns:repeat(3,1fr);">
                <div class="form-group">
                  <label class="form-label">Authorized Capital (INR)</label>
                  <input type="number" class="form-control" name="settings[authorized_capital]" data-setting="authorized_capital">
                  <select class="form-control" name="settings[authorized_capital_public]" data-setting="authorized_capital_public" style="margin-top:6px;font-size:0.8rem;">
                    <option value="1">Public (Show on Website)</option>
                    <option value="0">Private (Admin Only)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Paid-Up Capital (INR)</label>
                  <input type="number" class="form-control" name="settings[paid_up_capital]" data-setting="paid_up_capital">
                  <select class="form-control" name="settings[paid_up_capital_public]" data-setting="paid_up_capital_public" style="margin-top:6px;font-size:0.8rem;">
                    <option value="1">Public (Show on Website)</option>
                    <option value="0">Private (Admin Only)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Issued Capital (INR)</label>
                  <input type="number" class="form-control" name="settings[issued_capital]" data-setting="issued_capital">
                  <select class="form-control" name="settings[issued_capital_public]" data-setting="issued_capital_public" style="margin-top:6px;font-size:0.8rem;">
                    <option value="1">Public (Show on Website)</option>
                    <option value="0">Private (Admin Only)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Subscribed Capital (INR)</label>
                  <input type="number" class="form-control" name="settings[subscribed_capital]" data-setting="subscribed_capital">
                  <select class="form-control" name="settings[subscribed_capital_public]" data-setting="subscribed_capital_public" style="margin-top:6px;font-size:0.8rem;">
                    <option value="1">Public (Show on Website)</option>
                    <option value="0">Private (Admin Only)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Total Authorized Shares</label>
                  <input type="number" class="form-control" name="settings[authorized_shares]" data-setting="authorized_shares">
                  <select class="form-control" name="settings[authorized_shares_public]" data-setting="authorized_shares_public" style="margin-top:6px;font-size:0.8rem;">
                    <option value="1">Public (Show on Website)</option>
                    <option value="0">Private (Admin Only)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Total Issued Shares</label>
                  <input type="number" class="form-control" name="settings[total_issued_shares]" data-setting="total_issued_shares">
                  <select class="form-control" name="settings[total_issued_shares_public]" data-setting="total_issued_shares_public" style="margin-top:6px;font-size:0.8rem;">
                    <option value="1">Public (Show on Website)</option>
                    <option value="0">Private (Admin Only)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Available Shares (Current Round)</label>
                  <input type="number" class="form-control" name="settings[available_shares]" data-setting="available_shares">
                  <select class="form-control" name="settings[available_shares_public]" data-setting="available_shares_public" style="margin-top:6px;font-size:0.8rem;">
                    <option value="1">Public (Show on Website)</option>
                    <option value="0">Private (Admin Only)</option>
                  </select>
                </div>
              </div>
              <div style="margin-top:20px;text-align:right;">
                <button type="submit" class="btn btn-primary btn-lg">Save Company &amp; Share Capital Settings ✓</button>
              </div>
            </div>
          </form>
        </section>

        <!-- TAB 4: MANUAL BANK, UPI & QR CONFIGURATION (Section 5) -->
        <section class="admin-tab-view" id="tab-payment_settings">
          <h2 style="font-size:1.5rem;margin-bottom:6px;">Manual Bank Account, UPI &amp; Payment QR Settings</h2>
          <p style="font-size:0.86rem;color:var(--text-secondary);margin-bottom:22px;">
            Configure the official Corelbase bank details, corporate UPI ID, upload limits, and payment QR image displayed to investors.
          </p>

          <div class="bento-grid">
            <form id="paymentConfigForm" class="glass-card col-7">
              <div class="profile-section-title">Official Bank &amp; UPI Details</div>
              <div class="form-grid">
                <div class="form-group"><label class="form-label">Bank Name</label><input type="text" class="form-control" name="settings[bank_name]" data-setting="bank_name"></div>
                <div class="form-group"><label class="form-label">Account Name</label><input type="text" class="form-control" name="settings[account_name]" data-setting="account_name"></div>
                <div class="form-group"><label class="form-label">Account Number</label><input type="text" class="form-control" name="settings[account_number]" data-setting="account_number"></div>
                <div class="form-group"><label class="form-label">IFSC Code</label><input type="text" class="form-control" name="settings[ifsc_code]" data-setting="ifsc_code"></div>
                <div class="form-group full-width"><label class="form-label">Official UPI ID (Do not fabricate; set official UPI ID here)</label><input type="text" class="form-control" name="settings[upi_id]" data-setting="upi_id"></div>
                <div class="form-group"><label class="form-label">Max Proof Upload Size (MB)</label><input type="number" class="form-control" name="settings[max_upload_size_mb]" data-setting="max_upload_size_mb"></div>
                <div class="form-group"><label class="form-label">Allowed File Extensions</label><input type="text" class="form-control" name="settings[allowed_extensions]" data-setting="allowed_extensions"></div>
                <div class="form-group full-width"><label class="form-label">Payment Instructions Shown to Applicants</label><textarea class="form-control" rows="3" name="settings[payment_instructions]" data-setting="payment_instructions"></textarea></div>
              </div>
              <div style="margin-top:18px;text-align:right;">
                <button type="submit" class="btn btn-primary">Save Payment &amp; Upload Settings ✓</button>
              </div>
            </form>

            <div class="glass-card col-5" style="text-align:center;">
              <div class="profile-section-title">Upload Official Payment QR Code</div>
              <img src="../assets/brand/payment_qr_placeholder.png" id="adminPreviewPaymentQr" alt="Payment QR" style="width:190px;height:190px;object-fit:contain;background:#fff;padding:10px;border-radius:14px;margin:10px auto 16px;display:block;">
              <form class="asset-upload-form" data-asset-key="payment_qr_image">
                <input type="file" name="asset_file" class="form-control" accept=".png,.jpg,.jpeg,.webp" required style="margin-bottom:12px;">
                <button type="submit" class="btn btn-secondary" style="width:100%;">Upload Replacement Payment QR ↑</button>
              </form>
            </div>
          </div>
        </section>

        <!-- TAB 5: COMPANY ASSETS — LOGO, SEAL & DIRECTOR SIGNATURES (Section 18 & 51) -->
        <section class="admin-tab-view" id="tab-assets">
          <h2 style="font-size:1.5rem;margin-bottom:6px;">Company Assets &amp; Certificate Signatories</h2>
          <p style="font-size:0.86rem;color:var(--text-secondary);margin-bottom:22px;">
            Pre-loaded with Corelbase's official Logo, Seal, Director 1 Signature (Anurag Singh), and Director 2 Signature (Manabendra Singha). Any asset can be replaced below without modifying code.
          </p>

          <div class="bento-grid">
            <div class="glass-card col-6" style="text-align:center;">
              <div class="profile-section-title">Company Logo</div>
              <img src="../assets/brand/logo.png" id="preview_company_logo" alt="Logo" style="height:95px;object-fit:contain;background:#fff;padding:8px;border-radius:12px;margin-bottom:12px;">
              <form class="asset-upload-form" data-asset-key="company_logo">
                <input type="file" name="asset_file" class="form-control" accept=".png,.jpg,.jpeg,.webp" required style="margin-bottom:10px;">
                <button type="submit" class="btn btn-secondary btn-sm">Replace Company Logo</button>
              </form>
            </div>

            <div class="glass-card col-6" style="text-align:center;">
              <div class="profile-section-title">Official Company Seal</div>
              <img src="../assets/brand/seal.png" id="preview_company_seal" alt="Seal" style="height:95px;object-fit:contain;background:#fff;padding:8px;border-radius:12px;margin-bottom:12px;">
              <form class="asset-upload-form" data-asset-key="company_seal">
                <input type="file" name="asset_file" class="form-control" accept=".png,.jpg,.jpeg,.webp" required style="margin-bottom:10px;">
                <button type="submit" class="btn btn-secondary btn-sm">Replace Company Seal</button>
              </form>
            </div>

            <div class="glass-card col-6" style="text-align:center;">
              <div class="profile-section-title">Director 1 Signature (Anurag Singh)</div>
              <img src="../assets/brand/director1_sign.png" id="preview_director1_signature" alt="Director 1 Signature" style="height:80px;object-fit:contain;background:#fff;padding:8px;border-radius:12px;margin-bottom:12px;">
              <form class="asset-upload-form" data-asset-key="director1_signature">
                <input type="file" name="asset_file" class="form-control" accept=".png,.jpg,.jpeg,.webp" required style="margin-bottom:10px;">
                <button type="submit" class="btn btn-secondary btn-sm">Replace Director 1 Signature</button>
              </form>
            </div>

            <div class="glass-card col-6" style="text-align:center;">
              <div class="profile-section-title">Director 2 Signature (Manabendra Singha)</div>
              <img src="../assets/brand/director2_sign.png" id="preview_director2_signature" alt="Director 2 Signature" style="height:80px;object-fit:contain;background:#fff;padding:8px;border-radius:12px;margin-bottom:12px;">
              <form class="asset-upload-form" data-asset-key="director2_signature">
                <input type="file" name="asset_file" class="form-control" accept=".png,.jpg,.jpeg,.webp" required style="margin-bottom:10px;">
                <button type="submit" class="btn btn-secondary btn-sm">Replace Director 2 Signature</button>
              </form>
            </div>
          </div>
        </section>

        <!-- TAB 6: SMTP CONFIGURATION & EMAIL LOGS (Sections 27–30) -->
        <section class="admin-tab-view" id="tab-smtp">
          <h2 style="font-size:1.5rem;margin-bottom:6px;">SMTP Email Service &amp; Delivery Logs</h2>
          <p style="font-size:0.86rem;color:var(--text-secondary);margin-bottom:22px;">
            Configure server-side SMTP credentials and inspect all outgoing transactional email logs.
          </p>

          <div class="bento-grid" style="margin-bottom:24px;">
            <form id="smtpConfigForm" class="glass-card col-7">
              <div class="profile-section-title">SMTP Server Configuration</div>
              <div class="form-grid">
                <div class="form-group">
                  <label class="form-label">Live SMTP Mode</label>
                  <select class="form-control" name="settings[smtp_enabled]" data-setting="smtp_enabled">
                    <option value="0">Simulation / Log Mode (Safe Default)</option>
                    <option value="1">Enable Live SMTP Transmission</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Encryption Protocol</label>
                  <select class="form-control" name="settings[smtp_encryption]" data-setting="smtp_encryption">
                    <option value="tls">TLS (STARTTLS — Port 587)</option>
                    <option value="ssl">SSL (Implicit TLS — Port 465)</option>
                    <option value="none">None</option>
                  </select>
                </div>
                <div class="form-group"><label class="form-label">SMTP Host</label><input type="text" class="form-control" name="settings[smtp_host]" data-setting="smtp_host" placeholder="smtp.your-provider.com"></div>
                <div class="form-group"><label class="form-label">SMTP Port</label><input type="number" class="form-control" name="settings[smtp_port]" data-setting="smtp_port" placeholder="587"></div>
                <div class="form-group"><label class="form-label">SMTP Username</label><input type="text" class="form-control" name="settings[smtp_username]" data-setting="smtp_username"></div>
                <div class="form-group"><label class="form-label">SMTP Password</label><input type="password" class="form-control" name="settings[smtp_password]" placeholder="Leave blank to keep existing password"></div>
                <div class="form-group"><label class="form-label">From Email</label><input type="email" class="form-control" name="settings[smtp_from_email]" data-setting="smtp_from_email"></div>
                <div class="form-group"><label class="form-label">From Name</label><input type="text" class="form-control" name="settings[smtp_from_name]" data-setting="smtp_from_name"></div>
              </div>
              <div style="margin-top:16px;text-align:right;">
                <button type="submit" class="btn btn-primary">Save SMTP Configuration ✓</button>
              </div>
            </form>

            <div class="glass-card col-5">
              <div class="profile-section-title">Test SMTP Connection</div>
              <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:14px;">
                Send a test email to verify your SMTP server credentials and TLS/SSL handshake.
              </p>
              <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Recipient Email for Test</label>
                <input type="email" id="testSmtpEmailInput" class="form-control" value="<?= Security::e($admin['email']) ?>">
              </div>
              <button type="button" class="btn btn-secondary" style="width:100%;" id="sendTestSmtpBtn">Send Test Email →</button>
              <div id="smtpTestResultBox" style="margin-top:14px;font-size:0.83rem;color:var(--text-secondary);"></div>
            </div>
          </div>

          <div class="table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Application ID</th>
                  <th>Recipient</th>
                  <th>Template</th>
                  <th>Subject</th>
                  <th>Status</th>
                  <th>Timestamp (IST)</th>
                </tr>
              </thead>
              <tbody id="emailLogsTableBody"></tbody>
            </table>
          </div>
        </section>

        <!-- TAB 7: ADMIN REPORTS & EXPORTS (Sections 47 & 48) -->
        <section class="admin-tab-view" id="tab-reports">
          <h2 style="font-size:1.5rem;margin-bottom:6px;">Administrative Reports &amp; Data Exports</h2>
          <p style="font-size:0.86rem;color:var(--text-secondary);margin-bottom:22px;">
            Generate and export filtered reports in CSV, Microsoft Excel-compatible CSV, or executive PDF format. Passwords and unmasked Aadhaar numbers are never exported.
          </p>

          <div class="glass-card" style="max-width:820px;">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Report Category</label>
                <select id="expReportType" class="form-control">
                  <option value="investment">Investment Report</option>
                  <option value="investor">Investor Report</option>
                  <option value="payment">Payment Verification Report</option>
                  <option value="application">Application Workflow Report</option>
                  <option value="certificate">Share Certificate Registry Report</option>
                  <option value="monthly">Monthly Summary Report</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Filter by Workflow Status</label>
                <select id="expStatusFilter" class="form-control">
                  <option value="ALL">All Statuses</option>
                  <option value="PAYMENT UNDER VERIFICATION">Payment Under Verification</option>
                  <option value="PAYMENT VERIFIED">Payment Verified</option>
                  <option value="APPROVED">Approved</option>
                  <option value="SHARES ALLOTTED">Shares Allotted</option>
                  <option value="CERTIFICATE GENERATED">Certificate Generated</option>
                  <option value="REJECTED">Rejected</option>
                  <option value="DUPLICATE">Duplicate</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Date Range From</label>
                <input type="date" id="expDateFrom" class="form-control">
              </div>
              <div class="form-group">
                <label class="form-label">Date Range To</label>
                <input type="date" id="expDateTo" class="form-control">
              </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:22px;">
              <button type="button" class="btn btn-secondary" onclick="window.AdminApp.exportReport('csv')">⬇ Export Standard CSV</button>
              <button type="button" class="btn btn-secondary" onclick="window.AdminApp.exportReport('excel_csv')">⬇ Export Excel-Compatible CSV</button>
              <button type="button" class="btn btn-primary" onclick="window.AdminApp.exportReport('pdf')">⬇ Download Executive PDF Report</button>
            </div>
          </div>
        </section>

        <!-- TAB 8: IMMUTABLE SECURITY AUDIT LOGS (Section 49) -->
        <section class="admin-tab-view" id="tab-audit">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <div>
              <h2 style="font-size:1.5rem;">Immutable Security &amp; Compliance Audit Trail</h2>
              <p style="font-size:0.86rem;color:var(--text-secondary);">Records every login, logout, application view, payment verification, allotment, certificate generation, file download, and setting modification.</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.loadLogs('audit')">↻ Refresh Audit Trail</button>
          </div>

          <div class="table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Log ID</th>
                  <th>Admin</th>
                  <th>Action</th>
                  <th>Entity</th>
                  <th>Entity ID</th>
                  <th>Transition / Details</th>
                  <th>IP Address</th>
                  <th>Timestamp (IST)</th>
                </tr>
              </thead>
              <tbody id="auditLogsTableBody"></tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </div>

  <!-- SLIDE-OVER INVESTOR PROFILE & WORKFLOW DRAWER (Sections 14, 15, 35, 61) -->
  <div class="drawer-backdrop" id="investorDrawerBackdrop">
    <div class="drawer-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--border-subtle);margin-bottom:20px;">
        <div>
          <span class="eyebrow" id="drwStatusBadge" style="margin:0 0 6px;">STATUS</span>
          <h2 style="font-size:1.5rem;font-family:monospace;" id="drwAppIdTitle">CB-2026-000001</h2>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="window.AdminApp.closeProfileDrawer()">✕ Close Drawer</button>
      </div>

      <div id="drwDuplicateWarningBox" style="display:none;padding:14px;border-radius:12px;background:rgba(239,68,68,0.16);border:1px solid rgba(239,68,68,0.5);color:#fca5a5;font-size:0.85rem;margin-bottom:18px;"></div>

      <!-- 1. Personal Information -->
      <div class="profile-section-card">
        <div class="profile-section-title">1. Personal &amp; KYC Information</div>
        <div class="kv-grid" id="drwPersonalGrid"></div>
      </div>

      <!-- 2. Investment Information -->
      <div class="profile-section-card">
        <div class="profile-section-title">2. Investment &amp; Indicative Calculation</div>
        <div class="kv-grid" id="drwInvestmentGrid"></div>
      </div>

      <!-- 3. Payment & Proof File Management (Section 35) -->
      <div class="profile-section-card">
        <div class="profile-section-title">3. Manual Payment &amp; Uploaded Proof</div>
        <div class="kv-grid" id="drwPaymentGrid" style="margin-bottom:14px;"></div>
        <div id="drwPaymentFilesBox"></div>
      </div>

      <!-- 4. Application Workflow Actions (Section 15 & 59) -->
      <div class="profile-section-card">
        <div class="profile-section-title">4. Application Workflow &amp; Admin Controls</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
          <button type="button" class="btn btn-primary btn-sm" onclick="window.AdminApp.promptWorkflow('verify_payment', 'Verify Payment')">✓ Verify Payment</button>
          <button type="button" class="btn btn-primary btn-sm" onclick="window.AdminApp.promptWorkflow('approve_application', 'Approve Application')">✓ Approve Application</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.promptWorkflow('request_reupload', 'Request Payment Proof Re-Upload')">↺ Request Re-upload</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.promptWorkflow('mark_duplicate', 'Mark as Duplicate')">⚠ Mark Duplicate</button>
          <button type="button" class="btn btn-secondary btn-sm" style="border-color:#ef4444;color:#fca5a5;" onclick="window.AdminApp.promptWorkflow('reject_payment', 'Reject Payment / Application')">✕ Reject Payment</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="window.AdminApp.promptWorkflow('cancel_application', 'Cancel Application')">Cancel Application</button>
        </div>

        <div class="form-grid">
          <div class="form-group full-width">
            <label class="form-label">Add Admin Note / Edit Allowed Fields</label>
            <textarea id="drwAdminNoteInput" class="form-control" rows="2" placeholder="Enter internal compliance or verification note..."></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Investor Name (Editable)</label>
            <input type="text" id="drwEditName" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">UTR Reference (Editable)</label>
            <input type="text" id="drwEditUtr" class="form-control">
          </div>
        </div>
        <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;">
          <div style="font-size:0.78rem;color:var(--text-muted);" id="drwExistingNotes"></div>
          <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.saveNoteAndEdits()">Save Note &amp; Edits</button>
        </div>
      </div>

      <!-- 5. Share Allocation Recording (Sections 14, 60, 61) -->
      <div class="profile-section-card">
        <div class="profile-section-title">5. Record Actual Share Allotment (Required Before Certificate Generation)</div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Actual Shares Allotted <span class="req">*</span></label>
            <input type="number" id="allocSharesInput" class="form-control" min="1">
          </div>
          <div class="form-group">
            <label class="form-label">Allotment Date <span class="req">*</span></label>
            <input type="date" id="allocDateInput" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Register Folio Number</label>
            <input type="text" id="allocFolioInput" class="form-control" placeholder="Auto-generated if blank">
          </div>
          <div class="form-group">
            <label class="form-label">Board Resolution Reference</label>
            <input type="text" id="allocBoardRefInput" class="form-control" placeholder="e.g. BR-2026-04">
          </div>
        </div>
        <div style="margin-top:12px;text-align:right;">
          <button type="button" class="btn btn-primary btn-sm" onclick="window.AdminApp.recordShareAllotment()">Record Official Share Allotment ✓</button>
        </div>
      </div>

      <!-- 6. Share Certificate Generation & Email (Sections 14, 18, 29, 61) -->
      <div class="profile-section-card">
        <div class="profile-section-title">6. Share Certificate Management (`CB-SC-2026-XXXXXX`)</div>
        <div id="drwCertificateInfoBox" style="margin-bottom:14px;font-size:0.88rem;color:var(--text-secondary);"></div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
          <button type="button" class="btn btn-primary btn-sm" onclick="window.AdminApp.promptCertificateGeneration(false)">📜 Generate Share Certificate</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.promptCertificateGeneration(true)">↻ Regenerate Certificate</button>
          <a href="#" id="drwDownloadCertBtn" target="_blank" class="btn btn-secondary btn-sm" style="display:none;">⬇ Download Certificate PDF</a>
          <button type="button" class="btn btn-secondary btn-sm" onclick="window.AdminApp.resendTemplateEmail('certificate_available')">✉️ Email Certificate to Investor</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="window.AdminApp.resendTemplateEmail('application_submitted')">✉️ Resend Status Email</button>
        </div>
      </div>

      <!-- Application Audit History -->
      <div class="profile-section-card">
        <div class="profile-section-title">Application Audit History</div>
        <div id="drwAuditList" style="font-size:0.8rem;color:var(--text-secondary);max-height:190px;overflow-y:auto;"></div>
      </div>
    </div>
  </div>

  <!-- CONFIRMATION DIALOG MODAL (Section 59) -->
  <div class="modal-backdrop" id="adminConfirmModal">
    <div class="modal-dialog" style="max-width:480px;">
      <div class="modal-header">
        <h3 id="confModalTitle" style="font-size:1.1rem;">Confirm Administrative Action</h3>
        <button type="button" class="btn btn-ghost btn-sm" onclick="window.AdminApp.closeConfirmModal()">✕</button>
      </div>
      <div class="modal-body">
        <div style="margin-bottom:12px;font-size:0.88rem;">
          <div><strong>Action:</strong> <span id="confActionName" style="color:var(--accent-red-hover);"></span></div>
          <div><strong>Application ID:</strong> <code id="confAppId"></code></div>
          <div><strong>Investor:</strong> <span id="confInvestorName"></span></div>
        </div>
        <p id="confWarningText" style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:14px;"></p>
        <div class="form-group" style="margin-bottom:18px;">
          <label class="form-label">Reason / Compliance Remark (Logged in Audit Trail)</label>
          <input type="text" id="confNotesInput" class="form-control" placeholder="Optional or required remark for this action">
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" onclick="window.AdminApp.closeConfirmModal()">Cancel</button>
          <button type="button" class="btn btn-primary" id="confExecuteBtn">Confirm &amp; Execute ✓</button>
        </div>
      </div>
    </div>
  </div>

  <div class="toast-container" id="toastContainer"></div>
  <script src="../js/admin.js"></script>
</body>
</html>
