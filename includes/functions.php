<?php
/**
 * CORELBASE PRIVATE LIMITED — CORE BUSINESS LOGIC & HELPER FUNCTIONS
 * -----------------------------------------------------------------------------
 * Provides:
 * - Dynamic configuration management (`system_settings` + `CB_DEFAULT_SETTINGS`)
 * - Server-side sequential ID generation (`CB-2026-000001`, `CB-SC-2026-000001`)
 * - Dynamic share & ownership calculation engine
 * - Strict application workflow state transition enforcement
 * - Duplicate detection engine (UTR, PAN, Email, Mobile, Amount+Date)
 * - Immutable audit logging (`audit_logs`) & notifications (`notifications`)
 */

declare(strict_types=1);

require_once __DIR__ . '/security.php';

/**
 * Fetch all system settings merged with defaults.
 */
function getAllSettings(?PDO $pdo = null, bool $publicOnly = false): array
{
    $settings = CB_DEFAULT_SETTINGS;
    if ($publicOnly) {
        // Filter out non-public defaults first
        $privateKeys = [
            'issued_capital', 'subscribed_capital', 'authorized_shares', 'total_issued_shares',
            'session_timeout_minutes', 'max_login_attempts', 'lockout_duration_minutes',
            'director1_signature', 'director2_signature',
            'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
            'smtp_encryption', 'smtp_from_email', 'smtp_from_name'
        ];
        foreach ($privateKeys as $k) {
            unset($settings[$k]);
        }
    }

    try {
        $pdo = $pdo ?? Database::getConnection();
        $sql = $publicOnly
            ? "SELECT `setting_key`, `setting_value` FROM `system_settings` WHERE `is_public` = 1"
            : "SELECT `setting_key`, `setting_value` FROM `system_settings`";
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
    } catch (Throwable $e) {
        // Fallback gracefully to CB_DEFAULT_SETTINGS if database is not yet initialized
    }

    // If publicOnly, respect individual capital visibility flags
    if ($publicOnly) {
        $all = getAllSettings($pdo, false);
        $capitalVisibilityMap = [
            'authorized_capital'  => 'authorized_capital_public',
            'paid_up_capital'     => 'paid_up_capital_public',
            'issued_capital'      => 'issued_capital_public',
            'subscribed_capital'  => 'subscribed_capital_public',
            'authorized_shares'   => 'authorized_shares_public',
            'total_issued_shares' => 'total_issued_shares_public',
            'available_shares'    => 'available_shares_public',
        ];
        foreach ($capitalVisibilityMap as $valKey => $flagKey) {
            if (($all[$flagKey] ?? '0') === '1') {
                $settings[$valKey] = $all[$valKey] ?? '';
                $settings[$flagKey] = '1';
            } else {
                unset($settings[$valKey]);
                $settings[$flagKey] = '0';
            }
        }
    }

    return $settings;
}

/**
 * Fetch a single configuration value.
 */
function getSetting(string $key, ?string $default = null, ?PDO $pdo = null): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = getAllSettings($pdo, false);
    }
    return $cache[$key] ?? ($default ?? (CB_DEFAULT_SETTINGS[$key] ?? ''));
}

/**
 * Update a configuration value in `system_settings`.
 */
