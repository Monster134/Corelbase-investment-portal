<?php
/**
 * CORELBASE PRIVATE LIMITED — SHARE CERTIFICATE PDF DOWNLOAD ENDPOINT
 * (/api/certificate_download.php)
 * -----------------------------------------------------------------------------
 * Streams the official Share Certificate PDF (`CB-SC-2026-XXXXXX.pdf`) for an
 * authorized Administrator or verified Investor, generating the PDF on-the-fly
 * if missing on disk.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/pdf_generator.php';

Security::initSecureSession();

$certNo = strtoupper(Security::cleanInput($_GET['cert_no'] ?? '', 50));
$appId = strtoupper(Security::cleanInput($_GET['app_id'] ?? '', 40));
$email = strtolower(Security::cleanInput($_GET['email'] ?? '', 180));
$isAdmin = Auth::isAdminLoggedIn();

if ($certNo === '' && $appId === '') {
    http_response_code(400);
    exit('Certificate Number or Application ID is required.');
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT c.*, i.email AS investor_email
        FROM `certificates` c
        INNER JOIN `investors` i ON i.id = c.investor_ref_id
        WHERE (UPPER(c.certificate_number) = :cno OR (:cno = '' AND UPPER(c.application_id) = :aid))
        LIMIT 1
    ");
    $stmt->execute([':cno' => $certNo, ':aid' => $appId]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cert) {
        http_response_code(404);
        exit('Share Certificate not found.');
    }

    $investorSessionMatch = !empty($_SESSION['investor_code']) && strtoupper((string)$_SESSION['investor_code']) === strtoupper((string)$cert['investor_id']);
    $emailMatch = ($email !== '' && strtolower((string)$cert['investor_email']) === $email);
    $appMatch = ($appId !== '' && strtoupper((string)$cert['application_id']) === $appId);

    if (!$isAdmin && !$investorSessionMatch && !$emailMatch && !$appMatch) {
        http_response_code(403);
        exit('Unauthorized access to Share Certificate PDF.');
    }

    $relPath = (string)$cert['pdf_relative_path'];
    $fullPath = CB_ROOT_PATH . '/' . $relPath;

    if (!file_exists($fullPath)) {
        $relPath = PDFGenerator::generateShareCertificatePDF($cert);
        $fullPath = CB_ROOT_PATH . '/' . $relPath;
    }

    if ($isAdmin) {
        $admin = Auth::getCurrentAdmin();
        logAuditAction($pdo, 'Certificate Downloaded', 'CERTIFICATE', (string)$cert['certificate_number'], $admin['id'] ?? null, $admin['email'] ?? 'ADMIN');
    }

    $pdfContent = file_get_contents($fullPath);
    $downloadMode = isset($_GET['download']) ? 'attachment' : 'inline';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $downloadMode . '; filename="' . $cert['certificate_number'] . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Unable to stream Share Certificate PDF.');
}
