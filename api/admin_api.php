<?php
/**
 * CORELBASE PRIVATE LIMITED — MASTER ADMINISTRATIVE API (/api/admin_api.php)
 * -----------------------------------------------------------------------------
 * Requires authenticated Administrator session + CSRF protection on mutating calls.
 *
 * Supported Actions:
 * - dashboard_overview: 11 KPI cards + 5 chart datasets + recent applications
 * - search_applications: Global instant search & status/date filter
 * - get_investor_profile: Full 6-section profile (Personal, Investment, Payment,
 *   Application, Share Allocation, Certificate) + Payment Documents + Audit Trail
 * - workflow_action: Verify Payment, Reject Payment, Request Re-upload, Mark Duplicate,
 *   Cancel Application, Approve Application (Enforces Section 16 workflow transitions)
 * - add_admin_note / edit_allowed_fields: Update notes or allowed investor/payment fields
 * - record_share_allotment: Record actual shares allotted, folio, allotment date
 * - generate_certificate / regenerate_certificate: Enforces Section 61 preconditions,
 *   generates CB-SC-2026-XXXXXX, QR, PDF, emails certificate, logs audit
 * - update_certificate_status: VALID / REVOKED / SUSPENDED
 * - email_certificate / resend_email: Send any of the 11 branded email templates
 * - get_settings / save_settings: Manage Company, Round, Share Capital (with Public flags),
 *   Bank/UPI, Security, and SMTP configurations
 * - upload_company_asset: Upload replaceable Logo, Seal, Director 1 & 2 Signatures,
 *   Payment QR, Favicon
 * - test_smtp: Test SMTP connection and send diagnostic email
 * - get_audit_logs / get_email_logs: Paginated immutable logs
 * - view_payment_proof: Securely view or download uploaded payment proof with audit logging
 * - export_report: Export CSV, Excel-compatible CSV, or PDF Report for Investment,
 *   Investor, Payment, Application, Certificate, or Monthly reports
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/pdf_generator.php';

Security::initSecureSession();
$admin = Auth::requireAdmin(true);

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Database connection unavailable.'], 503);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Enforce CSRF verification on all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Security::verifyCsrfToken($csrf)) {
        jsonResponse(['success' => false, 'message' => 'Invalid or expired CSRF security token. Please refresh the page.'], 403);
    }
}

switch ($action) {
    // =========================================================================
    // 1. DASHBOARD OVERVIEW (Section 12: 11 KPI Cards + 5 Charts)
    // =========================================================================
    case 'dashboard_overview':
        $kpis = [
            'total_applications'      => (int)$pdo->query("SELECT COUNT(*) FROM `applications`")->fetchColumn(),
            'pending_verification'    => (int)$pdo->query("SELECT COUNT(*) FROM `applications` WHERE `status` IN ('SUBMITTED','PAYMENT UNDER VERIFICATION','RE-UPLOAD REQUESTED')")->fetchColumn(),
            'verified_payments'       => (int)$pdo->query("SELECT COUNT(*) FROM `payments` WHERE `verification_status` = 'VERIFIED'")->fetchColumn(),
            'rejected_applications'   => (int)$pdo->query("SELECT COUNT(*) FROM `applications` WHERE `status` = 'REJECTED'")->fetchColumn(),
            'duplicate_applications'  => (int)$pdo->query("SELECT COUNT(*) FROM `applications` WHERE `status` = 'DUPLICATE' OR `is_potential_duplicate` = 1")->fetchColumn(),
            'cancelled_applications'  => (int)$pdo->query("SELECT COUNT(*) FROM `applications` WHERE `status` = 'CANCELLED'")->fetchColumn(),
            'approved_applications'   => (int)$pdo->query("SELECT COUNT(*) FROM `applications` WHERE `status` IN ('APPROVED','SHARES ALLOTTED','CERTIFICATE GENERATED')")->fetchColumn(),
            'shares_allotted'         => (int)$pdo->query("SELECT COALESCE(SUM(`shares_allotted`), 0) FROM `share_allocations`")->fetchColumn(),
            'total_investment_applied'=> (float)$pdo->query("SELECT COALESCE(SUM(`investment_amount`), 0) FROM `applications` WHERE `status` NOT IN ('CANCELLED','REJECTED')")->fetchColumn(),
            'total_verified_investment'=> (float)$pdo->query("SELECT COALESCE(SUM(a.`investment_amount`), 0) FROM `applications` a INNER JOIN `payments` p ON p.application_ref_id = a.id WHERE p.`verification_status` = 'VERIFIED'")->fetchColumn(),
            'certificates_generated'  => (int)$pdo->query("SELECT COUNT(*) FROM `certificates` WHERE `status` = 'VALID'")->fetchColumn(),
        ];

        // Chart 1 & 2: Applications & Investment Amount Over Time (Last 14 Days)
        $timeSeries = $pdo->query("
            SELECT DATE(`created_at`) AS `day`,
                   COUNT(*) AS `app_count`,
                   COALESCE(SUM(`investment_amount`), 0) AS `total_amount`
            FROM `applications`
            GROUP BY DATE(`created_at`)
            ORDER BY `day` ASC
            LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Chart 3: Status Distribution
        $statusDist = $pdo->query("
            SELECT `status`, COUNT(*) AS `count`
            FROM `applications`
            GROUP BY `status`
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Chart 4: Investor Geographic / State Distribution
        $investorDist = $pdo->query("
            SELECT `state`, COUNT(*) AS `count`
            FROM `investors`
            GROUP BY `state`
            ORDER BY `count` DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Chart 5: Monthly Investment
        $monthlyInv = $pdo->query("
            SELECT DATE_FORMAT(`created_at`, '%Y-%m') AS `month`,
                   COUNT(*) AS `applications`,
                   COALESCE(SUM(`investment_amount`), 0) AS `amount`
            FROM `applications`
            WHERE `status` NOT IN ('REJECTED','CANCELLED')
            GROUP BY DATE_FORMAT(`created_at`, '%Y-%m')
            ORDER BY `month` ASC
            LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'success' => true,
            'kpis'    => $kpis,
            'charts'  => [
                'time_series'           => $timeSeries,
                'status_distribution'   => $statusDist,
                'investor_distribution' => $investorDist,
                'monthly_investment'    => $monthlyInv,
            ],
        ]);

    // =========================================================================
    // 2. ADVANCED GLOBAL SEARCH & FILTERING (Section 13)
    // =========================================================================
    case 'search_applications':
        $q = trim((string)($_GET['q'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $dateFrom = trim((string)($_GET['date_from'] ?? ''));
        $dateTo = trim((string)($_GET['date_to'] ?? ''));

        $where = ['1=1'];
        $params = [];

        if ($q !== '') {
            // Also compute PAN hash in case admin searches exact 10-char PAN
            $panHashSearch = Security::isValidPan($q) ? Security::hashIdentifier($q) : '';
            $where[] = "(
                a.application_id LIKE :q1
                OR a.investor_id LIKE :q2
                OR i.full_name LIKE :q3
                OR i.email LIKE :q4
                OR i.mobile LIKE :q5
                OR i.pan_masked LIKE :q6
                OR (:phash != '' AND i.pan_hash = :phash2)
                OR p.utr_number LIKE :q7
                OR c.certificate_number LIKE :q8
                OR CAST(a.investment_amount AS CHAR) LIKE :q9
            )";
            $like = '%' . $q . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
            $params[':q5'] = $like;
            $params[':q6'] = $like;
            $params[':phash'] = $panHashSearch;
            $params[':phash2'] = $panHashSearch;
            $params[':q7'] = $like;
            $params[':q8'] = $like;
            $params[':q9'] = $like;
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL') {
            if ($statusFilter === 'PENDING') {
                $where[] = "a.status IN ('SUBMITTED','PAYMENT UNDER VERIFICATION')";
            } elseif ($statusFilter === 'DUPLICATE') {
                $where[] = "(a.status = 'DUPLICATE' OR a.is_potential_duplicate = 1 OR p.is_duplicate_utr = 1)";
            } else {
                $where[] = "a.status = :st";
                $params[':st'] = $statusFilter;
            }
        }

        if ($dateFrom !== '') {
            $where[] = "DATE(a.created_at) >= :dfrom";
            $params[':dfrom'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE(a.created_at) <= :dto";
            $params[':dto'] = $dateTo;
        }

        $sql = "
            SELECT
                a.id AS app_pk,
                a.application_id,
                a.investor_id,
                a.investment_amount,
                a.indicative_shares,
                a.indicative_ownership_pct,
                a.status,
                a.is_potential_duplicate,
                a.duplicate_reason,
                a.created_at,
                i.full_name,
                i.email,
                i.mobile,
                i.city,
                i.state,
                i.pan_masked,
                i.aadhaar_masked,
                p.payment_method,
                p.payment_date,
                p.utr_number,
                p.amount_paid,
                p.verification_status AS payment_status,
                p.is_duplicate_utr,
                sa.shares_allotted,
                sa.allotment_date,
                c.certificate_number,
                c.status AS certificate_status
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            LEFT JOIN `share_allocations` sa ON sa.application_ref_id = a.id
            LEFT JOIN `certificates` c ON c.application_ref_id = a.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.id DESC
            LIMIT 250
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['created_at_ist'] = formatIST($r['created_at']);
            $r['amount_formatted'] = formatINR((float)$r['investment_amount']);
        }

        jsonResponse(['success' => true, 'count' => count($rows), 'records' => $rows]);

    // =========================================================================
    // 3. COMPLETE INVESTOR & APPLICATION PROFILE (Section 14)
    // =========================================================================
    case 'get_investor_profile':
        $appId = strtoupper(Security::cleanInput($_GET['application_id'] ?? '', 40));
        if ($appId === '') {
            jsonResponse(['success' => false, 'message' => 'Application ID is required.'], 422);
        }

        $stmt = $pdo->prepare("
            SELECT
                a.*,
                i.full_name, i.email, i.mobile, i.dob, i.address, i.city, i.state, i.pincode, i.country,
                i.pan_encrypted, i.pan_masked, i.aadhaar_encrypted, i.aadhaar_masked, i.kyc_status,
                i.is_duplicate_flagged, i.duplicate_notes,
                p.id AS payment_pk, p.payment_method, p.payment_date, p.utr_number, p.amount_paid,
                p.sender_bank_or_upi, p.verification_status AS payment_verification_status,
                p.is_duplicate_utr, p.admin_remarks AS payment_remarks,
                sa.id AS allocation_pk, sa.folio_number, sa.share_class, sa.shares_allotted,
                sa.distinctive_from, sa.distinctive_to, sa.face_value AS alloc_face_value,
                sa.issue_price AS alloc_issue_price, sa.premium_per_share AS alloc_premium,
                sa.paid_up_value_per_share, sa.total_allotment_amount,
                sa.ownership_percentage AS alloc_ownership_pct, sa.allotment_date,
                sa.board_resolution_ref, sa.allotment_notes,
                c.id AS certificate_pk, c.certificate_number, c.status AS certificate_status,
                c.qr_verification_url, c.pdf_relative_path, c.regeneration_count, c.generated_at AS cert_generated_at
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            LEFT JOIN `share_allocations` sa ON sa.application_ref_id = a.id
            LEFT JOIN `certificates` c ON c.application_ref_id = a.id
            WHERE UPPER(a.application_id) = :aid
            LIMIT 1
        ");
        $stmt->execute([':aid' => $appId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            jsonResponse(['success' => false, 'message' => 'Application not found.'], 404);
        }

        // Decrypt PAN strictly for SUPER_ADMIN role if needed, while keeping Aadhaar masked per Section 14 ("Aadhaar masked")
        $profile['pan_decrypted'] = ($admin['role'] === 'SUPER_ADMIN')
            ? Security::decryptSensitive((string)$profile['pan_encrypted'])
            : $profile['pan_masked'];
        unset($profile['pan_encrypted'], $profile['aadhaar_encrypted']);

        // Fetch uploaded payment proof documents
        $stmtDocs = $pdo->prepare("
            SELECT `id`, `utr_number`, `original_filename`, `stored_filename`, `relative_path`,
                   `mime_type`, `file_extension`, `file_size`, `verification_status`, `uploaded_at`
            FROM `payment_documents`
            WHERE `application_ref_id` = :arid
            ORDER BY `id` DESC
        ");
        $stmtDocs->execute([':arid' => $profile['id']]);
        $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as &$d) {
            $d['uploaded_at_ist'] = formatIST($d['uploaded_at']);
        }

        // Check if UTR is used on any OTHER application (Section 67 anti-fraud warning)
        $stmtDupUtr = $pdo->prepare("
            SELECT `application_id`, `investor_id`
            FROM `payments`
            WHERE UPPER(`utr_number`) = UPPER(:utr) AND `application_id` != :aid
        ");
        $stmtDupUtr->execute([':utr' => $profile['utr_number'] ?? '', ':aid' => $appId]);
        $otherUtrMatches = $stmtDupUtr->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Audit Logs for this Application
        $stmtAudit = $pdo->prepare("
            SELECT `admin_email`, `action`, `old_value`, `new_value`, `notes`, `ip_address`, `created_at`
            FROM `audit_logs`
            WHERE `entity_id` = :eid
            ORDER BY `id` DESC LIMIT 40
        ");
        $stmtAudit->execute([':eid' => $appId]);
        $audits = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
        foreach ($audits as &$au) {
            $au['created_at_ist'] = formatIST($au['created_at']);
        }

        logAuditAction($pdo, 'Application Viewed', 'APPLICATION', $appId, $admin['id'], $admin['email']);

        jsonResponse([
            'success'             => true,
            'profile'             => $profile,
            'documents'           => $documents,
            'other_utr_matches'   => $otherUtrMatches,
            'allowed_transitions' => getAllowedStatusTransitions((string)$profile['status']),
            'audit_logs'          => $audits,
        ]);

    // =========================================================================
    // 4. ADMIN WORKFLOW ACTIONS (Sections 15 & 16)
    // =========================================================================
    case 'workflow_action':
        $appId = strtoupper(Security::cleanInput($_POST['application_id'] ?? '', 40));
        $subAction = Security::cleanInput($_POST['sub_action'] ?? '', 50);
        $notes = Security::cleanInput($_POST['notes'] ?? '', 1000);

        $stmt = $pdo->prepare("
            SELECT a.*, i.full_name, i.email, i.id AS inv_pk, p.id AS pay_pk, p.utr_number, p.verification_status AS pay_status
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            WHERE UPPER(a.application_id) = :aid
            LIMIT 1
        ");
        $stmt->execute([':aid' => $appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            jsonResponse(['success' => false, 'message' => 'Application not found.'], 404);
        }

        $oldStatus = (string)$app['status'];
        $newStatus = $oldStatus;
        $auditTitle = '';
        $emailTemplate = null;

        switch ($subAction) {
            case 'verify_payment':
                $newStatus = 'PAYMENT VERIFIED';
                $auditTitle = 'Payment Verified';
                $emailTemplate = 'payment_verified';
                break;

            case 'reject_payment':
                $newStatus = 'REJECTED';
                $auditTitle = 'Payment Rejected';
                $emailTemplate = 'payment_rejected';
                break;

            case 'request_reupload':
                $newStatus = 'RE-UPLOAD REQUESTED';
                $auditTitle = 'Re-upload Requested';
                $emailTemplate = 'reupload_requested';
                break;

            case 'mark_duplicate':
                $newStatus = 'DUPLICATE';
                $auditTitle = 'Duplicate Marked';
                break;

            case 'cancel_application':
                $newStatus = 'CANCELLED';
                $auditTitle = 'Application Cancelled';
                $emailTemplate = 'application_cancelled';
                break;

            case 'approve_application':
                if ($app['pay_status'] !== 'VERIFIED' && $oldStatus !== 'PAYMENT VERIFIED') {
                    jsonResponse(['success' => false, 'message' => 'Payment must be marked as Verified before approving the application.'], 422);
                }
                $newStatus = 'APPROVED';
                $auditTitle = 'Application Approved';
                $emailTemplate = 'application_approved';
                break;

            default:
                jsonResponse(['success' => false, 'message' => 'Unknown workflow action.'], 422);
        }

        if (!isValidStatusTransition($oldStatus, $newStatus)) {
            jsonResponse([
                'success' => false,
                'message' => "Invalid workflow transition from [{$oldStatus}] to [{$newStatus}]."
            ], 422);
        }

        $pdo->beginTransaction();

        // Update application status & timestamps
        $verifiedCol = ($newStatus === 'PAYMENT VERIFIED') ? ", `verified_by_admin_id` = :aid, `verified_at` = UTC_TIMESTAMP()" : "";
        $approvedCol = ($newStatus === 'APPROVED') ? ", `approved_by_admin_id` = :aid, `approved_at` = UTC_TIMESTAMP()" : "";
        $reuploadCol = ($newStatus === 'RE-UPLOAD REQUESTED') ? ", `reupload_reason` = :notes_col" : "";
        $rejectCol   = ($newStatus === 'REJECTED') ? ", `rejection_reason` = :notes_col" : "";

        $sqlUpd = "
            UPDATE `applications`
            SET `status` = :new_st,
                `admin_notes` = CASE WHEN :notes != '' THEN CONCAT(COALESCE(`admin_notes`, ''), '\n[', UTC_TIMESTAMP(), '] ', :notes2) ELSE `admin_notes` END
                {$verifiedCol} {$approvedCol} {$reuploadCol} {$rejectCol},
                `updated_at` = UTC_TIMESTAMP()
            WHERE `id` = :id
        ";
        $updParams = [
            ':new_st' => $newStatus,
            ':notes'  => $notes,
            ':notes2' => $notes,
            ':id'     => $app['id'],
        ];
        if ($verifiedCol !== '' || $approvedCol !== '') {
            $updParams[':aid'] = $admin['id'];
        }
        if ($reuploadCol !== '' || $rejectCol !== '') {
            $updParams[':notes_col'] = $notes;
        }
        $pdo->prepare($sqlUpd)->execute($updParams);

        // Sync payment table status
        if ($subAction === 'verify_payment') {
            $pdo->prepare("UPDATE `payments` SET `verification_status` = 'VERIFIED', `verified_by` = :aid, `verified_at` = UTC_TIMESTAMP() WHERE `application_ref_id` = :arid")
                ->execute([':aid' => $admin['id'], ':arid' => $app['id']]);
            $pdo->prepare("UPDATE `payment_documents` SET `verification_status` = 'VERIFIED' WHERE `application_ref_id` = :arid AND `verification_status` = 'PENDING'")
                ->execute([':arid' => $app['id']]);
            $pdo->prepare("UPDATE `investors` SET `kyc_status` = 'VERIFIED' WHERE `id` = :iid")
                ->execute([':iid' => $app['inv_pk']]);
        } elseif ($subAction === 'reject_payment') {
            $pdo->prepare("UPDATE `payments` SET `verification_status` = 'REJECTED' WHERE `application_ref_id` = :arid")
                ->execute([':arid' => $app['id']]);
        } elseif ($subAction === 'request_reupload') {
            $pdo->prepare("UPDATE `payments` SET `verification_status` = 'REUPLOAD_REQUESTED' WHERE `application_ref_id` = :arid")
                ->execute([':arid' => $app['id']]);
        } elseif ($subAction === 'mark_duplicate') {
            $pdo->prepare("UPDATE `payments` SET `verification_status` = 'DUPLICATE' WHERE `application_ref_id` = :arid")
                ->execute([':arid' => $app['id']]);
        }

        logAuditAction($pdo, $auditTitle, 'APPLICATION', $appId, $admin['id'], $admin['email'], $oldStatus, $newStatus, $notes);

        createNotification(
            $pdo,
            'INVESTOR',
            "Application {$appId}: {$auditTitle}",
            "Your application status has been updated to {$newStatus}." . ($notes !== '' ? " Note: {$notes}" : ''),
            (string)$app['investor_id'],
            $appId
        );

        $pdo->commit();

        if ($emailTemplate !== null) {
            Mailer::sendTemplate($pdo, $emailTemplate, (string)$app['email'], (string)$app['full_name'], [
                'application_id' => $appId,
                'investor_id'    => $app['investor_id'],
                'amount'         => $app['investment_amount'],
                'utr_number'     => $app['utr_number'],
                'reason'         => $notes,
            ], $admin['id']);
        }

        jsonResponse([
            'success'    => true,
            'message'    => "{$auditTitle} completed for {$appId}.",
            'new_status' => $newStatus,
        ]);

    // =========================================================================
    // 5. ADD ADMIN NOTE / EDIT ALLOWED FIELDS (Section 15)
    // =========================================================================
    case 'save_admin_note':
        $appId = strtoupper(Security::cleanInput($_POST['application_id'] ?? '', 40));
        $note = Security::cleanInput($_POST['admin_note'] ?? '', 1500);
        $editName = Security::cleanInput($_POST['full_name'] ?? '', 150);
        $editMobile = Security::cleanInput($_POST['mobile'] ?? '', 20);
        $editUtr = Security::cleanInput($_POST['utr_number'] ?? '', 80);

        $stmt = $pdo->prepare("SELECT `id`, `investor_ref_id`, `admin_notes` FROM `applications` WHERE UPPER(`application_id`) = :aid LIMIT 1");
        $stmt->execute([':aid' => $appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            jsonResponse(['success' => false, 'message' => 'Application not found.'], 404);
        }

        if ($note !== '') {
            $stamped = '[' . date('Y-m-d H:i') . ' ' . $admin['email'] . '] ' . $note;
            $combined = trim(($app['admin_notes'] ? $app['admin_notes'] . "\n" : '') . $stamped);
            $pdo->prepare("UPDATE `applications` SET `admin_notes` = :n WHERE `id` = :id")
                ->execute([':n' => $combined, ':id' => $app['id']]);
        }

        if ($editName !== '' && $editMobile !== '') {
            $pdo->prepare("UPDATE `investors` SET `full_name` = :fn, `mobile` = :mob WHERE `id` = :iid")
                ->execute([':fn' => $editName, ':mob' => $editMobile, ':iid' => $app['investor_ref_id']]);
        }
        if ($editUtr !== '') {
            $pdo->prepare("UPDATE `payments` SET `utr_number` = :utr WHERE `application_ref_id` = :arid")
                ->execute([':utr' => $editUtr, ':arid' => $app['id']]);
        }

        logAuditAction($pdo, 'Application Edited / Note Added', 'APPLICATION', $appId, $admin['id'], $admin['email'], null, null, $note);
        jsonResponse(['success' => true, 'message' => 'Application notes and allowed fields updated.']);

    // =========================================================================
    // 6. RECORD ACTUAL SHARE ALLOTMENT (Sections 14, 16, 60, 61)
    // =========================================================================
    case 'record_share_allotment':
        $appId = strtoupper(Security::cleanInput($_POST['application_id'] ?? '', 40));
        $sharesAllotted = (int)($_POST['shares_allotted'] ?? 0);
        $allotmentDate = Security::cleanInput($_POST['allotment_date'] ?? '', 20);
        $folioNumber = Security::cleanInput($_POST['folio_number'] ?? '', 60);
        $shareClass = Security::cleanInput($_POST['share_class'] ?? 'Equity Shares', 80);
        $faceValue = (float)($_POST['face_value'] ?? getSetting('face_value_per_share', '10', $pdo));
        $issuePrice = (float)($_POST['issue_price'] ?? getSetting('issue_price_per_share', '1000', $pdo));
        $boardResRef = Security::cleanInput($_POST['board_resolution_ref'] ?? '', 120);
        $notes = Security::cleanInput($_POST['allotment_notes'] ?? '', 500);

        if ($sharesAllotted <= 0) {
            jsonResponse(['success' => false, 'message' => 'Actual shares allotted must be greater than zero.'], 422);
        }
        if ($allotmentDate === '' || strtotime($allotmentDate) === false) {
            jsonResponse(['success' => false, 'message' => 'Valid allotment date is required.'], 422);
        }

        $stmt = $pdo->prepare("
            SELECT a.*, i.full_name, i.email, p.verification_status AS pay_status
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            WHERE UPPER(a.application_id) = :aid
            LIMIT 1
        ");
        $stmt->execute([':aid' => $appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            jsonResponse(['success' => false, 'message' => 'Application not found.'], 404);
        }

        // Enforce Section 60 & 61: Payment must be VERIFIED and Application must be APPROVED (or already SHARES ALLOTTED)
        if ($app['pay_status'] !== 'VERIFIED') {
            jsonResponse(['success' => false, 'message' => 'Cannot record share allotment until Payment is Verified.'], 422);
        }
        if (!in_array($app['status'], ['APPROVED', 'SHARES ALLOTTED', 'CERTIFICATE GENERATED'], true)) {
            jsonResponse(['success' => false, 'message' => 'Application must be in APPROVED status before recording share allotment.'], 422);
        }

        if ($folioNumber === '') {
            $folioNumber = generateNextSequentialId($pdo, 'FOLIO');
        }

        $premium = max(0.0, $issuePrice - $faceValue);
        $totalAmount = round($sharesAllotted * $issuePrice, 2);
        $valuation = max(1.0, (float)getSetting('company_valuation', '100000000', $pdo));
        $ownershipPct = round(($totalAmount / $valuation) * 100, 6);

        $pdo->beginTransaction();

        $stmtAlloc = $pdo->prepare("
            INSERT INTO `share_allocations`
            (`application_ref_id`, `application_id`, `investor_ref_id`, `investor_id`, `folio_number`, `share_class`,
             `shares_allotted`, `face_value`, `issue_price`, `premium_per_share`, `paid_up_value_per_share`,
             `total_allotment_amount`, `ownership_percentage`, `allotment_date`, `board_resolution_ref`,
             `allotment_notes`, `allotted_by_admin_id`, `created_at`)
            VALUES
            (:arid, :aid, :iref, :iid, :folio, :sclass, :shares, :fv, :ip, :prem, :paidup, :tot, :own, :adate, :bref, :notes, :admin_id, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                `folio_number` = VALUES(`folio_number`),
                `share_class` = VALUES(`share_class`),
                `shares_allotted` = VALUES(`shares_allotted`),
                `face_value` = VALUES(`face_value`),
                `issue_price` = VALUES(`issue_price`),
                `premium_per_share` = VALUES(`premium_per_share`),
                `total_allotment_amount` = VALUES(`total_allotment_amount`),
                `ownership_percentage` = VALUES(`ownership_percentage`),
                `allotment_date` = VALUES(`allotment_date`),
                `board_resolution_ref` = VALUES(`board_resolution_ref`),
                `allotment_notes` = VALUES(`allotment_notes`),
                `updated_at` = UTC_TIMESTAMP()
        ");
        $stmtAlloc->execute([
            ':arid'     => $app['id'],
            ':aid'      => $appId,
            ':iref'     => $app['investor_ref_id'],
            ':iid'      => $app['investor_id'],
            ':folio'    => $folioNumber,
            ':sclass'   => $shareClass,
            ':shares'   => $sharesAllotted,
            ':fv'       => $faceValue,
            ':ip'       => $issuePrice,
            ':prem'     => $premium,
            ':paidup'   => $faceValue,
            ':tot'      => $totalAmount,
            ':own'      => $ownershipPct,
            ':adate'    => $allotmentDate,
            ':bref'     => $boardResRef,
            ':notes'    => $notes,
            ':admin_id' => $admin['id'],
        ]);

        if ($app['status'] === 'APPROVED') {
            $pdo->prepare("UPDATE `applications` SET `status` = 'SHARES ALLOTTED', `updated_at` = UTC_TIMESTAMP() WHERE `id` = :id")
                ->execute([':id' => $app['id']]);
        }

        logAuditAction(
            $pdo,
            'Share Allocation Recorded',
            'APPLICATION',
            $appId,
            $admin['id'],
            $admin['email'],
            (string)$app['status'],
            'SHARES ALLOTTED',
            "Shares: {$sharesAllotted}, Folio: {$folioNumber}, Date: {$allotmentDate}"
        );

        createNotification(
            $pdo,
            'INVESTOR',
            "Shares Allotted for {$appId}",
            "{$sharesAllotted} {$shareClass} have been allotted under Folio {$folioNumber} dated {$allotmentDate}.",
            (string)$app['investor_id'],
            $appId
        );

        $pdo->commit();

        Mailer::sendTemplate($pdo, 'shares_allotted', (string)$app['email'], (string)$app['full_name'], [
            'application_id' => $appId,
            'investor_id'    => $app['investor_id'],
            'shares'         => $sharesAllotted,
            'folio_number'   => $folioNumber,
            'allotment_date' => $allotmentDate,
        ], $admin['id']);

        jsonResponse([
            'success'      => true,
            'message'      => "Recorded allotment of {$sharesAllotted} shares under Folio {$folioNumber}.",
            'folio_number' => $folioNumber,
            'new_status'   => 'SHARES ALLOTTED',
        ]);

    // =========================================================================
    // 7. GENERATE / REGENERATE SHARE CERTIFICATE (Sections 18, 19, 20, 61)
    // =========================================================================
    case 'generate_certificate':
        $appId = strtoupper(Security::cleanInput($_POST['application_id'] ?? '', 40));

        $stmt = $pdo->prepare("
            SELECT
                a.*, i.full_name, i.email,
                p.verification_status AS pay_status,
                sa.id AS alloc_id, sa.folio_number, sa.share_class, sa.shares_allotted,
                sa.face_value, sa.paid_up_value_per_share, sa.issue_price,
                sa.ownership_percentage, sa.allotment_date,
                c.id AS existing_cert_id, c.certificate_number AS existing_cert_no
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            LEFT JOIN `share_allocations` sa ON sa.application_ref_id = a.id
            LEFT JOIN `certificates` c ON c.application_ref_id = a.id
            WHERE UPPER(a.application_id) = :aid
            LIMIT 1
        ");
        $stmt->execute([':aid' => $appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Application not found.'], 404);
        }

        // Strict Section 61 Precondition Enforcement:
        // 1. Payment = Verified
        // 2. Application = Approved / Shares Allotted
        // 3. Actual shares allotted = entered (> 0)
        // 4. Allotment date = entered
        if ($row['pay_status'] !== 'VERIFIED') {
            jsonResponse(['success' => false, 'message' => 'Certificate Generation Blocked: Payment is not Verified.'], 422);
        }
        if (!in_array($row['status'], ['APPROVED', 'SHARES ALLOTTED', 'CERTIFICATE GENERATED'], true)) {
            jsonResponse(['success' => false, 'message' => 'Certificate Generation Blocked: Application is not Approved.'], 422);
        }
        if (empty($row['alloc_id']) || (int)$row['shares_allotted'] <= 0 || empty($row['allotment_date'])) {
            jsonResponse(['success' => false, 'message' => 'Certificate Generation Blocked: Actual share allotment and allotment date must be recorded first.'], 422);
        }

        $settings = getAllSettings($pdo, false);
        $baseUrl = rtrim($settings['portal_base_url'] ?? 'https://YOUR-DOMAIN', '/');

        $isRegeneration = !empty($row['existing_cert_id']);
        $certNo = $isRegeneration
            ? (string)$row['existing_cert_no']
            : generateNextSequentialId($pdo, 'CERTIFICATE');

        $verifyToken = bin2hex(random_bytes(24));
        $qrVerifyUrl = "{$baseUrl}/verify.php?id=" . urlencode($certNo);

        $certData = [
            'certificate_number'   => $certNo,
            'investor_id'          => $row['investor_id'],
            'investor_name'        => $row['full_name'],
            'folio_number'         => $row['folio_number'],
            'shares_count'         => (int)$row['shares_allotted'],
            'share_class'          => $row['share_class'],
            'face_value'           => (float)$row['face_value'],
            'paid_up_value'        => (float)$row['paid_up_value_per_share'],
            'issue_price'          => (float)$row['issue_price'],
            'ownership_percentage' => (float)$row['ownership_percentage'],
            'allotment_date'       => $row['allotment_date'],
            'qr_verification_url'  => $qrVerifyUrl,
            'status'               => 'VALID',
        ];

        $pdfRelPath = PDFGenerator::generateShareCertificatePDF($certData, $settings);

        $pdo->beginTransaction();

        if ($isRegeneration) {
            $pdo->prepare("
                UPDATE `certificates`
                SET `investor_name` = :iname,
                    `folio_number` = :folio,
                    `shares_count` = :scount,
                    `share_class` = :sclass,
                    `face_value` = :fv,
                    `paid_up_value` = :puv,
                    `issue_price` = :ip,
                    `ownership_percentage` = :own,
                    `allotment_date` = :adate,
                    `qr_verification_url` = :qurl,
                    `pdf_relative_path` = :ppath,
                    `status` = 'VALID',
                    `regeneration_count` = `regeneration_count` + 1,
                    `updated_at` = UTC_TIMESTAMP()
                WHERE `id` = :cid
            ")->execute([
                ':iname'  => $row['full_name'],
                ':folio'  => $row['folio_number'],
                ':scount' => (int)$row['shares_allotted'],
                ':sclass' => $row['share_class'],
                ':fv'     => (float)$row['face_value'],
                ':puv'    => (float)$row['paid_up_value_per_share'],
                ':ip'     => (float)$row['issue_price'],
                ':own'    => (float)$row['ownership_percentage'],
                ':adate'  => $row['allotment_date'],
                ':qurl'   => $qrVerifyUrl,
                ':ppath'  => $pdfRelPath,
                ':cid'    => $row['existing_cert_id'],
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO `certificates`
                (`certificate_number`, `allocation_id`, `application_ref_id`, `application_id`,
                 `investor_ref_id`, `investor_id`, `investor_name`, `folio_number`,
                 `shares_count`, `share_class`, `face_value`, `paid_up_value`, `issue_price`,
                 `ownership_percentage`, `allotment_date`, `verification_token`, `qr_verification_url`,
                 `pdf_relative_path`, `status`, `issued_by`, `generated_by_admin_id`, `generated_at`)
                VALUES
                (:cno, :alid, :arid, :aid, :iref, :iid, :iname, :folio, :scount, :sclass, :fv, :puv, :ip,
                 :own, :adate, :vtok, :qurl, :ppath, 'VALID', :iss, :admin_id, UTC_TIMESTAMP())
            ")->execute([
                ':cno'      => $certNo,
                ':alid'     => $row['alloc_id'],
                ':arid'     => $row['id'],
                ':aid'      => $appId,
                ':iref'     => $row['investor_ref_id'],
                ':iid'      => $row['investor_id'],
                ':iname'    => $row['full_name'],
                ':folio'    => $row['folio_number'],
                ':scount'   => (int)$row['shares_allotted'],
                ':sclass'   => $row['share_class'],
                ':fv'       => (float)$row['face_value'],
                ':puv'      => (float)$row['paid_up_value_per_share'],
                ':ip'       => (float)$row['issue_price'],
                ':own'      => (float)$row['ownership_percentage'],
                ':adate'    => $row['allotment_date'],
                ':vtok'     => $verifyToken,
                ':qurl'     => $qrVerifyUrl,
                ':ppath'    => $pdfRelPath,
                ':iss'      => $settings['company_name'] ?? 'Corelbase Private Limited',
                ':admin_id' => $admin['id'],
            ]);
        }

        // Update application status to CERTIFICATE GENERATED
        $pdo->prepare("UPDATE `applications` SET `status` = 'CERTIFICATE GENERATED', `updated_at` = UTC_TIMESTAMP() WHERE `id` = :id")
            ->execute([':id' => $row['id']]);

        $auditAct = $isRegeneration ? 'Certificate Regenerated' : 'Certificate Generated';
        logAuditAction($pdo, $auditAct, 'CERTIFICATE', $certNo, $admin['id'], $admin['email'], (string)$row['status'], 'CERTIFICATE GENERATED', "Application: {$appId}");

        createNotification(
            $pdo,
            'INVESTOR',
            "Share Certificate {$certNo} Available",
            "Your official digital Share Certificate ({$certNo}) has been generated and is ready for download.",
            (string)$row['investor_id'],
            $appId
        );

        $pdo->commit();

        // Dispatch Certificate Email (Section 29)
        Mailer::sendTemplate($pdo, 'certificate_available', (string)$row['email'], (string)$row['full_name'], [
            'application_id'     => $appId,
            'investor_id'        => $row['investor_id'],
            'certificate_number' => $certNo,
            'shares'             => $row['shares_allotted'],
        ], $admin['id']);

        jsonResponse([
            'success'            => true,
            'message'            => "{$auditAct} successfully: {$certNo}",
            'certificate_number' => $certNo,
            'pdf_url'            => "../api/certificate_download.php?cert_no=" . urlencode($certNo),
            'verify_url'         => "../verify.php?id=" . urlencode($certNo),
        ]);

    // =========================================================================
    // 8. REVOKE / SUSPEND / RESTORE CERTIFICATE (Section 20)
    // =========================================================================
    case 'update_certificate_status':
        $certNo = strtoupper(Security::cleanInput($_POST['certificate_number'] ?? '', 50));
        $newStatus = strtoupper(Security::cleanInput($_POST['status'] ?? 'VALID', 20));
        $reason = Security::cleanInput($_POST['reason'] ?? '', 500);

        if (!in_array($newStatus, ['VALID', 'REVOKED', 'SUSPENDED'], true)) {
            jsonResponse(['success' => false, 'message' => 'Invalid certificate status.'], 422);
        }

        $pdo->prepare("UPDATE `certificates` SET `status` = :st, `revocation_reason` = :r, `updated_at` = UTC_TIMESTAMP() WHERE UPPER(`certificate_number`) = :cno")
            ->execute([':st' => $newStatus, ':r' => $reason, ':cno' => $certNo]);

        logAuditAction($pdo, "Certificate Status -> {$newStatus}", 'CERTIFICATE', $certNo, $admin['id'], $admin['email'], null, $newStatus, $reason);
        jsonResponse(['success' => true, 'message' => "Certificate {$certNo} status updated to {$newStatus}."]);

    // =========================================================================
    // 9. EMAIL CERTIFICATE / RESEND NOTIFICATION EMAIL (Section 15 & 29)
    // =========================================================================
    case 'resend_email':
        $appId = strtoupper(Security::cleanInput($_POST['application_id'] ?? '', 40));
        $templateKey = Security::cleanInput($_POST['template_key'] ?? 'application_submitted', 60);

        $stmt = $pdo->prepare("
            SELECT a.*, i.full_name, i.email, p.utr_number, sa.shares_allotted, sa.folio_number, sa.allotment_date, c.certificate_number
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            LEFT JOIN `share_allocations` sa ON sa.application_ref_id = a.id
            LEFT JOIN `certificates` c ON c.application_ref_id = a.id
            WHERE UPPER(a.application_id) = :aid
            LIMIT 1
        ");
        $stmt->execute([':aid' => $appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Application not found.'], 404);
        }

        $res = Mailer::sendTemplate($pdo, $templateKey, (string)$row['email'], (string)$row['full_name'], [
            'application_id'     => $row['application_id'],
            'investor_id'        => $row['investor_id'],
            'amount'             => $row['investment_amount'],
            'utr_number'         => $row['utr_number'],
            'shares'             => $row['shares_allotted'] ?? $row['indicative_shares'],
            'folio_number'       => $row['folio_number'] ?? '',
            'allotment_date'     => $row['allotment_date'] ?? '',
            'certificate_number' => $row['certificate_number'] ?? '',
        ], $admin['id']);

        logAuditAction($pdo, 'Email Sent (' . $templateKey . ')', 'APPLICATION', $appId, $admin['id'], $admin['email']);
        jsonResponse($res);

    // =========================================================================
    // 10. SYSTEM, SHARE CAPITAL & SMTP SETTINGS (Sections 3, 4, 30, 50)
    // =========================================================================
    case 'get_settings':
        $all = getAllSettings($pdo, false);
        // Mask SMTP password on retrieval so it isn't sent in plain text
        if (!empty($all['smtp_password']) && $all['smtp_password'] !== 'SMTP_PASSWORD_PLACEHOLDER') {
            $all['smtp_password_masked'] = '••••••••••••';
        }
        unset($all['smtp_password']);
        jsonResponse(['success' => true, 'settings' => $all]);

    case 'save_settings':
        $posted = $_POST['settings'] ?? [];
        if (!is_array($posted)) {
            jsonResponse(['success' => false, 'message' => 'Invalid settings payload.'], 422);
        }

        $publicKeys = [
            'company_name', 'company_short_name', 'company_cin', 'company_gstin',
            'company_registered_office', 'company_email', 'company_phone', 'company_website',
            'portal_base_url', 'timezone', 'round_name', 'round_status',
            'target_investment', 'company_valuation', 'min_investment', 'max_investment',
            'face_value_per_share', 'issue_price_per_share', 'premium_per_share', 'share_class_default',
            'authorized_capital', 'authorized_capital_public',
            'paid_up_capital', 'paid_up_capital_public',
            'issued_capital_public', 'subscribed_capital_public',
            'authorized_shares_public', 'total_issued_shares_public',
            'available_shares', 'available_shares_public',
            'bank_name', 'account_name', 'account_number', 'ifsc_code', 'account_type',
            'upi_id', 'payment_qr_image', 'payment_instructions', 'allowed_payment_methods',
            'max_upload_size_mb', 'allowed_extensions',
            'company_logo', 'company_seal', 'director1_name', 'director1_designation',
            'director2_name', 'director2_designation', 'favicon'
        ];

        $pdo->beginTransaction();
        foreach ($posted as $k => $v) {
            $cleanKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k));
            if ($cleanKey === '') {
                continue;
            }
            // Do not overwrite smtp_password if masked placeholder submitted
            if ($cleanKey === 'smtp_password' && (trim((string)$v) === '' || trim((string)$v) === '••••••••••••')) {
                continue;
            }
            $isPub = in_array($cleanKey, $publicKeys, true) ? 1 : 0;
            updateSetting($pdo, $cleanKey, trim((string)$v), $admin['id'], 'general', $isPub);
        }

        // Auto-recompute premium_per_share if issue_price or face_value updated
        if (isset($posted['issue_price_per_share']) || isset($posted['face_value_per_share'])) {
            $ip = (float)($posted['issue_price_per_share'] ?? getSetting('issue_price_per_share', '1000', $pdo));
            $fv = (float)($posted['face_value_per_share'] ?? getSetting('face_value_per_share', '10', $pdo));
            updateSetting($pdo, 'premium_per_share', (string)max(0, $ip - $fv), $admin['id'], 'capital', 1);
        }

        logAuditAction($pdo, 'Admin Settings Changed', 'SETTINGS', 'SYSTEM_SETTINGS', $admin['id'], $admin['email']);
        $pdo->commit();
        jsonResponse(['success' => true, 'message' => 'System configuration saved successfully.']);

    // =========================================================================
    // 11. UPLOAD REPLACEABLE COMPANY ASSETS (Section 51)
    // =========================================================================
    case 'upload_company_asset':
        $assetKey = Security::cleanInput($_POST['asset_key'] ?? '', 50);
        $allowedAssetKeys = [
            'company_logo'        => 1,
            'company_seal'        => 1,
            'director1_signature' => 0,
            'director2_signature' => 0,
            'payment_qr_image'    => 1,
            'favicon'             => 1,
        ];

        if (!array_key_exists($assetKey, $allowedAssetKeys)) {
            jsonResponse(['success' => false, 'message' => 'Invalid asset type specified.'], 422);
        }
        if (empty($_FILES['asset_file']) || ($_FILES['asset_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'message' => 'Please select a valid PNG, JPG, or WEBP image file.'], 422);
        }

        $file = $_FILES['asset_file'];
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            jsonResponse(['success' => false, 'message' => 'Only PNG, JPG, JPEG, or WEBP images are allowed for company assets.'], 422);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            jsonResponse(['success' => false, 'message' => 'Uploaded file is not a valid image.'], 422);
        }

        $targetDir = CB_ROOT_PATH . '/uploads/assets';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }
        $fileName = $assetKey . '_' . date('Ymd_His') . '.' . $ext;
        $relPath = 'uploads/assets/' . $fileName;
        $fullPath = CB_ROOT_PATH . '/' . $relPath;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            jsonResponse(['success' => false, 'message' => 'Failed to save uploaded asset file.'], 500);
        }

        updateSetting($pdo, $assetKey, $relPath, $admin['id'], 'assets', $allowedAssetKeys[$assetKey]);
        logAuditAction($pdo, "Uploaded Company Asset ({$assetKey})", 'SETTINGS', $assetKey, $admin['id'], $admin['email'], null, $relPath);

        jsonResponse([
            'success'    => true,
            'message'    => 'Company asset updated successfully.',
            'asset_key'  => $assetKey,
            'asset_path' => $relPath,
        ]);

    // =========================================================================
    // 12. TEST SMTP CONNECTION (Section 30)
    // =========================================================================
    case 'test_smtp':
        $testEmail = Security::cleanInput($_POST['test_email'] ?? $admin['email'], 180);
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'message' => 'Please enter a valid recipient email for the SMTP test.'], 422);
        }
        $res = Mailer::testSmtpConnection($pdo, $testEmail, $admin['id']);
        jsonResponse($res);

    // =========================================================================
    // 13. VIEW / DOWNLOAD PAYMENT PROOF DOCUMENT (Sections 34 & 35)
    // =========================================================================
    case 'view_payment_proof':
        $docId = (int)($_GET['doc_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM `payment_documents` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            http_response_code(404);
            exit('Payment proof document record not found.');
        }

        $fullPath = CB_ROOT_PATH . '/' . ltrim((string)$doc['relative_path'], '/');
        if (!file_exists($fullPath)) {
            http_response_code(404);
            exit('Payment proof file not found on disk.');
        }

        $isDownload = isset($_GET['download']);
        logAuditAction(
            $pdo,
            $isDownload ? 'File Downloaded' : 'File Viewed',
            'FILE',
            (string)$doc['application_id'],
            $admin['id'],
            $admin['email'],
            null,
            (string)$doc['stored_filename']
        );

        header('Content-Type: ' . $doc['mime_type']);
        header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . basename((string)$doc['stored_filename']) . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('X-Content-Type-Options: nosniff');
        readfile($fullPath);
        exit;

    // =========================================================================
    // 14. AUDIT LOGS & EMAIL LOGS (Section 49)
    // =========================================================================
    case 'get_logs':
        $type = $_GET['log_type'] ?? 'audit';
        if ($type === 'email') {
            $rows = $pdo->query("SELECT * FROM `email_logs` ORDER BY `id` DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['sent_at_ist'] = formatIST($r['sent_at']);
            }
            jsonResponse(['success' => true, 'logs' => $rows]);
        } else {
            $rows = $pdo->query("SELECT * FROM `audit_logs` ORDER BY `id` DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['created_at_ist'] = formatIST($r['created_at']);
            }
            jsonResponse(['success' => true, 'logs' => $rows]);
        }

    // =========================================================================
    // 15. ADMIN REPORTS & EXPORT (CSV, Excel-CSV, PDF — Sections 47 & 48)
    // =========================================================================
    case 'export_report':
        $reportType = Security::cleanInput($_GET['report_type'] ?? 'application', 40);
        $format = strtolower(Security::cleanInput($_GET['format'] ?? 'csv', 20));
        $dateFrom = Security::cleanInput($_GET['date_from'] ?? '', 20);
        $dateTo = Security::cleanInput($_GET['date_to'] ?? '', 20);
        $statusFilter = Security::cleanInput($_GET['status'] ?? '', 40);

        $where = ['1=1'];
        $params = [];
        if ($dateFrom !== '') {
            $where[] = "DATE(a.created_at) >= :df";
            $params[':df'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE(a.created_at) <= :dt";
            $params[':dt'] = $dateTo;
        }
        if ($statusFilter !== '' && $statusFilter !== 'ALL') {
            $where[] = "a.status = :st";
            $params[':st'] = $statusFilter;
        }

        $sql = "
            SELECT
                a.application_id,
                a.investor_id,
                i.full_name,
                i.email,
                i.mobile,
                i.city,
                i.state,
                i.pan_masked,
                a.investment_amount,
                a.indicative_shares,
                a.indicative_ownership_pct,
                p.payment_method,
                p.payment_date,
                p.utr_number,
                p.verification_status AS payment_status,
                a.status,
                sa.shares_allotted,
                sa.folio_number,
                sa.allotment_date,
                c.certificate_number,
                c.status AS certificate_status,
                a.created_at
            FROM `applications` a
            INNER JOIN `investors` i ON i.id = a.investor_ref_id
            LEFT JOIN `payments` p ON p.application_ref_id = a.id
            LEFT JOIN `share_allocations` sa ON sa.application_ref_id = a.id
            LEFT JOIN `certificates` c ON c.application_ref_id = a.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        logAuditAction($pdo, "Exported Report ({$reportType} - {$format})", 'REPORT', strtoupper($reportType), $admin['id'], $admin['email']);

        if ($format === 'pdf') {
            $totalApplied = 0.0;
            $totalVerified = 0.0;
            $totalShares = 0;
            foreach ($records as $r) {
                $totalApplied += (float)$r['investment_amount'];
                if ($r['payment_status'] === 'VERIFIED') {
                    $totalVerified += (float)$r['investment_amount'];
                }
                $totalShares += (int)($r['shares_allotted'] ?? 0);
            }
            $pdfBytes = PDFGenerator::generateAdminReportPDF(
                ucfirst($reportType) . ' Report',
                $records,
                [
                    'total_applied'  => $totalApplied,
                    'total_verified' => $totalVerified,
                    'total_shares'   => $totalShares,
                ]
            );
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="Corelbase_' . $reportType . '_Report_' . date('Ymd') . '.pdf"');
            echo $pdfBytes;
            exit;
        }

        // CSV or Excel-compatible UTF-8 BOM CSV
        $filename = "Corelbase_" . ucfirst($reportType) . "_Report_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if ($format === 'excel_csv') {
            // Write UTF-8 BOM for Microsoft Excel compatibility
            fwrite($out, "\xEF\xBB\xBF");
        }

        fputcsv($out, [
            'Application ID', 'Investor ID', 'Full Name', 'Email', 'Mobile',
            'City', 'State', 'PAN (Masked)', 'Investment Amount (INR)',
            'Indicative Shares', 'Indicative Ownership %', 'Payment Method',
            'Payment Date', 'UTR Number', 'Payment Verification', 'Application Status',
            'Actual Shares Allotted', 'Folio Number', 'Allotment Date',
            'Certificate Number', 'Certificate Status', 'Submitted Timestamp (IST)'
        ]);

        foreach ($records as $r) {
            fputcsv($out, [
                $r['application_id'],
                $r['investor_id'],
                $r['full_name'],
                $r['email'],
                $r['mobile'],
                $r['city'],
                $r['state'],
                $r['pan_masked'],
                $r['investment_amount'],
                $r['indicative_shares'],
                $r['indicative_ownership_pct'],
                $r['payment_method'],
                $r['payment_date'],
                $r['utr_number'],
                $r['payment_status'],
                $r['status'],
                $r['shares_allotted'] ?? 0,
                $r['folio_number'] ?? '',
                $r['allotment_date'] ?? '',
                $r['certificate_number'] ?? '',
                $r['certificate_status'] ?? '',
                formatIST($r['created_at']),
            ]);
        }
        fclose($out);
        exit;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid API action requested.'], 400);
}
