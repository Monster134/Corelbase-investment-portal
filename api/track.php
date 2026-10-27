<?php
/**
 * CORELBASE PRIVATE LIMITED — JSON APPLICATION TRACKING API (/api/track.php)
 * -----------------------------------------------------------------------------
 * Returns sanitized application status for given `application_id` + `email`.
 * Never exposes PAN, Aadhaar, bank details, admin notes, or payment screenshots.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

$appId = strtoupper(Security::cleanInput($_GET['application_id'] ?? $_POST['application_id'] ?? $_GET['app_id'] ?? '', 40));
$email = strtolower(Security::cleanInput($_GET['email'] ?? $_POST['email'] ?? '', 180));

if ($appId === '' || $email === '') {
    jsonResponse(['success' => false, 'message' => 'Both Application ID and Registered Email are required.'], 422);
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT
            a.application_id,
            a.investor_id,
            a.investment_amount,
            a.indicative_shares,
            a.indicative_ownership_pct,
            a.status,
            a.reupload_reason,
            a.created_at,
            p.verification_status AS payment_status,
            sa.shares_allotted,
            sa.allotment_date,
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Application not found for the provided ID and Email.'], 404);
    }

    jsonResponse([
        'success'     => true,
        'application' => [
            'application_id'           => $row['application_id'],
            'investor_id'              => $row['investor_id'],
            'investment_amount'        => (float)$row['investment_amount'],
            'indicative_shares'        => (float)$row['indicative_shares'],
            'indicative_ownership_pct' => (float)$row['indicative_ownership_pct'],
            'status'                   => $row['status'],
            'payment_status'           => $row['payment_status'] ?? 'UNDER_VERIFICATION',
            'shares_allotted'          => $row['shares_allotted'] !== null ? (int)$row['shares_allotted'] : null,
            'allotment_date'           => $row['allotment_date'],
            'certificate_number'       => $row['certificate_number'],
            'certificate_status'       => $row['certificate_status'],
            'submitted_at_ist'         => formatIST($row['created_at']),
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Unable to query tracking status at this time.'], 500);
}