function updateSetting(PDO $pdo, string $key, string $value, ?int $adminId = null, string $group = 'general', ?int $isPublic = null): bool
{
    if ($isPublic === null) {
        $stmtCheck = $pdo->prepare("SELECT `is_public`, `setting_group` FROM `system_settings` WHERE `setting_key` = :k LIMIT 1");
        $stmtCheck->execute([':k' => $key]);
        $existing = $stmtCheck->fetch();
        $isPublic = $existing ? (int)$existing['is_public'] : 0;
        $group = $existing ? (string)$existing['setting_group'] : $group;
    }

    $stmt = $pdo->prepare("
        INSERT INTO `system_settings` (`setting_group`, `setting_key`, `setting_value`, `is_public`, `updated_by`, `updated_at`)
        VALUES (:grp, :key, :val, :pub, :admin_id, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            `setting_value` = VALUES(`setting_value`),
            `updated_by` = VALUES(`updated_by`),
            `updated_at` = UTC_TIMESTAMP()
    ");
    return $stmt->execute([
        ':grp'      => $group,
        ':key'      => $key,
        ':val'      => $value,
        ':pub'      => $isPublic,
        ':admin_id' => $adminId,
    ]);
}

/**
 * Calculate dynamic Indicative Shares and Ownership % based on current settings.
 * Formula:
 * - Ownership % = (Investment Amount / Company Valuation) * 100
 * - Indicative Shares = Investment Amount / Issue Price Per Share
 */
function calculateIndicativeInvestment(float $amount, ?array $settings = null): array
{
    $settings = $settings ?? getAllSettings(null, false);
    $valuation = max(1.0, (float)($settings['company_valuation'] ?? 100000000));
    $issuePrice = max(1.0, (float)($settings['issue_price_per_share'] ?? 1000));
    $faceValue = (float)($settings['face_value_per_share'] ?? 10);
    $premium = max(0.0, $issuePrice - $faceValue);
    $minInv = (float)($settings['min_investment'] ?? 5000);
    $maxInv = (float)($settings['max_investment'] ?? 100000);

    $indicativeShares = round($amount / $issuePrice, 4);
    $ownershipPct = round(($amount / $valuation) * 100, 6);

    return [
        'investment_amount'        => round($amount, 2),
        'indicative_shares'        => $indicativeShares,
        'indicative_shares_whole'  => (int)floor($indicativeShares),
        'indicative_ownership_pct' => $ownershipPct,
        'company_valuation'        => $valuation,
        'issue_price_per_share'    => $issuePrice,
        'face_value_per_share'     => $faceValue,
        'premium_per_share'        => $premium,
        'min_investment'           => $minInv,
        'max_investment'           => $maxInv,
        'is_within_limits'         => ($amount >= $minInv && $amount <= $maxInv),
        'disclaimer'               => 'Indicative Share Calculation — Final allotment is subject to company approval and applicable documentation.',
    ];
}

/**
 * Generate sequential server-side ID in format:
 * - Application / Investor ID: CB-2026-000001
 * - Certificate Number: CB-SC-2026-000001
 * - Folio Number: CB-FOLIO-2026-0001
 */
function generateNextSequentialId(PDO $pdo, string $type = 'APPLICATION'): string
{
    $year = '2026'; // Matches Corelbase 2026 round format CB-2026-XXXXXX
    switch (strtoupper($type)) {
        case 'INVESTOR':
            $prefix = "CB-{$year}-";
            $stmt = $pdo->prepare("SELECT `investor_id` FROM `investors` WHERE `investor_id` LIKE :pfx ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([':pfx' => $prefix . '%']);
            $last = (string)$stmt->fetchColumn();
            $nextNum = $last ? ((int)substr($last, strlen($prefix)) + 1) : 1;
            return $prefix . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

        case 'CERTIFICATE':
            $prefix = "CB-SC-{$year}-";
            $stmt = $pdo->prepare("SELECT `certificate_number` FROM `certificates` WHERE `certificate_number` LIKE :pfx ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([':pfx' => $prefix . '%']);
            $last = (string)$stmt->fetchColumn();
            $nextNum = $last ? ((int)substr($last, strlen($prefix)) + 1) : 1;
            return $prefix . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

        case 'FOLIO':
            $prefix = "CB-FOLIO-{$year}-";
            $stmt = $pdo->prepare("SELECT `folio_number` FROM `share_allocations` WHERE `folio_number` LIKE :pfx ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([':pfx' => $prefix . '%']);
            $last = (string)$stmt->fetchColumn();
            $nextNum = $last ? ((int)substr($last, strlen($prefix)) + 1) : 1;
            return $prefix . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);

        case 'APPLICATION':
        default:
            $prefix = "CB-{$year}-";
            $stmt = $pdo->prepare("SELECT `application_id` FROM `applications` WHERE `application_id` LIKE :pfx ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([':pfx' => $prefix . '%']);
            $last = (string)$stmt->fetchColumn();
            $nextNum = $last ? ((int)substr($last, strlen($prefix)) + 1) : 1;
            return $prefix . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Strict Application Status Workflow Matrix (Section 16).
 * Enforces legal & operational workflow transitions.
 */
function getAllowedStatusTransitions(string $currentStatus): array
{
    $matrix = [
        'SUBMITTED' => [
            'PAYMENT UNDER VERIFICATION',
            'REJECTED',
            'DUPLICATE',
            'CANCELLED',
        ],
        'PAYMENT UNDER VERIFICATION' => [
            'PAYMENT VERIFIED',
            'RE-UPLOAD REQUESTED',
            'REJECTED',
            'DUPLICATE',
            'CANCELLED',
        ],
        'RE-UPLOAD REQUESTED' => [
            'PAYMENT UNDER VERIFICATION',
            'PAYMENT VERIFIED',
            'REJECTED',
            'CANCELLED',
        ],
        'PAYMENT VERIFIED' => [
            'APPROVED',
            'RE-UPLOAD REQUESTED',
            'REJECTED',
            'CANCELLED',
        ],
        'APPROVED' => [
            'SHARES ALLOTTED',
            'CANCELLED',
        ],
        'SHARES ALLOTTED' => [
            'CERTIFICATE GENERATED',
            'CANCELLED',
        ],
        'CERTIFICATE GENERATED' => [
            // Terminal positive state (Certificate can be regenerated or revoked separately)
        ],
        'DUPLICATE' => [
            'PAYMENT UNDER VERIFICATION',
            'REJECTED',
            'CANCELLED',
        ],
        'REJECTED' => [
            'PAYMENT UNDER VERIFICATION',
        ],
        'CANCELLED' => [],
    ];

    return $matrix[$currentStatus] ?? [];
}

/**
 * Check if transitioning from $currentStatus to $newStatus is permitted.
 */
function isValidStatusTransition(string $currentStatus, string $newStatus): bool
{
    if ($currentStatus === $newStatus) {
        return true;
    }
    return in_array($newStatus, getAllowedStatusTransitions($currentStatus), true);
}

/**
 * Comprehensive Anti-Fraud & Duplicate Detection Engine (Sections 67 & 68).
 * Checks:
 * - Same UTR number (Critical warning)
 * - Same PAN hash
 * - Same Aadhaar hash
 * - Same Email
 * - Same Mobile
 * - Same Payment Amount + Payment Date
 */
function detectApplicationDuplicates(
    PDO $pdo,
    string $email,
    string $mobile,
    string $panHash,
    string $aadhaarHash,
    string $utrNumber,
    float $amountPaid,
    string $paymentDate
): array {
    $flags = [];
    $isDuplicateUtr = false;

    // 1. Check Duplicate UTR (Highest severity)
    $stmtUtr = $pdo->prepare("SELECT `application_id`, `investor_id` FROM `payments` WHERE UPPER(`utr_number`) = UPPER(:utr) LIMIT 1");
    $stmtUtr->execute([':utr' => trim($utrNumber)]);
    if ($utrMatch = $stmtUtr->fetch()) {
        $isDuplicateUtr = true;
        $flags[] = "WARNING: This UTR ({$utrNumber}) has already been associated with application {$utrMatch['application_id']}.";
    }

    // 2. Check Same PAN
    $stmtPan = $pdo->prepare("SELECT `investor_id`, `full_name` FROM `investors` WHERE `pan_hash` = :ph LIMIT 1");
    $stmtPan->execute([':ph' => $panHash]);
    if ($panMatch = $stmtPan->fetch()) {
        $flags[] = "Same PAN already registered under Investor ID {$panMatch['investor_id']}.";
    }

    // 3. Check Same Aadhaar
    $stmtAadhaar = $pdo->prepare("SELECT `investor_id` FROM `investors` WHERE `aadhaar_hash` = :ah LIMIT 1");
    $stmtAadhaar->execute([':ah' => $aadhaarHash]);
    if ($aadMatch = $stmtAadhaar->fetch()) {
        $flags[] = "Same Aadhaar already registered under Investor ID {$aadMatch['investor_id']}.";
    }

    // 4. Check Same Email
    $stmtEmail = $pdo->prepare("SELECT `investor_id` FROM `investors` WHERE LOWER(`email`) = LOWER(:em) LIMIT 1");
    $stmtEmail->execute([':em' => trim($email)]);
    if ($emailMatch = $stmtEmail->fetch()) {
        $flags[] = "Same Email already associated with Investor ID {$emailMatch['investor_id']}.";
    }

    // 5. Check Same Mobile
    $stmtMob = $pdo->prepare("SELECT `investor_id` FROM `investors` WHERE `mobile` = :mob LIMIT 1");
    $stmtMob->execute([':mob' => trim($mobile)]);
    if ($mobMatch = $stmtMob->fetch()) {
        $flags[] = "Same Mobile number already associated with Investor ID {$mobMatch['investor_id']}.";
    }

    // 6. Check Same Payment Amount + Payment Date
    $stmtAmt = $pdo->prepare("SELECT `application_id` FROM `payments` WHERE `amount_paid` = :amt AND `payment_date` = :pdate LIMIT 1");
    $stmtAmt->execute([':amt' => $amountPaid, ':pdate' => $paymentDate]);
    if ($amtMatch = $stmtAmt->fetch()) {
        $flags[] = "Identical payment amount (₹" . number_format($amountPaid, 2) . ") on {$paymentDate} found in {$amtMatch['application_id']}.";
    }

    return [
        'has_duplicates'   => !empty($flags),
        'is_duplicate_utr' => $isDuplicateUtr,
        'reasons'          => $flags,
        'summary'          => implode(' | ', $flags),
    ];
}

/**
 * Record an immutable entry in `audit_logs` (Section 49).
 */
function logAuditAction(
    PDO $pdo,
    string $action,
    string $entityType,
    string $entityId,
    ?int $adminId = null,
    string $adminEmail = 'SYSTEM',
    ?string $oldValue = null,
    ?string $newValue = null,
    ?string $notes = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `audit_logs`
            (`admin_id`, `admin_email`, `action`, `entity_type`, `entity_id`, `old_value`, `new_value`, `notes`, `ip_address`, `created_at`)
            VALUES (:admin_id, :admin_email, :action, :entity_type, :entity_id, :old_val, :new_val, :notes, :ip, UTC_TIMESTAMP())
        ");
        $stmt->execute([
            ':admin_id'    => $adminId,
            ':admin_email' => $adminEmail,
            ':action'      => $action,
            ':entity_type' => strtoupper($entityType),
            ':entity_id'   => $entityId,
            ':old_val'     => $oldValue,
            ':new_val'     => $newValue,
            ':notes'       => $notes,
            ':ip'          => Security::getClientIp(),
        ]);
    } catch (Throwable $e) {
        Database::logError('Audit log error: ' . $e->getMessage());
    }
}

/**
 * Create an in-portal notification for an investor or admin.
 */
function createNotification(
    PDO $pdo,
    string $targetType,
    string $title,
    string $message,
    ?string $investorId = null,
    ?string $applicationId = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `notifications` (`target_type`, `investor_id`, `application_id`, `title`, `message`, `created_at`)
            VALUES (:tt, :inv_id, :app_id, :title, :msg, UTC_TIMESTAMP())
        ");
        $stmt->execute([
            ':tt'     => strtoupper($targetType),
            ':inv_id' => $investorId,
            ':app_id' => $applicationId,
            ':title'  => $title,
            ':msg'    => $message,
        ]);
    } catch (Throwable $e) {
        Database::logError('Notification insert error: ' . $e->getMessage());
    }
}

/**
 * Format INR currency in Indian numbering style (e.g., ₹10,00,000).
 */
function formatINR(float|int|string $amount, bool $withDecimals = false): string
{
    $num = (float)$amount;
    $isNegative = $num < 0;
    $num = abs($num);
    $parts = explode('.', number_format($num, 2, '.', ''));
    $intPart = $parts[0];
    $decPart = $parts[1] ?? '00';

    if (strlen($intPart) > 3) {
        $lastThree = substr($intPart, -3);
        $rest = substr($intPart, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $formatted = $rest . ',' . $lastThree;
    } else {
        $formatted = $intPart;
    }

    if ($withDecimals && $decPart !== '00') {
        $formatted .= '.' . $decPart;
    }
    return ($isNegative ? '-₹' : '₹') . $formatted;
}

/**
 * Convert UTC database datetime string to Indian Standard Time (Asia/Kolkata).
 */
function formatIST(?string $utcDateTime, string $format = 'd M Y, h:i A \I\S\T'): string
{
    if (empty($utcDateTime)) {
        return '—';
    }
    try {
        $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format($format);
    } catch (Throwable $e) {
        return $utcDateTime;
    }
}

/**
 * Send clean JSON response and terminate script.
 */
function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    Security::sendSecurityHeaders(true);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
