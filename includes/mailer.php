<?php
/**
 * CORELBASE PRIVATE LIMITED — SMTP SERVICE & BRANDED EMAIL TEMPLATE ENGINE
 * -----------------------------------------------------------------------------
 * Implements:
 * - Native RFC-compliant PHP Socket SMTP Client supporting TLS (STARTTLS), SSL,
 *   and AUTH LOGIN without exposing credentials to frontend code.
 * - All 11 required transactional email templates with Corelbase Black + Red
 *   corporate branding, Application ID tracking, and mandatory legal disclaimer.
 * - Full database audit logging into `email_logs`.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

class Mailer
{
    /**
     * Send a branded notification email using one of the 11 official templates.
     *
     * Supported template keys:
     * 1. application_submitted
     * 2. payment_proof_received
     * 3. payment_under_verification
     * 4. payment_verified
     * 5. reupload_requested
     * 6. payment_rejected
     * 7. application_approved
     * 8. shares_allotted
     * 9. certificate_generated
     * 10. certificate_available
     * 11. application_cancelled
     */
    public static function sendTemplate(
        PDO $pdo,
        string $templateKey,
        string $recipientEmail,
        string $recipientName,
        array $context = [],
        ?int $adminId = null
    ): array {
        $settings = getAllSettings($pdo, false);
        [$subject, $htmlBody] = self::buildTemplate($templateKey, $recipientName, $context, $settings);

        return self::dispatchEmail(
            $pdo,
            $recipientEmail,
            $recipientName,
            $subject,
            $htmlBody,
            $templateKey,
            $context['application_id'] ?? null,
            $context['investor_id'] ?? null,
            $adminId
        );
    }

    /**
     * Test SMTP server connection and optionally send a test email (Section 30).
     */
    public static function testSmtpConnection(PDO $pdo, string $testRecipient, ?int $adminId = null): array
    {
        $settings = getAllSettings($pdo, false);
        $subject = 'Corelbase Investor Portal — SMTP Configuration Test';
        $body = self::wrapBrandedHtml(
            'SMTP Configuration Verified',
            '<p style="margin:0 0 14px;">This is an automated test email sent from the <strong>Corelbase Private Limited Investor Portal</strong> Admin Panel.</p>' .
            '<p style="margin:0;">Your SMTP configuration (' . Security::e($settings['smtp_host'] ?? '') . ':' . Security::e($settings['smtp_port'] ?? '') . ') is working properly.</p>',
            ['application_id' => 'SMTP-TEST-' . date('His')],
            $settings
        );

        return self::dispatchEmail(
            $pdo,
            $testRecipient,
            'Administrator',
            $subject,
            $body,
            'smtp_test',
            null,
            null,
            $adminId,
            true // force live attempt if host is configured
        );
    }

    /**
     * Dispatch email via configured SMTP server or log safely in simulation mode.
     */
    public static function dispatchEmail(
        PDO $pdo,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $templateKey = 'custom',
        ?string $applicationId = null,
        ?string $investorId = null,
        ?int $adminId = null,
        bool $forceLive = false
    ): array {
        $settings = getAllSettings($pdo, false);
        $smtpEnabled = ($settings['smtp_enabled'] ?? '0') === '1' || $forceLive;
        $host = trim((string)($settings['smtp_host'] ?? ''));
        $port = (int)($settings['smtp_port'] ?? 587);
        $username = trim((string)($settings['smtp_username'] ?? ''));
        $password = (string)($settings['smtp_password'] ?? '');
        $encryption = strtolower(trim((string)($settings['smtp_encryption'] ?? 'tls')));
        $fromEmail = trim((string)($settings['smtp_from_email'] ?? 'support@corelbase.com'));
        $fromName = trim((string)($settings['smtp_from_name'] ?? 'Corelbase Investor Relations'));

        $status = 'SIMULATED';
        $errorMsg = null;

        // Check if real SMTP credentials are provided
        $hasValidHost = ($host !== '' && $host !== 'smtp.your-provider.com' && $username !== 'SMTP_USERNAME_PLACEHOLDER');

        if ($smtpEnabled && $hasValidHost) {
            try {
                self::smtpSendSocket($host, $port, $encryption, $username, $password, $fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody);
                $status = 'SENT';
            } catch (Throwable $e) {
                $status = 'FAILED';
                $errorMsg = $e->getMessage();
                Database::logError('SMTP Send Failure: ' . $errorMsg);
            }
        } elseif ($forceLive && !$hasValidHost) {
            $status = 'SIMULATED';
            $errorMsg = 'SMTP placeholder credentials detected. Logged in simulation mode — update SMTP Host, Username & Password in Admin Settings for live transmission.';
        }

        // Log into `email_logs`
        try {
            $stmt = $pdo->prepare("
                INSERT INTO `email_logs`
                (`application_id`, `investor_id`, `recipient_email`, `recipient_name`, `template_key`, `subject`, `status`, `error_message`, `triggered_by_admin_id`, `sent_at`)
                VALUES (:app_id, :inv_id, :remail, :rname, :tkey, :subj, :st, :err, :aid, UTC_TIMESTAMP())
            ");
            $stmt->execute([
                ':app_id' => $applicationId,
                ':inv_id' => $investorId,
                ':remail' => $toEmail,
                ':rname'  => $toName,
                ':tkey'   => $templateKey,
                ':subj'   => $subject,
                ':st'     => $status,
                ':err'    => $errorMsg,
                ':aid'    => $adminId,
            ]);
        } catch (Throwable $e) {
            Database::logError('Email log insert failed: ' . $e->getMessage());
        }

        return [
            'success' => ($status === 'SENT' || $status === 'SIMULATED'),
            'status'  => $status,
            'message' => $status === 'SENT'
                ? "Email sent successfully to {$toEmail}."
                : ($status === 'SIMULATED'
                    ? "Email queued & recorded in Email Logs (Simulation Mode active until live SMTP credentials are saved)."
                    : "SMTP transmission failed: {$errorMsg}"),
        ];
    }

    /**
     * Build subject and HTML content for all 11 required templates (Sections 28 & 29).
     */
    private static function buildTemplate(string $key, string $name, array $ctx, array $settings): array
    {
        $appId = Security::e($ctx['application_id'] ?? 'CB-2026-000001');
        $invId = Security::e($ctx['investor_id'] ?? $appId);
        $amount = isset($ctx['amount']) ? formatINR((float)$ctx['amount']) : '—';
        $utr = Security::e($ctx['utr_number'] ?? '—');
        $certNo = Security::e($ctx['certificate_number'] ?? '');
        $shares = isset($ctx['shares']) ? number_format((float)$ctx['shares']) : '—';
        $baseUrl = rtrim($settings['portal_base_url'] ?? 'https://YOUR-DOMAIN', '/');
        $trackUrl = "{$baseUrl}/track.php?app_id=" . urlencode($appId);
        $verifyUrl = $certNo ? "{$baseUrl}/verify.php?id=" . urlencode($certNo) : "{$baseUrl}/verify.php";
        $safeName = Security::e($name);

        switch ($key) {
            case 'application_submitted':
                $subject = "[{$appId}] Investment Application Submitted — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>Thank you for submitting your investment application to <strong>Corelbase Private Limited</strong>. Your application has been registered with the details below:</p>
                    <ul>
                        <li><strong>Application ID:</strong> {$appId}</li>
                        <li><strong>Investor ID:</strong> {$invId}</li>
                        <li><strong>Investment Amount Applied:</strong> {$amount}</li>
                        <li><strong>UTR / Reference:</strong> {$utr}</li>
                        <li><strong>Current Status:</strong> Application Submitted / Payment Under Verification</li>
                    </ul>
                    <p>Please retain your Application ID (<strong>{$appId}</strong>) for tracking your application status online.</p>
                    <p><a href=\"{$trackUrl}\" style=\"display:inline-block;padding:12px 22px;background:#E11D48;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;\">Track Your Application</a></p>";
                break;

            case 'payment_proof_received':
                $subject = "[{$appId}] Payment Proof Received — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>We have received the payment proof document uploaded for your investment application <strong>{$appId}</strong> (UTR: <strong>{$utr}</strong>, Amount: <strong>{$amount}</strong>).</p>
                    <p>Our finance and compliance team will verify the credit against the official Corelbase State Bank of India account.</p>";
                break;

            case 'payment_under_verification':
                $subject = "[{$appId}] Payment Under Verification — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>Your manual bank/UPI transfer (UTR: <strong>{$utr}</strong>) for application <strong>{$appId}</strong> is currently <strong>Under Verification</strong> by our treasury team.</p>
                    <p>You will be notified by email once bank reconciliation is completed.</p>";
                break;

            case 'payment_verified':
                $subject = "[{$appId}] Payment Verified — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>We are pleased to confirm that your payment of <strong>{$amount}</strong> (UTR: <strong>{$utr}</strong>) for Application ID <strong>{$appId}</strong> has been <strong>Verified</strong>.</p>
                    <p><em>Important Note:</em> Payment verification confirms receipt of funds only and does not by itself constitute share allotment. Your application is now queued for formal company approval.</p>";
                break;

            case 'reupload_requested':
                $reason = Security::e($ctx['reason'] ?? 'Please upload a clearer screenshot showing the 12-digit UTR number, date, and amount transferred.');
                $subject = "[{$appId}] Action Required: Payment Proof Re-upload Requested — Corelbase";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>During verification of Application ID <strong>{$appId}</strong>, our review team requested an updated payment proof screenshot:</p>
                    <div style=\"padding:12px 16px;background:#181822;border-left:4px solid #F59E0B;margin:14px 0;color:#FDE68A;\"><strong>Reviewer Note:</strong> {$reason}</div>
                    <p>Please visit the Application Tracking / Investor Portal page to upload a clear payment proof document.</p>
                    <p><a href=\"{$trackUrl}\" style=\"display:inline-block;padding:12px 22px;background:#E11D48;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;\">Open Application Status</a></p>";
                break;

            case 'payment_rejected':
                $reason = Security::e($ctx['reason'] ?? 'Payment could not be reconciled with our bank statement.');
                $subject = "[{$appId}] Application / Payment Update — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>We regret to inform you that the payment/application under ID <strong>{$appId}</strong> could not be verified.</p>
                    <p><strong>Reason:</strong> {$reason}</p>
                    <p>If you believe this is an error, please reply to <a href=\"mailto:support@corelbase.com\" style=\"color:#F43F5E;\">support@corelbase.com</a> quoting your Application ID <strong>{$appId}</strong>.</p>";
                break;

            case 'application_approved':
                $subject = "[{$appId}] Investment Application Approved — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>Your investment application <strong>{$appId}</strong> for <strong>{$amount}</strong> has been <strong>Approved</strong> by Corelbase Private Limited.</p>
                    <p>Formal share allotment and digital share certificate issuance will follow upon completion of corporate allotment procedures.</p>";
                break;

            case 'shares_allotted':
                $allotDate = Security::e($ctx['allotment_date'] ?? date('d M Y'));
                $folio = Security::e($ctx['folio_number'] ?? '—');
                $subject = "[{$appId}] Share Allotment Recorded — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>Corelbase Private Limited has recorded the allotment of equity shares against your approved Application ID <strong>{$appId}</strong>:</p>
                    <ul>
                        <li><strong>Application ID:</strong> {$appId}</li>
                        <li><strong>Register Folio Number:</strong> {$folio}</li>
                        <li><strong>Shares Allotted:</strong> {$shares} Equity Shares</li>
                        <li><strong>Allotment Date:</strong> {$allotDate}</li>
                    </ul>";
                break;

            case 'certificate_generated':
            case 'certificate_available':
                $downloadUrl = "{$baseUrl}/api/certificate_download.php?cert_no=" . urlencode($certNo) . "&app_id=" . urlencode($appId);
                $subject = "Your Corelbase Share Certificate is Available";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>Your official digital Share Allotment Certificate for <strong>Corelbase Private Limited</strong> has been generated and is now available.</p>
                    <ul>
                        <li><strong>Investor Name:</strong> {$safeName}</li>
                        <li><strong>Certificate Number:</strong> {$certNo}</li>
                        <li><strong>Application ID:</strong> {$appId}</li>
                        <li><strong>Shares Allotted:</strong> {$shares} Equity Shares</li>
                    </ul>
                    <p style=\"margin:20px 0;\">
                        <a href=\"{$downloadUrl}\" style=\"display:inline-block;padding:12px 22px;background:#E11D48;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;margin-right:10px;\">Download / View Certificate</a>
                        <a href=\"{$verifyUrl}\" style=\"display:inline-block;padding:12px 22px;background:#1E202B;color:#ffffff;text-decoration:none;border-radius:8px;border:1px solid #3F3F50;font-weight:600;\">Verify Certificate Online</a>
                    </p>";
                break;

            case 'application_cancelled':
                $reason = Security::e($ctx['reason'] ?? 'Cancelled per administrative review.');
                $subject = "[{$appId}] Investment Application Cancelled — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p>
                    <p>Your investment application <strong>{$appId}</strong> has been marked as <strong>Cancelled</strong>.</p>
                    <p><strong>Remarks:</strong> {$reason}</p>
                    <p>For any assistance, please contact <a href=\"mailto:support@corelbase.com\" style=\"color:#F43F5E;\">support@corelbase.com</a>.</p>";
                break;

            default:
                $subject = "[{$appId}] Investor Portal Notification — Corelbase Private Limited";
                $inner = "<p>Dear <strong>{$safeName}</strong>,</p><p>There is an update regarding your investment application <strong>{$appId}</strong>.</p>";
                break;
        }

        return [$subject, self::wrapBrandedHtml($subject, $inner, $ctx, $settings)];
    }

    /**
     * Wrap email body in Corelbase Black + Red responsive HTML layout.
     */
    private static function wrapBrandedHtml(string $headline, string $innerHtml, array $ctx, array $settings): string
    {
        $companyName = Security::e($settings['company_name'] ?? 'Corelbase Private Limited');
        $cin = Security::e($settings['company_cin'] ?? 'U62099UP2026PTC250888');
        $regOffice = Security::e($settings['company_registered_office'] ?? '');
        $supportEmail = Security::e($settings['company_email'] ?? 'support@corelbase.com');
        $appId = Security::e($ctx['application_id'] ?? '');
        $disclaimer = Security::e(CB_LEGAL_DISCLAIMER);

        $appBadge = $appId !== ''
            ? "<div style=\"display:inline-block;padding:4px 12px;background:rgba(225,29,72,0.18);border:1px solid #E11D48;color:#FDA4AF;border-radius:99px;font-size:12px;font-weight:600;\">Application ID: {$appId}</div>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{$headline}</title>
</head>
<body style="margin:0;padding:24px;background-color:#07070A;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#E4E4E7;line-height:1.6;">
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:620px;margin:0 auto;background:#111118;border:1px solid #272732;border-radius:16px;overflow:hidden;">
    <tr>
      <td style="padding:24px 32px;background:linear-gradient(135deg,#0A0A0F 0%,#1A0B12 100%);border-bottom:2px solid #E11D48;">
        <div style="font-size:20px;font-weight:800;letter-spacing:0.06em;color:#FFFFFF;">COREL<span style="color:#E11D48;">BASE</span></div>
        <div style="font-size:12px;color:#A1A1AA;margin-top:2px;">{$companyName} • CIN: {$cin}</div>
        <div style="margin-top:12px;">{$appBadge}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 32px;color:#E4E4E7;font-size:15px;">
        {$innerHtml}
      </td>
    </tr>
    <tr>
      <td style="padding:20px 32px;background:#0B0B10;border-top:1px solid #23232E;font-size:11.5px;color:#71717A;">
        <p style="margin:0 0 8px;"><strong>Official Support:</strong> <a href="mailto:{$supportEmail}" style="color:#F43F5E;text-decoration:none;">{$supportEmail}</a></p>
        <p style="margin:0 0 8px;"><strong>Registered Office:</strong> {$regOffice}</p>
        <p style="margin:0;color:#52525B;line-height:1.45;">{$disclaimer}</p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Low-level native socket SMTP client with STARTTLS / SSL and AUTH LOGIN.
     */
    private static function smtpSendSocket(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody
    ): void {
        $remote = ($encryption === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new RuntimeException("Unable to connect to SMTP host {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($fp, 12);

        $readResponse = function () use ($fp): string {
            $resp = '';
            while (($line = fgets($fp, 515)) !== false) {
                $resp .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $resp;
        };

        $sendCommand = function (string $cmd, array $expectedCodes) use ($fp, $readResponse): string {
            fwrite($fp, $cmd . "\r\n");
            $resp = $readResponse();
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, $expectedCodes, true)) {
                throw new RuntimeException("SMTP Error on command [" . strtok($cmd, ' ') . "]: " . trim($resp));
            }
            return $resp;
        };

        $greeting = $readResponse();
        if ((int)substr($greeting, 0, 3) !== 220) {
            fclose($fp);
            throw new RuntimeException("Invalid SMTP greeting: " . trim($greeting));
        }

        $serverHost = $_SERVER['HTTP_HOST'] ?? 'corelbase.local';
        $sendCommand("EHLO {$serverHost}", [250]);

        if ($encryption === 'tls') {
            $sendCommand("STARTTLS", [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                throw new RuntimeException("Failed to negotiate STARTTLS encryption.");
            }
            $sendCommand("EHLO {$serverHost}", [250]);
        }

        if ($username !== '') {
            $sendCommand("AUTH LOGIN", [334]);
            $sendCommand(base64_encode($username), [334]);
            $sendCommand(base64_encode($password), [235]);
        }

        $sendCommand("MAIL FROM:<{$fromEmail}>", [250]);
        $sendCommand("RCPT TO:<{$toEmail}>", [250, 251]);
        $sendCommand("DATA", [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedToName = '=?UTF-8?B?' . base64_encode($toName) . '?=';

        $headers = [
            "Date: " . date('r'),
            "From: {$encodedFromName} <{$fromEmail}>",
            "To: {$encodedToName} <{$toEmail}>",
            "Subject: {$encodedSubject}",
            "Message-ID: <" . bin2hex(random_bytes(12)) . "@corelbase.com>",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
        ];

        $messageData = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";
        $sendCommand($messageData, [250]);
        $sendCommand("QUIT", [221, 250]);
        fclose($fp);
    }
}
