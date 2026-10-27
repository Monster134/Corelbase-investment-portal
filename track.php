<?php
/**
 * CORELBASE PRIVATE LIMITED — PUBLIC APPLICATION TRACKING PORTAL (/track.php)
 * -----------------------------------------------------------------------------
 * Allows investors to track their application status using Application ID + Email.
 * Strictly protects privacy: NEVER exposes PAN, Aadhaar, bank details, internal
 * admin notes, or payment screenshots.
 * Also supports replacement payment proof upload if status is RE-UPLOAD REQUESTED.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

Security::initSecureSession();
Security::sendSecurityHeaders(false);

$csrfToken = Security::getCsrfToken();
$settings = getAllSettings(null, true);

$appId = strtoupper(Security::cleanInput($_GET['app_id'] ?? $_POST['app_id'] ?? '', 40));
$email = strtolower(Security::cleanInput($_GET['email'] ?? $_POST['email'] ?? '', 180));
$uploadMessage = '';
$uploadSuccess = false;
$record = null;
$errorMessage = '';

if ($appId !== '' && $email !== '') {
    try {
        $pdo = Database::getConnection();

        // Handle payment proof re-upload if submitted
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reupload_proof') {
            if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                $errorMessage = 'Security token expired. Please refresh and try again.';
            } else {
                $stmtCheck = $pdo->prepare("
                    SELECT a.*, i.email, i.investor_id, p.id AS payment_pk, p.utr_number
                    FROM `applications` a
                    INNER JOIN `investors` i ON i.id = a.investor_ref_id
                    LEFT JOIN `payments` p ON p.application_ref_id = a.id
                    WHERE UPPER(a.application_id) = :aid AND LOWER(i.email) = :em
                    LIMIT 1
                ");
                $stmtCheck->execute([':aid' => $appId, ':em' => $email]);
                $appRow = $stmtCheck->fetch();

                if (!$appRow) {
                    $errorMessage = 'Matching application not found.';
                } elseif ($appRow['status'] !== 'RE-UPLOAD REQUESTED') {
                    $errorMessage = 'Payment proof re-upload is only enabled when requested by the Administrator.';
                } elseif (empty($_FILES['replacement_proof']['name'])) {
                    $errorMessage = 'Please select a valid payment screenshot or PDF to upload.';
                } else {
                    $newUtr = Security::cleanInput($_POST['utr_number'] ?? $appRow['utr_number'], 80);
                    $maxMb = (int)getSetting('max_upload_size_mb', '5', $pdo);
                    $allowedExt = getSetting('allowed_extensions', 'jpg,jpeg,png,webp,pdf', $pdo);

                    $uploadRes = Security::validateAndStorePaymentProof(
                        $_FILES['replacement_proof'],
                        (string)$appRow['investor_id'],
                        $newUtr,
                        $maxMb,
                        $allowedExt
                    );

                    if (!$uploadRes['success']) {
                        $errorMessage = $uploadRes['message'] ?? 'Upload failed.';
                    } else {
                        $fData = $uploadRes['data'];
                        $pdo->beginTransaction();

                        // Mark previous documents as SUPERSEDED
                        $pdo->prepare("UPDATE `payment_documents` SET `verification_status` = 'SUPERSEDED' WHERE `application_ref_id` = :arid")
                            ->execute([':arid' => $appRow['id']]);

                        // Update payment UTR & status
                        $pdo->prepare("
                            UPDATE `payments`
                            SET `utr_number` = :utr, `verification_status` = 'UNDER_VERIFICATION', `updated_at` = UTC_TIMESTAMP()
                            WHERE `id` = :pid
                        ")->execute([':utr' => $newUtr, ':pid' => $appRow['payment_pk']]);

                        // Insert new payment document record
                        $stmtDoc = $pdo->prepare("
                            INSERT INTO `payment_documents`
                            (`payment_id`, `application_ref_id`, `investor_id`, `application_id`, `utr_number`,
                             `original_filename`, `stored_filename`, `relative_path`, `mime_type`, `file_extension`,
                             `file_size`, `sha256_checksum`, `verification_status`, `uploaded_at`)
                            VALUES
                            (:pid, :arid, :inv_id, :app_id, :utr, :orig, :stored, :rel, :mime, :ext, :fsize, :sha, 'PENDING', UTC_TIMESTAMP())
                        ");
                        $stmtDoc->execute([
                            ':pid'    => $appRow['payment_pk'],
                            ':arid'   => $appRow['id'],
                            ':inv_id' => $appRow['investor_id'],
                            ':app_id' => $appRow['application_id'],
                            ':utr'    => $newUtr,
                            ':orig'   => $fData['original_filename'],
                            ':stored' => $fData['stored_filename'],
                            ':rel'    => $fData['relative_path'],
                            ':mime'   => $fData['mime_type'],
                            ':ext'    => $fData['file_extension'],
                            ':fsize'  => $fData['file_size'],
                            ':sha'    => $fData['sha256_checksum'],
                        ]);

                        // Transition application back to PAYMENT UNDER VERIFICATION
                        $pdo->prepare("
                            UPDATE `applications`
                            SET `status` = 'PAYMENT UNDER VERIFICATION', `updated_at` = UTC_TIMESTAMP()
                            WHERE `id` = :id
                        ")->execute([':id' => $appRow['id']]);

                        logAuditAction($pdo, 'Investor Re-Uploaded Payment Proof', 'APPLICATION', (string)$appRow['application_id'], null, 'INVESTOR', 'RE-UPLOAD REQUESTED', 'PAYMENT UNDER VERIFICATION', "New UTR: {$newUtr}");
                        $pdo->commit();
                        $uploadSuccess = true;
                        $uploadMessage = 'Replacement payment proof uploaded successfully. Your application is now back Under Verification.';
                    }
                }
            }
        }

        // Query sanitized tracking details (NO PAN, NO Aadhaar, NO Bank Acc, NO Admin Notes)
        $stmt = $pdo->prepare("
            SELECT
                a.application_id,
                a.investor_id,
                a.investment_amount,
                a.indicative_shares,
                a.indicative_ownership_pct,
                a.status,
                a.reupload_reason,
                a.rejection_reason,
                a.created_at,
                a.verified_at,
                a.approved_at,
                i.kyc_status,
                p.verification_status AS payment_verification_status,
                p.payment_date,
                sa.shares_allotted,
                sa.allotment_date,
                sa.folio_number,
                c.certificate_number,
                c.status AS certificate_status
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            LEFT JOIN `share_allocations` sa ON sa.application_ref_id = a.id
            LEFT JOIN `certificates` c ON c.application_ref_id = a.id
            WHERE UPPER(a.application_id) = :aid AND LOWER(i.email) = :em
            LIMIT 1
        ");
        $stmt->execute([':aid' => $appId, ':em' => $email]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record && $errorMessage === '') {
            $errorMessage = 'No investment application matched that Application ID and Email combination.';
        }
    } catch (Throwable $e) {
        $errorMessage = 'Unable to retrieve tracking status right now. Please ensure the database is initialized.';
    }
}

/**
 * Determine milestone icon & state for the 6-step tracking view (Section 17).
 */
