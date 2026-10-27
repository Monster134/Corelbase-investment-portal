<?php
/**
 * CORELBASE PRIVATE LIMITED — PUBLIC CONFIGURATION API (/api/public_config.php)
 * -----------------------------------------------------------------------------
 * Returns ONLY settings marked as `is_public = 1` (and capital figures marked
 * as Public by the Administrator) along with the session CSRF token.
 * Never exposes database credentials, SMTP passwords, or private signing keys.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';

Security::initSecureSession();
$csrfToken = Security::getCsrfToken();
$publicSettings = getAllSettings(null, true);

jsonResponse([
    'success'    => true,
    'config'     => $publicSettings,
    'csrf_token' => $csrfToken,
    'timestamp'  => formatIST(gmdate('Y-m-d H:i:s')),
]);
