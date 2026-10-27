<?php
/**
 * CORELBASE PRIVATE LIMITED — INVESTMENT APPLICATION SUBMISSION API (/api/apply.php)
 * -----------------------------------------------------------------------------
 * Handles the 7-step Investor Registration & Manual Payment Submission:
 * - Server-side validation of Personal Details, KYC (PAN & Aadhaar), Investment
 *   Amount limits, Payment Details, UTR, and Payment Proof upload.
 * - AES-256-GCM encryption + masking of PAN (ABCDE****F) and Aadhaar (********1234).
 * - Anti-fraud & Duplicate detection across UTR, PAN, Aadhaar, Email, Mobile, Amount+Date.
 * - Server-side sequential ID generation: CB-2026-000001.
 * - Secure payment screenshot storage: /uploads/payments/YEAR/INVESTOR-ID/UTR.ext
 * - Transactional email dispatch & audit logging.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

Security::initSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// Verify CSRF token if present in session
$submittedCsrf = $_POST['csrf_token'] ?? '';
if (!empty($_SESSION['csrf_token']) && $submittedCsrf !== '' && !Security::verifyCsrfToken($submittedCsrf)) {
    jsonResponse(['success' => false, 'message' => 'Your session security token expired. Please refresh the page and submit again.'], 403);
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Database is not yet initialized. Please complete /install/ setup first.'], 503);
}

$settings = getAllSettings($pdo, false);
if (strtoupper($settings['round_status'] ?? 'OPEN') !== 'OPEN') {
    jsonResponse(['success' => false, 'message' => 'The current investment application round is temporarily closed.'], 400);
}

// 1. Sanitize & Validate Basic Information (Section 6)
$fullName = Security::cleanInput($_POST['full_name'] ?? '', 150);
$email = strtolower(Security::cleanInput($_POST['email'] ?? '', 180));
$mobile = preg_replace('/\D/', '', (string)($_POST['mobile'] ?? ''));
if (strlen($mobile) > 10 && str_starts_with($mobile, '91')) {
    $mobile = substr($mobile, -10);
}
$dob = Security::cleanInput($_POST['dob'] ?? '', 20);
$address = Security::cleanInput($_POST['address'] ?? '', 400);
$city = Security::cleanInput($_POST['city'] ?? '', 100);
$state = Security::cleanInput($_POST['state'] ?? '', 100);
$pincode = Security::cleanInput($_POST['pincode'] ?? '', 15);
$country = Security::cleanInput($_POST['country'] ?? 'India', 80);
$portalPassword = (string)($_POST['portal_password'] ?? '');

if ($fullName === '' || mb_strlen($fullName) < 3) {
    jsonResponse(['success' => false, 'message' => 'Full legal name is required.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 422);
}
if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid 10-digit mobile number.'], 422);
}
if ($dob === '' || strtotime($dob) === false || strtotime($dob) > strtotime('-18 years')) {
    jsonResponse(['success' => false, 'message' => 'Applicant must be at least 18 years old.'], 422);
}
if ($address === '' || $city === '' || $state === '' || $pincode === '') {
    jsonResponse(['success' => false, 'message' => 'Complete residential address, city, state, and PIN code are required.'], 422);
}

// 2. Validate KYC (PAN & Aadhaar)
$panRaw = strtoupper(preg_replace('/\s+/', '', (string)($_POST['pan_number'] ?? '')));
$aadhaarRaw = preg_replace('/\D/', '', (string)($_POST['aadhaar_number'] ?? ''));

if (!Security::isValidPan($panRaw)) {
    jsonResponse(['success' => false, 'message' => 'Invalid PAN format. Expected 10-character PAN (e.g. ABCDE1234F).'], 422);
}
if (!Security::isValidAadhaar($aadhaarRaw)) {
    jsonResponse(['success' => false, 'message' => 'Invalid Aadhaar number. Expected 12 digits.'], 422);
}

// 3. Validate Investment Amount (Section 7)
$investmentAmount = round((float)($_POST['investment_amount'] ?? 0), 2);
$minInv = (float)($settings['min_investment'] ?? 5000);
$maxInv = (float)($settings['max_investment'] ?? 100000);

if ($investmentAmount < $minInv || $investmentAmount > $maxInv) {
    jsonResponse([
        'success' => false,
        'message' => 'Investment amount must be between ' . formatINR($minInv) . ' and ' . formatINR($maxInv) . '.'
    ], 422);
}

// 4. Validate Manual Payment Details (Section 8)
$paymentMethod = Security::cleanInput($_POST['payment_method'] ?? 'Bank Transfer', 40);
if (!in_array($paymentMethod, ['Bank Transfer', 'UPI', 'Other'], true)) {
    $paymentMethod = 'Bank Transfer';
}
$paymentDate = Security::cleanInput($_POST['payment_date'] ?? date('Y-m-d'), 20);
$utrNumber = Security::cleanInput($_POST['utr_number'] ?? '', 90);
$amountPaid = round((float)($_POST['amount_paid'] ?? $investmentAmount), 2);
$senderBankOrUpi = Security::cleanInput($_POST['sender_bank_or_upi'] ?? '', 150);

if ($utrNumber === '' || strlen($utrNumber) < 5) {
    jsonResponse(['success' => false, 'message' => 'UTR number is required.'], 422);
}
if ($paymentDate === '' || strtotime($paymentDate) === false) {
    jsonResponse(['success' => false, 'message' => 'Valid payment date is required.'], 422);
}
if ($senderBankOrUpi === '') {
    jsonResponse(['success' => false, 'message' => 'Please enter the bank or UPI app used for payment.'], 422);
}
if (empty($_FILES['payment_screenshot']) || ($_FILES['payment_screenshot']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    jsonResponse(['success' => false, 'message' => 'Payment proof is required.'], 422);
}

// 5. Compute Encrypted & Masked KYC + Duplicate Detection (Sections 6, 67, 68)
$panEncrypted = Security::encryptSensitive($panRaw);
$panMasked = Security::maskPan($panRaw);
$panHash = Security::hashIdentifier($panRaw);

$aadhaarEncrypted = Security::encryptSensitive($aadhaarRaw);
$aadhaarMasked = Security::maskAadhaar($aadhaarRaw);
$aadhaarHash = Security::hashIdentifier($aadhaarRaw);

$dupCheck = detectApplicationDuplicates(
    $pdo,
    $email,
    $mobile,
    $panHash,
    $aadhaarHash,
    $utrNumber,
    $amountPaid,
    $paymentDate
);

$calc = calculateIndicativeInvestment($investmentAmount, $settings);

try {
    $pdo->beginTransaction();

    // Generate server-side unique IDs (Section 9)
    $investorId = generateNextSequentialId($pdo, 'INVESTOR');
    $applicationId = generateNextSequentialId($pdo, 'APPLICATION');
    // Keep initial Investor ID and Application ID aligned if first submission
    if ($investorId !== $applicationId) {
        $maxNum = max(
            (int)substr($investorId, -6),
            (int)substr($applicationId, -6)
        );
        $aligned = 'CB-2026-' . str_pad((string)$maxNum, 6, '0', STR_PAD_LEFT);
        $investorId = $aligned;
        $applicationId = $aligned;
    }

    // Validate and store payment screenshot under /uploads/payments/2026/CB-2026-XXXXXX/UTR.ext (Section 10 & 36)
    $maxUploadMb = (int)($settings['max_upload_size_mb'] ?? 5);
    $allowedExt = (string)($settings['allowed_extensions'] ?? 'jpg,jpeg,png,webp,pdf');
    $uploadResult = Security::validateAndStorePaymentProof(
        $_FILES['payment_screenshot'],
        $investorId,
        $utrNumber,
        $maxUploadMb,
        $allowedExt
    );

    if (!$uploadResult['success']) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => $uploadResult['message'] ?? 'Payment proof upload failed.'], 422);
    }

    $fileMeta = $uploadResult['data'];

    // Create or link Investor Portal User account (`users` table)
    $userId = null;
    $stmtUserFind = $pdo->prepare("SELECT `id` FROM `users` WHERE LOWER(`email`) = :em LIMIT 1");
    $stmtUserFind->execute([':em' => $email]);
    $existingUserId = $stmtUserFind->fetchColumn();

    if ($existingUserId) {
        $userId = (int)$existingUserId;
    } else {
        $rawPass = (strlen($portalPassword) >= 8) ? $portalPassword : bin2hex(random_bytes(8));
        $passHash = password_hash($rawPass, PASSWORD_DEFAULT);
        $stmtUserIns = $pdo->prepare("
            INSERT INTO `users` (`investor_code`, `email`, `mobile`, `password_hash`, `is_active`, `created_at`)
            VALUES (:icode, :em, :mob, :ph, 1, UTC_TIMESTAMP())
        ");
        $stmtUserIns->execute([
            ':icode' => $investorId,
            ':em'    => $email,
            ':mob'   => $mobile,
            ':ph'    => $passHash,
        ]);
        $userId = (int)$pdo->lastInsertId();
    }

    // Insert into `investors`
    $stmtInv = $pdo->prepare("
        INSERT INTO `investors`
        (`user_id`, `investor_id`, `full_name`, `email`, `mobile`, `dob`, `address`, `city`, `state`, `pincode`, `country`,
         `pan_encrypted`, `pan_masked`, `pan_hash`, `aadhaar_encrypted`, `aadhaar_masked`, `aadhaar_hash`,
         `kyc_status`, `is_duplicate_flagged`, `duplicate_notes`, `created_at`)
        VALUES
        (:uid, :inv_id, :fname, :em, :mob, :dob, :addr, :city, :state, :pin, :country,
         :pan_enc, :pan_mask, :pan_hash, :aad_enc, :aad_mask, :aad_hash,
         'PENDING', :dup_flag, :dup_notes, UTC_TIMESTAMP())
    ");
    $stmtInv->execute([
        ':uid'       => $userId,
        ':inv_id'    => $investorId,
        ':fname'     => $fullName,
        ':em'        => $email,
        ':mob'       => $mobile,
        ':dob'       => $dob,
        ':addr'      => $address,
        ':city'      => $city,
        ':state'     => $state,
        ':pin'       => $pincode,
        ':country'   => $country,
        ':pan_enc'   => $panEncrypted,
        ':pan_mask'  => $panMasked,
        ':pan_hash'  => $panHash,
        ':aad_enc'   => $aadhaarEncrypted,
        ':aad_mask'  => $aadhaarMasked,
        ':aad_hash'  => $aadhaarHash,
        ':dup_flag'  => $dupCheck['has_duplicates'] ? 1 : 0,
        ':dup_notes' => $dupCheck['has_duplicates'] ? $dupCheck['summary'] : null,
    ]);
    $investorPk = (int)$pdo->lastInsertId();

    // Insert into `applications` (Status starts at PAYMENT UNDER VERIFICATION per workflow)
    $appReference = 'REF-' . $applicationId . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $stmtApp = $pdo->prepare("
        INSERT INTO `applications`
        (`application_id`, `investor_ref_id`, `investor_id`, `application_reference`,
         `investment_amount`, `indicative_shares`, `indicative_ownership_pct`,
         `configured_valuation`, `configured_issue_price`, `configured_face_value`, `configured_premium`,
         `status`, `is_potential_duplicate`, `duplicate_reason`, `declaration_accepted`,
         `submitted_ip`, `user_agent`, `created_at`)
        VALUES
        (:app_id, :inv_ref, :inv_id, :app_ref,
         :amt, :ind_shares, :ind_own,
         :val, :iprice, :fval, :prem,
         'PAYMENT UNDER VERIFICATION', :is_dup, :dup_reason, 1,
         :ip, :ua, UTC_TIMESTAMP())
    ");
    $stmtApp->execute([
        ':app_id'     => $applicationId,
        ':inv_ref'    => $investorPk,
        ':inv_id'     => $investorId,
        ':app_ref'    => $appReference,
        ':amt'        => $calc['investment_amount'],
        ':ind_shares' => $calc['indicative_shares'],
        ':ind_own'    => $calc['indicative_ownership_pct'],
        ':val'        => $calc['company_valuation'],
        ':iprice'     => $calc['issue_price_per_share'],
        ':fval'       => $calc['face_value_per_share'],
        ':prem'       => $calc['premium_per_share'],
        ':is_dup'     => $dupCheck['has_duplicates'] ? 1 : 0,
        ':dup_reason' => $dupCheck['has_duplicates'] ? $dupCheck['summary'] : null,
        ':ip'         => Security::getClientIp(),
        ':ua'         => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250),
    ]);
    $applicationPk = (int)$pdo->lastInsertId();

    // Insert into `payments`
    $stmtPay = $pdo->prepare("
        INSERT INTO `payments`
        (`application_ref_id`, `application_id`, `investor_id`, `payment_method`, `payment_date`,
         `utr_number`, `amount_paid`, `sender_bank_or_upi`, `verification_status`, `is_duplicate_utr`, `created_at`)
        VALUES
        (:app_ref, :app_id, :inv_id, :pmethod, :pdate, :utr, :paid, :sender, 'UNDER_VERIFICATION', :dup_utr, UTC_TIMESTAMP())
    ");
    $stmtPay->execute([
        ':app_ref' => $applicationPk,
        ':app_id'  => $applicationId,
        ':inv_id'  => $investorId,
        ':pmethod' => $paymentMethod,
        ':pdate'   => $paymentDate,
        ':utr'     => $utrNumber,
        ':paid'    => $amountPaid,
        ':sender'  => $senderBankOrUpi,
        ':dup_utr' => $dupCheck['is_duplicate_utr'] ? 1 : 0,
    ]);
    $paymentPk = (int)$pdo->lastInsertId();

    // Insert into `payment_documents`
    $stmtDoc = $pdo->prepare("
        INSERT INTO `payment_documents`
        (`payment_id`, `application_ref_id`, `investor_id`, `application_id`, `utr_number`,
         `original_filename`, `stored_filename`, `relative_path`, `mime_type`, `file_extension`,
         `file_size`, `sha256_checksum`, `verification_status`, `uploaded_at`)
        VALUES
        (:pid, :app_ref, :inv_id, :app_id, :utr, :orig, :stored, :rel, :mime, :ext, :fsize, :sha, 'PENDING', UTC_TIMESTAMP())
    ");
    $stmtDoc->execute([
        ':pid'     => $paymentPk,
        ':app_ref' => $applicationPk,
        ':inv_id'  => $investorId,
        ':app_id'  => $applicationId,
        ':utr'     => $utrNumber,
        ':orig'    => $fileMeta['original_filename'],
        ':stored'  => $fileMeta['stored_filename'],
        ':rel'     => $fileMeta['relative_path'],
        ':mime'    => $fileMeta['mime_type'],
        ':ext'     => $fileMeta['file_extension'],
        ':fsize'   => $fileMeta['file_size'],
        ':sha'     => $fileMeta['sha256_checksum'],
    ]);

    // Audit & Notifications
    logAuditAction(
        $pdo,
        'Application Submitted',
        'APPLICATION',
        $applicationId,
        null,
        $email,
        null,
        'PAYMENT UNDER VERIFICATION',
        $dupCheck['has_duplicates'] ? ('Potential Duplicate Flagged: ' . $dupCheck['summary']) : "UTR: {$utrNumber}"
    );

    createNotification(
        $pdo,
        'INVESTOR',
        "Application {$applicationId} Received",
        "Your investment application for " . formatINR($investmentAmount) . " (UTR: {$utrNumber}) has been received and is Under Verification.",
        $investorId,
        $applicationId
    );

    createNotification(
        $pdo,
        'ADMIN',
        "New Application: {$applicationId}",
        "{$fullName} submitted an application for " . formatINR($investmentAmount) . " (UTR: {$utrNumber})." . ($dupCheck['has_duplicates'] ? ' [POTENTIAL DUPLICATE]' : ''),
        $investorId,
        $applicationId
    );

    $pdo->commit();

    // Send confirmation emails (non-blocking if SMTP not yet live)
    Mailer::sendTemplate($pdo, 'application_submitted', $email, $fullName, [
        'application_id' => $applicationId,
        'investor_id'    => $investorId,
        'amount'         => $investmentAmount,
        'utr_number'     => $utrNumber,
    ]);

    jsonResponse([
        'success'        => true,
        'message'        => 'Application submitted successfully',
        'application_id' => $applicationId,
        'investor_id'    => $investorId,
        'status'         => 'PAYMENT UNDER VERIFICATION',
        'amount'         => $investmentAmount,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Database::logError('Application submission error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Unable to process your application. Please try again.'], 500);
}