function resolveTrackSteps(array $rec): array
{
    $status = (string)$rec['status'];
    $payStatus = (string)($rec['payment_verification_status'] ?? 'UNDER_VERIFICATION');

    $paymentVerified = in_array($status, ['PAYMENT VERIFIED', 'APPROVED', 'SHARES ALLOTTED', 'CERTIFICATE GENERATED'], true)
        || $payStatus === 'VERIFIED';
    $approved = in_array($status, ['APPROVED', 'SHARES ALLOTTED', 'CERTIFICATE GENERATED'], true);
    $allotted = in_array($status, ['SHARES ALLOTTED', 'CERTIFICATE GENERATED'], true) || !empty($rec['shares_allotted']);
    $certGenerated = ($status === 'CERTIFICATE GENERATED') || !empty($rec['certificate_number']);

    return [
        [
            'label' => 'Application Submitted',
            'state' => 'done',
            'icon'  => '✓',
            'sub'   => formatIST($rec['created_at'] ?? null, 'd M Y'),
        ],
        [
            'label' => 'Payment Received',
            'state' => ($status === 'REJECTED' || $status === 'CANCELLED') ? 'pending' : 'done',
            'icon'  => ($status === 'REJECTED' || $status === 'CANCELLED') ? '✕' : '✓',
            'sub'   => 'Proof Uploaded',
        ],
        [
            'label' => $paymentVerified ? 'Payment Verified' : ($status === 'RE-UPLOAD REQUESTED' ? 'Re-Upload Requested' : 'Payment Under Verification'),
            'state' => $paymentVerified ? 'done' : ($status === 'PAYMENT UNDER VERIFICATION' || $status === 'RE-UPLOAD REQUESTED' ? 'current' : 'pending'),
            'icon'  => $paymentVerified ? '✓' : '⏳',
            'sub'   => $paymentVerified ? 'Verified by Treasury' : 'Bank Reconciliation',
        ],
        [
            'label' => $approved ? 'Application Approved' : 'Approval Pending',
            'state' => $approved ? 'done' : ($paymentVerified && !$approved ? 'current' : 'pending'),
            'icon'  => $approved ? '✓' : ($paymentVerified ? '⏳' : '○'),
            'sub'   => $approved ? 'Company Approved' : 'Subject to Approval',
        ],
        [
            'label' => 'Shares Allotted',
            'state' => $allotted ? 'done' : ($approved && !$allotted ? 'current' : 'pending'),
            'icon'  => $allotted ? '✓' : ($approved ? '⏳' : '○'),
            'sub'   => $allotted ? ((int)$rec['shares_allotted'] . ' Equity Shares') : 'Pending Allotment',
        ],
        [
            'label' => 'Certificate Generated',
            'state' => $certGenerated ? 'done' : ($allotted && !$certGenerated ? 'current' : 'pending'),
            'icon'  => $certGenerated ? '✓' : '○',
            'sub'   => $certGenerated ? (string)$rec['certificate_number'] : 'Issued Post-Allotment',
        ],
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Track Investment Application — Corelbase Private Limited</title>
  <link rel="icon" type="image/png" href="assets/brand/favicon.png">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <div class="ambient-canvas" aria-hidden="true"></div>

  <div class="compliance-topbar">
    <strong>INVESTOR PRIVACY PROTECTION:</strong> Sensitive personal identifiers (PAN, Aadhaar, Bank Details, and Payment Screenshots) are never displayed on public tracking pages.
  </div>

  <header class="site-header">
    <div class="container nav-wrapper">
      <a href="index.html" class="brand-link">
        <img src="assets/brand/logo.png" alt="Corelbase Logo" class="brand-logo">
        <div>
          <span class="brand-text">COREL<span>BASE</span></span>
          <span class="brand-sub">Application Tracker</span>
        </div>
      </a>
      <div class="nav-actions">
        <a href="index.html" class="btn btn-ghost btn-sm">← Back to Portal</a>
        <a href="verify.php" class="btn btn-secondary btn-sm">Verify Certificate</a>
        <a href="investor/index.php" class="btn btn-primary btn-sm">Investor Login</a>
      </div>
    </div>
  </header>

  <main class="section">
    <div class="container">
      <div class="section-header">
        <span class="eyebrow">Real-Time Application Status</span>
        <h1 class="section-title">Track Your Investment Application</h1>
        <p class="section-desc">
          Enter your unique Corelbase Application ID (e.g. <code>CB-2026-000001</code>) and your registered email address below.
        </p>
      </div>

      <!-- Lookup Form Card -->
      <div class="glass-card" style="max-width:780px;margin-bottom:32px;">
        <form method="GET" action="track.php" class="form-grid">
          <div class="form-group">
            <label class="form-label" for="app_id">Application ID <span class="req">*</span></label>
            <input type="text" class="form-control" id="app_id" name="app_id" value="<?= Security::e($appId) ?>" placeholder="CB-2026-000001" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="email">Registered Email Address <span class="req">*</span></label>
            <input type="email" class="form-control" id="email" name="email" value="<?= Security::e($email) ?>" placeholder="investor@example.com" required>
          </div>
          <div class="form-group full-width">
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">Check Application Status →</button>
          </div>
        </form>
      </div>

      <?php if ($errorMessage !== ''): ?>
        <div class="glass-card" style="border-color:rgba(239,68,68,0.5);background:rgba(239,68,68,0.08);margin-bottom:28px;">
          <strong style="color:#fca5a5;">Status Lookup Notice:</strong>
          <p style="color:var(--text-secondary);margin-top:4px;"><?= Security::e($errorMessage) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($uploadMessage !== ''): ?>
        <div class="glass-card" style="border-color:rgba(16,185,129,0.5);background:rgba(16,185,129,0.1);margin-bottom:28px;">
          <strong style="color:#34d399;">✓ <?= Security::e($uploadMessage) ?></strong>
        </div>
      <?php endif; ?>

      <?php if ($record): ?>
        <?php $steps = resolveTrackSteps($record); ?>
        <div class="glass-card" style="margin-bottom:28px;">
          <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;padding-bottom:20px;border-bottom:1px solid var(--border-subtle);">
            <div>
              <div class="metric-label">Application Reference</div>
              <h2 style="font-size:1.75rem;font-family:monospace;"><?= Security::e($record['application_id']) ?></h2>
              <div style="font-size:0.84rem;color:var(--text-secondary);">Investor ID: <strong><?= Security::e($record['investor_id']) ?></strong> | Submitted: <?= Security::e(formatIST($record['created_at'])) ?></div>
            </div>
            <div style="text-align:right;">
              <div class="metric-label">Current Workflow Stage</div>
              <span class="eyebrow" style="font-size:0.86rem;padding:8px 16px;margin:0;"><?= Security::e($record['status']) ?></span>
            </div>
          </div>

          <!-- 6-Stage Visual Timeline (Section 17) -->
          <div class="status-timeline" style="margin:28px 0;">
            <?php foreach ($steps as $st): ?>
              <div class="timeline-node <?= Security::e($st['state']) ?>">
                <div style="font-size:1.35rem;font-weight:800;margin-bottom:6px;"><?= Security::e($st['icon']) ?></div>
                <div style="font-weight:700;font-size:0.9rem;"><?= Security::e($st['label']) ?></div>
                <div style="font-size:0.76rem;color:var(--text-muted);margin-top:4px;"><?= Security::e($st['sub']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Summary Metrics & Document Status (Section 46) -->
          <div class="calc-output-grid" style="grid-template-columns:repeat(4,1fr);">
            <div class="calc-stat-box">
              <div class="metric-label">Investment Applied</div>
              <div style="font-size:1.25rem;font-weight:800;"><?= Security::e(formatINR((float)$record['investment_amount'])) ?></div>
            </div>
            <div class="calc-stat-box">
              <div class="metric-label">Indicative Shares</div>
              <div style="font-size:1.25rem;font-weight:800;"><?= Security::e(rtrim(rtrim(number_format((float)$record['indicative_shares'], 4), '0'), '.')) ?> <span style="font-size:0.75rem;color:var(--text-muted);">(Indicative)</span></div>
            </div>
            <div class="calc-stat-box">
              <div class="metric-label">Actual Shares Allotted</div>
              <div style="font-size:1.25rem;font-weight:800;color:var(--status-success);">
                <?= !empty($record['shares_allotted']) ? number_format((int)$record['shares_allotted']) . ' Shares' : 'Pending Allotment' ?>
              </div>
            </div>
            <div class="calc-stat-box">
              <div class="metric-label">Share Certificate</div>
              <div style="font-size:1.05rem;font-weight:700;">
                <?php if (!empty($record['certificate_number']) && $record['certificate_status'] === 'VALID'): ?>
                  <a href="verify.php?id=<?= urlencode((string)$record['certificate_number']) ?>" style="color:var(--accent-red-hover);text-decoration:none;"><?= Security::e($record['certificate_number']) ?> →</a>
                <?php else: ?>
                  Not Yet Issued
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:18px;">
            <a href="api/acknowledgement.php?app_id=<?= urlencode((string)$record['application_id']) ?>&email=<?= urlencode($email) ?>" target="_blank" class="btn btn-secondary">Download Application Acknowledgement PDF</a>
            <?php if (!empty($record['certificate_number']) && $record['certificate_status'] === 'VALID'): ?>
              <a href="api/certificate_download.php?cert_no=<?= urlencode((string)$record['certificate_number']) ?>&app_id=<?= urlencode((string)$record['application_id']) ?>&email=<?= urlencode($email) ?>" target="_blank" class="btn btn-primary">Download Share Certificate PDF</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Re-Upload Payment Proof Card (Shown when status == RE-UPLOAD REQUESTED) -->
        <?php if ($record['status'] === 'RE-UPLOAD REQUESTED'): ?>
          <div class="glass-card" id="reupload" style="border-color:rgba(245,158,11,0.55);">
            <span class="eyebrow" style="background:rgba(245,158,11,0.16);color:#fbbf24;border-color:rgba(245,158,11,0.4);">Action Required</span>
            <h3 style="font-size:1.35rem;margin-bottom:8px;">Re-Upload Replacement Payment Proof</h3>
            <?php if (!empty($record['reupload_reason'])): ?>
              <p style="color:#fde68a;margin-bottom:16px;"><strong>Reason from Compliance Team:</strong> <?= Security::e($record['reupload_reason']) ?></p>
            <?php endif; ?>
            <form method="POST" action="track.php" enctype="multipart/form-data" class="form-grid">
              <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
              <input type="hidden" name="action" value="reupload_proof">
              <input type="hidden" name="app_id" value="<?= Security::e($record['application_id']) ?>">
              <input type="hidden" name="email" value="<?= Security::e($email) ?>">
              <div class="form-group">
                <label class="form-label" for="reupload_utr">Confirmed 12-Digit UTR / Reference Number <span class="req">*</span></label>
                <input type="text" class="form-control" id="reupload_utr" name="utr_number" required placeholder="Enter correct UTR number">
              </div>
              <div class="form-group">
                <label class="form-label" for="replacement_proof">Replacement Payment Screenshot / PDF <span class="req">*</span></label>
                <input type="file" class="form-control" id="replacement_proof" name="replacement_proof" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
              </div>
              <div class="form-group full-width">
                <button type="submit" class="btn btn-primary">Submit Replacement Payment Proof →</button>
              </div>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="legal-box" style="margin-top:36px;">
        <strong>Mandatory Statutory Disclaimer:</strong> <?= Security::e(CB_LEGAL_DISCLAIMER) ?>
      </div>
    </div>
  </main>
</body>
</html>
