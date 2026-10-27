<?php
/**
 * CORELBASE PRIVATE LIMITED — AUTHENTICATED INVESTOR DASHBOARD (/investor/index.php)
 * -----------------------------------------------------------------------------
 * Sections 45 & 46:
 * Allows registered investors to log in and inspect:
 * - Profile (with masked PAN & Aadhaar)
 * - Application & 6-stage Timeline
 * - Document Status Checklist (Application, Payment, KYC, Approval, Allotment, Certificate)
 * - Investment Amount, Indicative Shares, and Actual Allotted Shares (if approved)
 * - Downloadable Acknowledgement PDF & Share Certificate PDF
 * - In-Portal Notifications
 * Never exposes internal admin notes or audit logs.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

Security::initSecureSession();
Security::sendSecurityHeaders(false);

$csrfToken = Security::getCsrfToken();
$loginError = '';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    $pdo = null;
}

// Handle Logout
if (($_GET['action'] ?? '') === 'logout') {
    unset($_SESSION['investor_user_id'], $_SESSION['investor_code'], $_SESSION['investor_email'], $_SESSION['investor_name']);
    session_regenerate_id(true);
    header('Location: index.php');
    exit;
}

// Handle Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $loginError = 'Security session expired. Please refresh and try again.';
    } elseif (!$pdo) {
        $loginError = 'Database service is unavailable. Please run /install/ first.';
    } else {
        $ident = Security::cleanInput($_POST['identifier'] ?? '', 180);
        $pass = (string)($_POST['password'] ?? '');
        $res = Auth::investorLogin($pdo, $ident, $pass);
        if ($res['success']) {
            header('Location: index.php');
            exit;
        }
        $loginError = $res['message'];
    }
}

$isLoggedIn = !empty($_SESSION['investor_user_id']) && $pdo !== null;
$investorProfile = null;
$applications = [];
$notifications = [];

if ($isLoggedIn) {
    $invCode = (string)$_SESSION['investor_code'];
    $stmtInv = $pdo->prepare("
        SELECT `id`, `investor_id`, `full_name`, `email`, `mobile`, `dob`, `address`, `city`, `state`, `pincode`, `country`,
               `pan_masked`, `aadhaar_masked`, `kyc_status`, `created_at`
        FROM `investors`
        WHERE `investor_id` = :ic OR `user_id` = :uid
        LIMIT 1
    ");
    $stmtInv->execute([':ic' => $invCode, ':uid' => (int)$_SESSION['investor_user_id']]);
    $investorProfile = $stmtInv->fetch(PDO::FETCH_ASSOC);

    if ($investorProfile) {
        $stmtApps = $pdo->prepare("
            SELECT
                a.application_id, a.investment_amount, a.indicative_shares, a.indicative_ownership_pct,
                a.configured_issue_price, a.configured_face_value, a.configured_premium,
                a.status, a.reupload_reason, a.created_at,
                p.payment_method, p.payment_date, p.utr_number, p.amount_paid, p.verification_status AS pay_status,
                sa.shares_allotted, sa.folio_number, sa.allotment_date, sa.ownership_percentage AS actual_ownership_pct,
                c.certificate_number, c.status AS cert_status
            FROM `applications` a
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            LEFT JOIN `share_allocations` sa ON sa.application_ref_id = a.id
            LEFT JOIN `certificates` c ON c.application_ref_id = a.id
            WHERE a.investor_ref_id = :iref
            ORDER BY a.id DESC
        ");
        $stmtApps->execute([':iref' => $investorProfile['id']]);
        $applications = $stmtApps->fetchAll(PDO::FETCH_ASSOC);

        $stmtNotif = $pdo->prepare("
            SELECT `title`, `message`, `is_read`, `created_at`
            FROM `notifications`
            WHERE `target_type` = 'INVESTOR' AND `investor_id` = :iid
            ORDER BY `id` DESC LIMIT 15
        ");
        $stmtNotif->execute([':iid' => $investorProfile['investor_id']]);
        $notifications = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Investor Dashboard — Corelbase Private Limited</title>
  <link rel="icon" type="image/png" href="../assets/brand/favicon.png">
  <link rel="stylesheet" href="../css/style.css">
</head>
<body>
  <div class="ambient-canvas" aria-hidden="true"></div>

  <header class="site-header">
    <div class="container nav-wrapper">
      <a href="../index.html" class="brand-link">
        <img src="../assets/brand/logo.png" alt="Corelbase Logo" class="brand-logo">
        <div>
          <span class="brand-text">COREL<span>BASE</span></span>
          <span class="brand-sub">Investor Dashboard</span>
        </div>
      </a>
      <div class="nav-actions">
        <a href="../index.html" class="btn btn-ghost btn-sm">Public Portal</a>
        <a href="../track.php" class="btn btn-secondary btn-sm">Quick Track</a>
        <?php if ($isLoggedIn): ?>
          <a href="index.php?action=logout" class="btn btn-primary btn-sm">Sign Out</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="section">
    <div class="container">
      <?php if (!$isLoggedIn): ?>
        <div class="glass-card" style="max-width:480px;margin:20px auto;">
          <span class="eyebrow">Investor Portal Access</span>
          <h1 style="font-size:1.65rem;margin-bottom:8px;">Sign In to Your Investor Account</h1>
          <p style="color:var(--text-secondary);font-size:0.9rem;margin-bottom:20px;">
            Enter your registered Email or Investor ID (<code>CB-2026-XXXXXX</code>) and the password created during registration.
          </p>

          <?php if ($loginError !== ''): ?>
            <div style="padding:12px;border-radius:10px;background:rgba(239,68,68,0.14);border:1px solid rgba(239,68,68,0.4);color:#fca5a5;font-size:0.85rem;margin-bottom:16px;">
              <?= Security::e($loginError) ?>
            </div>
          <?php endif; ?>

          <form method="POST" action="index.php" style="display:flex;flex-direction:column;gap:16px;">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
              <label class="form-label" for="identifier">Email or Investor ID</label>
              <input type="text" class="form-control" id="identifier" name="identifier" placeholder="investor@example.com or CB-2026-000001" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="password">Portal Password</label>
              <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">Access Investor Dashboard →</button>
          </form>

          <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border-subtle);font-size:0.84rem;color:var(--text-secondary);text-align:center;">
            Didn't set a password during submission? You can track your application directly without a password on the <a href="../track.php" style="color:var(--accent-red-hover);">Application Tracking Page</a>.
          </div>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px;">
          <div>
            <span class="eyebrow">Authenticated Investor Session</span>
            <h1 style="font-size:2rem;">Welcome, <?= Security::e($investorProfile['full_name'] ?? 'Investor') ?></h1>
            <p style="color:var(--text-secondary);">Investor ID: <code><?= Security::e($investorProfile['investor_id'] ?? '') ?></code> | Registered Email: <?= Security::e($investorProfile['email'] ?? '') ?></p>
          </div>
          <a href="../index.html#apply" class="btn btn-primary">+ Submit New Application</a>
        </div>

        <div class="bento-grid">
          <!-- Investor Profile & Masked KYC Card -->
          <div class="glass-card col-5">
            <div class="metric-label">Investor Profile &amp; Masked KYC</div>
            <h3 style="font-size:1.25rem;margin-bottom:14px;">Verified Identity Details</h3>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:0.9rem;">
              <div class="bank-detail-row"><span class="bank-detail-label">Investor ID</span><span class="bank-detail-val"><?= Security::e($investorProfile['investor_id'] ?? '') ?></span></div>
              <div class="bank-detail-row"><span class="bank-detail-label">Mobile</span><span><?= Security::e($investorProfile['mobile'] ?? '') ?></span></div>
              <div class="bank-detail-row"><span class="bank-detail-label">PAN (Masked)</span><span class="bank-detail-val"><?= Security::e($investorProfile['pan_masked'] ?? '') ?></span></div>
              <div class="bank-detail-row"><span class="bank-detail-label">Aadhaar (Masked)</span><span class="bank-detail-val"><?= Security::e($investorProfile['aadhaar_masked'] ?? '') ?></span></div>
              <div class="bank-detail-row"><span class="bank-detail-label">KYC Status</span><span class="eyebrow" style="margin:0;"><?= Security::e($investorProfile['kyc_status'] ?? 'PENDING') ?></span></div>
            </div>
          </div>

          <!-- Notifications Card -->
          <div class="glass-card col-7">
            <div class="metric-label">In-Portal Updates</div>
            <h3 style="font-size:1.25rem;margin-bottom:14px;">Application &amp; Compliance Notifications</h3>
            <?php if (empty($notifications)): ?>
              <p style="color:var(--text-secondary);">No new notifications yet. Updates appear here as your application progresses.</p>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:10px;max-height:280px;overflow-y:auto;">
                <?php foreach ($notifications as $n): ?>
                  <div style="padding:12px 14px;border-radius:10px;background:var(--bg-glass);border:1px solid var(--border-subtle);">
                    <div style="display:flex;justify-content:space-between;font-size:0.8rem;color:var(--text-muted);margin-bottom:4px;">
                      <strong style="color:var(--text-primary);"><?= Security::e($n['title']) ?></strong>
                      <span><?= Security::e(formatIST($n['created_at'])) ?></span>
                    </div>
                    <div style="font-size:0.86rem;color:var(--text-secondary);"><?= Security::e($n['message']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Applications List -->
          <?php foreach ($applications as $app): ?>
            <?php
              $st = (string)$app['status'];
              $docPay = in_array($st, ['PAYMENT VERIFIED', 'APPROVED', 'SHARES ALLOTTED', 'CERTIFICATE GENERATED'], true) ? '✓ Payment Verified' : '⏳ Under Verification';
              $docKyc = ($investorProfile['kyc_status'] ?? '') === 'VERIFIED' ? '✓ KYC Verified' : '⏳ KYC Under Review';
              $docApp = in_array($st, ['APPROVED', 'SHARES ALLOTTED', 'CERTIFICATE GENERATED'], true) ? '✓ Approved' : '⏳ Approval Pending';
              $docAllot = !empty($app['shares_allotted']) ? ('✓ ' . number_format((int)$app['shares_allotted']) . ' Shares Allotted') : '○ Allotment Pending';
              $docCert = !empty($app['certificate_number']) ? ('✓ ' . $app['certificate_number']) : '○ Certificate Pending';
            ?>
            <div class="glass-card col-12">
              <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--border-subtle);">
                <div>
                  <div class="metric-label">Application ID</div>
                  <h3 style="font-size:1.5rem;font-family:monospace;"><?= Security::e($app['application_id']) ?></h3>
                </div>
                <span class="eyebrow" style="margin:0;font-size:0.85rem;padding:8px 16px;"><?= Security::e($st) ?></span>
              </div>

              <!-- Document & Milestone Status Row (Section 46) -->
              <div class="status-timeline" style="margin-bottom:20px;">
                <div class="timeline-node done"><div class="metric-label">Application</div><div style="font-weight:700;">✓ Submitted</div></div>
                <div class="timeline-node <?= str_starts_with($docPay, '✓') ? 'done' : 'current' ?>"><div class="metric-label">Payment</div><div style="font-weight:700;"><?= Security::e($docPay) ?></div></div>
                <div class="timeline-node <?= str_starts_with($docKyc, '✓') ? 'done' : 'current' ?>"><div class="metric-label">KYC</div><div style="font-weight:700;"><?= Security::e($docKyc) ?></div></div>
                <div class="timeline-node <?= str_starts_with($docApp, '✓') ? 'done' : '' ?>"><div class="metric-label">Approval</div><div style="font-weight:700;"><?= Security::e($docApp) ?></div></div>
                <div class="timeline-node <?= str_starts_with($docAllot, '✓') ? 'done' : '' ?>"><div class="metric-label">Allotment</div><div style="font-weight:700;"><?= Security::e($docAllot) ?></div></div>
                <div class="timeline-node <?= str_starts_with($docCert, '✓') ? 'done' : '' ?>"><div class="metric-label">Certificate</div><div style="font-weight:700;"><?= Security::e($docCert) ?></div></div>
              </div>

              <div class="calc-output-grid" style="grid-template-columns:repeat(4,1fr);">
                <div class="calc-stat-box">
                  <div class="metric-label">Investment Amount</div>
                  <div style="font-size:1.2rem;font-weight:800;"><?= Security::e(formatINR((float)$app['investment_amount'])) ?></div>
                  <div class="metric-sub">UTR: <?= Security::e($app['utr_number'] ?? '—') ?></div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Indicative Shares</div>
                  <div style="font-size:1.2rem;font-weight:800;"><?= Security::e(rtrim(rtrim(number_format((float)$app['indicative_shares'], 4), '0'), '.')) ?></div>
                  <div class="metric-sub">Indicative (~<?= Security::e(rtrim(rtrim(number_format((float)$app['indicative_ownership_pct'], 6), '0'), '.')) ?>%)</div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Actual Shares Allotted</div>
                  <div style="font-size:1.2rem;font-weight:800;color:var(--status-success);">
                    <?= !empty($app['shares_allotted']) ? number_format((int)$app['shares_allotted']) . ' Equity Shares' : 'Subject to Approval' ?>
                  </div>
                  <div class="metric-sub">Folio: <?= Security::e($app['folio_number'] ?? 'Pending') ?></div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Documents &amp; Verification</div>
                  <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px;">
                    <a href="../api/acknowledgement.php?app_id=<?= urlencode((string)$app['application_id']) ?>&email=<?= urlencode((string)$investorProfile['email']) ?>" target="_blank" class="btn btn-secondary btn-sm">Acknowledgement PDF</a>
                    <?php if (!empty($app['certificate_number']) && $app['cert_status'] === 'VALID'): ?>
                      <a href="../api/certificate_download.php?cert_no=<?= urlencode((string)$app['certificate_number']) ?>&app_id=<?= urlencode((string)$app['application_id']) ?>" target="_blank" class="btn btn-primary btn-sm">Download Share Certificate</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
