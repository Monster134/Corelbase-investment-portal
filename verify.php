<?php
/**
 * CORELBASE PRIVATE LIMITED — PUBLIC SHARE CERTIFICATE VERIFICATION (/verify.php)
 * -----------------------------------------------------------------------------
 * Sections 20 & 62:
 * Verifies Share Certificates (e.g. CB-SC-2026-000001) scanned via QR or entered
 * manually.
 *
 * Shows:
 * - Large Corelbase logo
 * - Positive Green Verification Indicator (or Revoked/Suspended warning)
 * - Certificate Number
 * - Status: VALID / REVOKED / SUSPENDED
 * - Company Name: Corelbase Private Limited
 * - Partially Masked Holder Name (e.g. Aar** Sha***)
 * - Number of Shares & Class of Shares
 * - Allotment Date
 * - Issued By & Verification Timestamp (IST)
 * - Verification QR Code
 *
 * Strictly NEVER exposes: PAN, Aadhaar, Bank info, Email, Mobile, Address, or
 * Payment screenshot.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/qr_generator.php';

Security::sendSecurityHeaders(false);

$certNumber = strtoupper(Security::cleanInput($_GET['id'] ?? $_GET['cert_no'] ?? '', 50));
$cert = null;
$lookupPerformed = ($certNumber !== '');
$verificationTimestamp = formatIST(gmdate('Y-m-d H:i:s'));

if ($lookupPerformed) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.certificate_number,
                c.investor_name,
                c.folio_number,
                c.shares_count,
                c.share_class,
                c.face_value,
                c.paid_up_value,
                c.allotment_date,
                c.qr_verification_url,
                c.status,
                c.revocation_reason,
                c.issued_by,
                c.generated_at
            FROM `certificates` c
            WHERE UPPER(c.certificate_number) = :cno
            LIMIT 1
        ");
        $stmt->execute([':cno' => $certNumber]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);

        // Log verification scan in `certificate_verification`
        $resStatus = $cert ? (string)$cert['status'] : 'NOT_FOUND';
        if (!in_array($resStatus, ['VALID', 'REVOKED', 'SUSPENDED', 'NOT_FOUND'], true)) {
            $resStatus = 'NOT_FOUND';
        }
        $logStmt = $pdo->prepare("
            INSERT INTO `certificate_verification`
            (`certificate_id`, `certificate_number_queried`, `verification_result`, `visitor_ip`, `user_agent`, `verified_at`)
            VALUES (:cid, :cno, :res, :ip, :ua, UTC_TIMESTAMP())
        ");
        $logStmt->execute([
            ':cid' => $cert['id'] ?? null,
            ':cno' => $certNumber,
            ':res' => $resStatus,
            ':ip'  => Security::getClientIp(),
            ':ua'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250),
        ]);
    } catch (Throwable $e) {
        // Database not yet initialized
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify Share Certificate — Corelbase Private Limited</title>
  <link rel="icon" type="image/png" href="assets/brand/favicon.png">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <div class="ambient-canvas" aria-hidden="true"></div>

  <div class="compliance-topbar">
    <strong>OFFICIAL CERTIFICATE VERIFICATION REGISTRY:</strong> Corelbase Private Limited (CIN: U62099UP2026PTC250888). Holder identity details are partially masked for public privacy protection.
  </div>

  <header class="site-header">
    <div class="container nav-wrapper">
      <a href="index.html" class="brand-link">
        <img src="assets/brand/logo.png" alt="Corelbase Logo" class="brand-logo">
        <div>
          <span class="brand-text">COREL<span>BASE</span></span>
          <span class="brand-sub">Certificate Registry</span>
        </div>
      </a>
      <div class="nav-actions">
        <a href="index.html" class="btn btn-ghost btn-sm">← Back to Portal</a>
        <a href="track.php" class="btn btn-secondary btn-sm">Track Application</a>
      </div>
    </div>
  </header>

  <main class="section">
    <div class="container" style="max-width:860px;">
      <!-- Large Corelbase Identity Header -->
      <div style="text-align:center;margin-bottom:32px;">
        <img src="assets/brand/logo.png" alt="Corelbase Private Limited" style="width:76px;height:76px;background:#fff;padding:6px;border-radius:18px;box-shadow:0 12px 30px rgba(0,0,0,0.4);margin-bottom:14px;">
        <div class="eyebrow" style="display:table;margin:0 auto 10px;">QR &amp; Certificate Authenticity Verification</div>
        <h1 class="section-title" style="margin-bottom:8px;">Corelbase Share Certificate Registry</h1>
        <p style="color:var(--text-secondary);">Verify digital share certificates (`CB-SC-2026-XXXXXX`) issued by Corelbase Private Limited.</p>
      </div>

      <!-- Search Input Box -->
      <div class="glass-card" style="margin-bottom:28px;">
        <form method="GET" action="verify.php" style="display:flex;gap:12px;flex-wrap:wrap;">
          <input type="text" class="form-control" name="id" value="<?= Security::e($certNumber) ?>" placeholder="Enter Certificate Number (e.g. CB-SC-2026-000001)" style="flex:1;min-width:240px;font-family:monospace;font-size:1.05rem;" required>
          <button type="submit" class="btn btn-primary btn-lg">Verify Certificate ✓</button>
        </form>
      </div>

      <?php if ($lookupPerformed): ?>
        <?php if ($cert): ?>
          <?php
            $status = strtoupper((string)$cert['status']);
            $isValid = ($status === 'VALID');
            $badgeColor = $isValid ? '#10b981' : ($status === 'SUSPENDED' ? '#f59e0b' : '#ef4444');
            $badgeBg = $isValid ? 'rgba(16,185,129,0.14)' : ($status === 'SUSPENDED' ? 'rgba(245,158,11,0.15)' : 'rgba(239,68,68,0.15)');
            $maskedHolder = Security::maskInvestorName((string)$cert['investor_name']);
            $verifyUrl = (string)($cert['qr_verification_url'] ?: ('https://YOUR-DOMAIN/verify.php?id=' . urlencode((string)$cert['certificate_number'])));
          ?>
          <div class="glass-card" style="border-color:<?= $badgeColor ?>;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;padding-bottom:20px;border-bottom:1px solid var(--border-subtle);margin-bottom:22px;">
              <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:52px;height:52px;border-radius:50%;background:<?= $badgeBg ?>;border:2px solid <?= $badgeColor ?>;color:<?= $badgeColor ?>;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:800;">
                  <?= $isValid ? '✓' : '!' ?>
                </div>
                <div>
                  <div style="font-size:1.35rem;font-weight:800;color:<?= $badgeColor ?>;">
                    <?= $isValid ? '✓ Certificate Verified' : 'Certificate ' . Security::e($status) ?>
                  </div>
                  <div style="font-size:0.84rem;color:var(--text-secondary);">
                    Official Registry Record • Verified at <?= Security::e($verificationTimestamp) ?>
                  </div>
                </div>
              </div>
              <div style="padding:8px 16px;border-radius:99px;background:<?= $badgeBg ?>;border:1px solid <?= $badgeColor ?>;color:<?= $badgeColor ?>;font-weight:800;font-size:0.85rem;">
                STATUS: <?= Security::e($status) ?>
              </div>
            </div>

            <div class="calc-layout" style="align-items:center;">
              <div class="calc-output-grid" style="margin-bottom:0;">
                <div class="calc-stat-box highlight">
                  <div class="metric-label">Certificate Number</div>
                  <div style="font-size:1.15rem;font-weight:800;font-family:monospace;"><?= Security::e($cert['certificate_number']) ?></div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Company Name</div>
                  <div style="font-size:1.02rem;font-weight:700;"><?= Security::e($cert['issued_by'] ?: 'Corelbase Private Limited') ?></div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Registered Holder (Masked)</div>
                  <div style="font-size:1.12rem;font-weight:800;"><?= Security::e($maskedHolder) ?></div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Number of Shares</div>
                  <div style="font-size:1.15rem;font-weight:800;color:var(--status-success);"><?= number_format((int)$cert['shares_count']) ?> <?= Security::e($cert['share_class'] ?: 'Equity Shares') ?></div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Allotment Date</div>
                  <div style="font-size:1.02rem;font-weight:700;"><?= Security::e(date('d M Y', strtotime((string)$cert['allotment_date']))) ?></div>
                </div>
                <div class="calc-stat-box">
                  <div class="metric-label">Issued By</div>
                  <div style="font-size:0.95rem;font-weight:700;">Board of Directors, Corelbase Pvt Ltd</div>
                </div>
              </div>

              <div style="text-align:center;padding:20px;background:var(--bg-glass);border-radius:16px;border:1px solid var(--border-subtle);">
                <div class="metric-label" style="margin-bottom:10px;">Verification QR Code</div>
                <div style="display:inline-block;padding:10px;background:#ffffff;border-radius:12px;">
                  <?= QRGenerator::renderSvg($verifyUrl, 165, 2) ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:8px;font-family:monospace;">
                  <?= Security::e($cert['certificate_number']) ?>
                </div>
              </div>
            </div>

            <?php if (!$isValid && !empty($cert['revocation_reason'])): ?>
              <div style="margin-top:18px;padding:12px 16px;border-radius:10px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.4);color:#fca5a5;font-size:0.86rem;">
                <strong>Administrative Notice:</strong> <?= Security::e($cert['revocation_reason']) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="glass-card" style="border-color:rgba(239,68,68,0.55);text-align:center;padding:36px 24px;">
            <div style="font-size:2.2rem;color:#ef4444;margin-bottom:10px;">✕</div>
            <h2 style="font-size:1.45rem;color:#fca5a5;margin-bottom:8px;">Certificate Not Found</h2>
            <p style="color:var(--text-secondary);max-width:520px;margin:0 auto;">
              No share certificate matching <code><?= Security::e($certNumber) ?></code> was found in the official Corelbase Private Limited allotment registry. Please verify the certificate number or contact <a href="mailto:support@corelbase.com" style="color:var(--accent-red-hover);">support@corelbase.com</a>.
            </p>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="legal-box" style="margin-top:32px;">
        <strong>Mandatory Statutory Disclaimer:</strong> <?= Security::e(CB_LEGAL_DISCLAIMER) ?>
      </div>
    </div>
  </main>
</body>
</html>
