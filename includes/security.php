<?php
/**
 * CORELBASE PRIVATE LIMITED — SECURITY & CRYPTOGRAPHY ENGINE
 * -----------------------------------------------------------------------------
 * Implements:
 * - Secure session initialization (HttpOnly, SameSite, Secure flag, Session Timeout)
 * - CSRF token generation & constant-time verification
 * - AES-256-GCM authenticated encryption for PAN & Aadhaar numbers
 * - HMAC-SHA256 blind indexing for duplicate KYC detection
 * - Privacy masking for PAN (ABCDE****F), Aadhaar (********1234), and Names
 * - Brute-force login rate limiting & IP throttling
 * - Strict payment proof upload validation (MIME + finfo + extension + size)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

class Security
{
    /**
     * Initialize secure PHP session with strict cookie parameters and inactivity timeout.
     */
    public static function initSecureSession(int $timeoutMinutes = 30): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') == 443)
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_name('CORELBASE_SESSID');
            session_start();
        }

        // Enforce inactivity timeout
        $timeoutSeconds = max(300, $timeoutMinutes * 60);
        if (isset($_SESSION['_last_activity']) && (time() - (int)$_SESSION['_last_activity']) > $timeoutSeconds) {
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true);
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_last_activity'] = time();

        // Regenerate session ID periodically (every 15 minutes)
        if (!isset($_SESSION['_created_at'])) {
            $_SESSION['_created_at'] = time();
        } elseif (time() - (int)$_SESSION['_created_at'] > 900) {
            session_regenerate_id(true);
            $_SESSION['_created_at'] = time();
        }
    }

    /**
     * Set standard security HTTP headers.
     */
    public static function sendSecurityHeaders(bool $isJson = false): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 1; mode=block');
        header("Permissions-Policy: geolocation=(), microphone=(), camera=(self)");
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
    }

    /**
     * Generate or retrieve active CSRF token for current session.
     */
    public static function getCsrfToken(): string
    {
        self::initSecureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    /**
     * Verify submitted CSRF token in constant time.
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        self::initSecureSession();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals((string)$_SESSION['csrf_token'], (string)$token);
    }

    /**
     * Encrypt sensitive KYC field (PAN / Aadhaar) using AES-256-GCM.
     */
    public static function encryptSensitive(string $plaintext): string
    {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            return '';
        }
        $key = hash('sha256', CB_APP_SECRET_KEY, true);
        $cipher = 'aes-256-gcm';
        if (in_array($cipher, openssl_get_cipher_methods(), true)) {
            $ivLen = openssl_cipher_iv_length($cipher);
            $iv = random_bytes($ivLen);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            return 'GCM:' . base64_encode($iv . $tag . $ciphertext);
        }
        // Fallback to AES-256-CBC with HMAC
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        return 'CBC:' . base64_encode($iv . $mac . $ciphertext);
    }

    /**
     * Decrypt sensitive KYC field for authorized Super Admin view only.
     */
    public static function decryptSensitive(string $payload): string
    {
        if ($payload === '' || str_starts_with($payload, 'DEMO_ENC_')) {
            return '[DEMO ENCRYPTED RECORD]';
        }
        $key = hash('sha256', CB_APP_SECRET_KEY, true);
        if (str_starts_with($payload, 'GCM:')) {
            $raw = base64_decode(substr($payload, 4), true);
            if ($raw === false || strlen($raw) < 28) {
                return '[Protected]';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ciphertext = substr($raw, 28);
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $decrypted !== false ? $decrypted : '[Protected]';
        }
        if (str_starts_with($payload, 'CBC:')) {
            $raw = base64_decode(substr($payload, 4), true);
            if ($raw === false || strlen($raw) < 48) {
                return '[Protected]';
            }
            $iv = substr($raw, 0, 16);
            $mac = substr($raw, 16, 32);
            $ciphertext = substr($raw, 48);
            $calcMac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
            if (!hash_equals($mac, $calcMac)) {
                return '[Protected]';
            }
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : '[Protected]';
        }
        return '[Protected]';
    }

    /**
     * Generate deterministic HMAC-SHA256 blind index hash for duplicate PAN/Aadhaar checks.
     */
    public static function hashIdentifier(string $value): string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($value)));
        return hash_hmac('sha256', $normalized, CB_HMAC_PEPPER);
    }

    /**
     * Mask PAN number: ABCDE1234F -> ABCDE****F
     */
    public static function maskPan(string $pan): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $pan));
        if (strlen($clean) !== 10) {
            return '*****' . substr($clean, -1);
        }
        return substr($clean, 0, 5) . '****' . substr($clean, 9, 1);
    }

    /**
     * Mask Aadhaar number: 123456789012 -> ********9012
     */
    public static function maskAadhaar(string $aadhaar): string
    {
        $clean = preg_replace('/\D/', '', $aadhaar);
        if (strlen($clean) < 4) {
            return '************';
        }
        return '********' . substr($clean, -4);
    }

    /**
     * Partially mask investor name for public QR Certificate Verification page.
     * Example: "Aarav Sharma" -> "Aar** Sha***"
     */
    public static function maskInvestorName(string $name): string
    {
        $name = trim(preg_replace('/\[DEMO RECORD\]/i', '', $name));
        $parts = preg_split('/\s+/', $name) ?: [];
        $maskedParts = [];
        foreach ($parts as $part) {
            $len = mb_strlen($part);
            if ($len <= 2) {
                $maskedParts[] = mb_substr($part, 0, 1) . '*';
            } elseif ($len <= 4) {
                $maskedParts[] = mb_substr($part, 0, 2) . str_repeat('*', $len - 2);
            } else {
                $maskedParts[] = mb_substr($part, 0, 3) . str_repeat('*', min(4, $len - 3));
            }
        }
        return implode(' ', $maskedParts);
    }

    /**
     * Validate Indian PAN format: 5 letters, 4 digits, 1 letter (e.g. ABCDE1234F)
     */
    public static function isValidPan(string $pan): bool
    {
        return (bool)preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper(trim($pan)));
    }

    /**
     * Validate 12-digit Indian Aadhaar number (starts with 2-9).
     */
    public static function isValidAadhaar(string $aadhaar): bool
    {
        $clean = preg_replace('/\s+|-/', '', trim($aadhaar));
        return (bool)preg_match('/^[2-9][0-9]{11}$/', $clean);
    }

    /**
     * Sanitize string input.
     */
    public static function cleanInput(?string $value, int $maxLength = 500): string
    {
        if ($value === null) {
            return '';
        }
        $clean = trim(strip_tags($value));
        return mb_substr($clean, 0, $maxLength);
    }

    /**
     * HTML-escape string for safe output rendering.
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Get client IP address safely.
     */
    public static function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /**
     * Check if IP or identifier is rate-limited for login/sensitive endpoints.
     */
    public static function isRateLimited(PDO $pdo, string $identifier, string $endpoint = 'admin_login', int $maxAttempts = 5, int $windowMinutes = 15): bool
    {
        $ip = self::getClientIp();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM `login_attempts`
            WHERE (`ip_address` = :ip OR `identifier` = :identifier)
              AND `endpoint` = :endpoint
              AND `attempted_at` >= (UTC_TIMESTAMP() - INTERVAL :win MINUTE)
        ");
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->bindValue(':identifier', $identifier, PDO::PARAM_STR);
        $stmt->bindValue(':endpoint', $endpoint, PDO::PARAM_STR);
        $stmt->bindValue(':win', $windowMinutes, PDO::PARAM_INT);
        $stmt->execute();
        return ((int)$stmt->fetchColumn()) >= $maxAttempts;
    }

    /**
     * Record a failed login or sensitive endpoint attempt.
     */
    public static function recordFailedAttempt(PDO $pdo, string $identifier, string $endpoint = 'admin_login'): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO `login_attempts` (`ip_address`, `identifier`, `endpoint`, `attempted_at`)
            VALUES (:ip, :identifier, :endpoint, UTC_TIMESTAMP())
        ");
        $stmt->execute([
            ':ip'         => self::getClientIp(),
            ':identifier' => mb_substr($identifier, 0, 190),
            ':endpoint'   => $endpoint,
        ]);
    }

    /**
     * Clear failed login attempts on successful authentication.
     */
    public static function clearFailedAttempts(PDO $pdo, string $identifier, string $endpoint = 'admin_login'): void
    {
        $stmt = $pdo->prepare("
            DELETE FROM `login_attempts`
            WHERE (`ip_address` = :ip OR `identifier` = :identifier)
              AND `endpoint` = :endpoint
        ");
        $stmt->execute([
            ':ip'         => self::getClientIp(),
            ':identifier' => $identifier,
            ':endpoint'   => $endpoint,
        ]);
    }

    /**
     * Validate and store payment proof screenshot/PDF according to specification:
     * /uploads/payments/YEAR/INVESTOR-ID/UTR.ext
     *
     * @return array{success:bool, message?:string, data?:array}
     */
    public static function validateAndStorePaymentProof(
        array $file,
        string $investorId,
        string $utrNumber,
        int $maxSizeMb = 5,
        string $allowedExtCsv = 'jpg,jpeg,png,webp,pdf'
    ): array {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'message' => 'Invalid payment proof upload parameters.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Payment proof upload failed. Please check file size and try again.'];
        }

        $maxBytes = max(1, $maxSizeMb) * 1024 * 1024;
        if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
            return ['success' => false, 'message' => "Payment screenshot must be under {$maxSizeMb} MB."];
        }

        $originalName = (string)($file['name'] ?? 'proof');
        // Block double extensions or dangerous executable extensions anywhere in filename
        if (preg_match('/\.(php\d*|phtml|phar|pl|py|cgi|sh|exe|bat|cmd|js|html|htm|svg|htaccess|ini)/i', $originalName)) {
            return ['success' => false, 'message' => 'Executable or script files are strictly prohibited.'];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = array_filter(array_map('trim', explode(',', strtolower($allowedExtCsv))));
        // Always enforce safe whitelist
        $hardWhitelist = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $allowedExtensions = array_values(array_intersect($allowedExtensions, $hardWhitelist));
        if (empty($allowedExtensions)) {
            $allowedExtensions = $hardWhitelist;
        }

        if (!in_array($ext, $allowedExtensions, true)) {
            return ['success' => false, 'message' => 'Allowed file formats: ' . strtoupper(implode(', ', $allowedExtensions)) . '.'];
        }

        // Strict server-side MIME detection using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($file['tmp_name']);
        $allowedMimes = [
            'jpg'  => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png'  => ['image/png'],
            'webp' => ['image/webp'],
            'pdf'  => ['application/pdf'],
        ];

        if (!isset($allowedMimes[$ext]) || !in_array($detectedMime, $allowedMimes[$ext], true)) {
            return ['success' => false, 'message' => 'File content does not match allowed image/PDF format.'];
        }

        // If image, verify via getimagesize that it is a valid raster image
        if ($ext !== 'pdf') {
            $imgInfo = @getimagesize($file['tmp_name']);
            if ($imgInfo === false) {
                return ['success' => false, 'message' => 'Uploaded image file appears corrupted or invalid.'];
            }
        }

        // Scan first 4KB for embedded PHP tags (`<?php` or `<?=`)
        $headBytes = (string)@file_get_contents($file['tmp_name'], false, null, 0, 4096);
        if (stripos($headBytes, '<?php') !== false || stripos($headBytes, '<?=') !== false || stripos($headBytes, '<script') !== false) {
            return ['success' => false, 'message' => 'File rejected by security scanner.'];
        }

        // Build target path: /uploads/payments/YEAR/INVESTOR-ID/UTR.ext
        $year = date('Y');
        $safeInvestorId = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($investorId));
        $safeUtr = preg_replace('/[^A-Za-z0-9\-_]/', '', $utrNumber);
        if ($safeUtr === '') {
            $safeUtr = 'UTR_' . bin2hex(random_bytes(4));
        }

        $relativeDir = "uploads/payments/{$year}/{$safeInvestorId}";
        $targetDir = CB_ROOT_PATH . '/' . $relativeDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true)) {
            return ['success' => false, 'message' => 'Unable to initialize secure storage directory.'];
        }

        $storedFilename = "{$safeUtr}.{$ext}";
        $targetPath = $targetDir . '/' . $storedFilename;

        // If a file with the same name already exists (e.g. re-upload), append short random suffix while keeping UTR prefix
        if (file_exists($targetPath)) {
            $storedFilename = "{$safeUtr}_" . substr(bin2hex(random_bytes(3)), 0, 6) . ".{$ext}";
            $targetPath = $targetDir . '/' . $storedFilename;
        }

        $checksum = hash_file('sha256', $file['tmp_name']);

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Fallback for internal CLI test environment
            if (!@copy($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'message' => 'Failed to save payment proof on server.'];
            }
        }

        @chmod($targetPath, 0640);

        return [
            'success' => true,
            'data'    => [
                'original_filename' => self::cleanInput($originalName, 250),
                'stored_filename'   => $storedFilename,
                'relative_path'     => $relativeDir . '/' . $storedFilename,
                'mime_type'         => $detectedMime,
                'file_extension'    => $ext,
                'file_size'         => (int)$file['size'],
                'sha256_checksum'   => $checksum,
            ],
        ];
    }
}
