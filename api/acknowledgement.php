<?php
/**
 * CORELBASE PRIVATE LIMITED — APPLICATION ACKNOWLEDGEMENT PDF ENDPOINT
 * (/api/acknowledgement.php)
 * -----------------------------------------------------------------------------
 * Section 57:
 * Generates and streams an official Application Acknowledgement PDF that clearly
 * states: "Application acknowledgement — not proof of share allotment."
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/pdf_generator.php';

Security::initSecureSession();

$appId = strtoupper(Security::cleanInput($_GET['app_id'] ?? '', 40));
$email = strtolower(Security::cleanInput($_GET['email'] ?? '', 180));
$isAdmin = Auth::isAdminLoggedIn();

if ($appId === '') {
    http_response_code(400);
    exit('Application ID is required.');
}

try {
    $pdo = Database::getConnection();
    $sql = "
        SELECT
            a.application_id, a.investor_id, a.investment_amount, a.indicative_shares,
            a.indicative_ownership_pct, a.status, a.created_at,
            i.full_name, i.email, i.mobile, i.pan_masked, i.aadhaar_masked,
            p.payment_method, p.payment_date, p.utr_number
        FROM `applications` a
        INNER JOIN `investors` i ON i.id = a.investor_ref_id
        LEFT JOIN `payments` p ON p.application_ref_id = a.id
        WHERE UPPER(a.application_id) = :aid
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':aid' => $appId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit('Application record not found.');
    }

    // Authorize: either Admin, or logged-in Investor matching record, or matching email parameter
    $investorSessionMatch = !empty($_SESSION['investor_code']) && strtoupper((string)$_SESSION['investor_code']) === strtoupper((string)$row['investor_id']);
    $emailMatch = ($email !== '' && strtolower((string)$row['email']) === $email);

    if (!$isAdmin && !$investorSessionMatch && !$emailMatch) {
        http_response_code(403);
        exit('Please provide your registered email address to download your Application Acknowledgement PDF.');
    }

    $pdfBinary = PDFGenerator::generateAcknowledgementPDF($row);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Corelbase_Acknowledgement_' . $row['application_id'] . '.pdf"');
    header('Content-Length: ' . strlen($pdfBinary));
    echo $pdfBinary;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to generate Acknowledgement PDF.');
}
