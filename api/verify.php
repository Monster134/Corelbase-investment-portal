<?php
/**
 * CORELBASE PRIVATE LIMITED — JSON CERTIFICATE VERIFICATION API (/api/verify.php)
 * -----------------------------------------------------------------------------
 * Verifies a Share Certificate Number (`CB-SC-2026-000001`) and returns public
 * verification metadata with partially masked investor name.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

$certNo = strtoupper(Security::cleanInput($_GET['id'] ?? $_POST['id'] ?? $_GET['certificate_number'] ?? '', 50));

if ($certNo === '') {
    jsonResponse(['success' => false, 'message' => 'Certificate number is required.'], 422);
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT `id`, `certificate_number`, `investor_name`, `shares_count`, `share_class`,
               `allotment_date`, `status`, `issued_by`, `generated_at`
        FROM `certificates`
        WHERE UPPER(`certificate_number`) = :cno
        LIMIT 1
    ");
    $stmt->execute([':cno' => $certNo]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);

    $resStatus = $cert ? (string)$cert['status'] : 'NOT_FOUND';
    if (!in_array($resStatus, ['VALID', 'REVOKED', 'SUSPENDED', 'NOT_FOUND'], true)) {
        $resStatus = 'NOT_FOUND';
    }

    $pdo->prepare("
        INSERT INTO `certificate_verification`
        (`certificate_id`, `certificate_number_queried`, `verification_result`, `visitor_ip`, `user_agent`, `verified_at`)
        VALUES (:cid, :cno, :res, :ip, :ua, UTC_TIMESTAMP())
    ")->execute([
        ':cid' => $cert['id'] ?? null,
        ':cno' => $certNo,
        ':res' => $resStatus,
        ':ip'  => Security::getClientIp(),
        ':ua'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'API', 0, 250),
    ]);

    if (!$cert) {
        jsonResponse(['success' => false, 'verified' => false, 'message' => 'Certificate not found in Corelbase registry.'], 404);
    }

    jsonResponse([
        'success'     => true,
        'verified'    => ($cert['status'] === 'VALID'),
        'certificate' => [
            'certificate_number' => $cert['certificate_number'],
            'company_name'       => 'Corelbase Private Limited',
            'holder_name_masked' => Security::maskInvestorName((string)$cert['investor_name']),
            'shares_count'       => (int)$cert['shares_count'],
            'share_class'        => $cert['share_class'],
            'allotment_date'     => $cert['allotment_date'],
            'status'             => $cert['status'],
            'issued_by'          => $cert['issued_by'],
            'verified_at_ist'    => formatIST(gmdate('Y-m-d H:i:s')),
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Verification service temporarily unavailable.'], 500);
}
