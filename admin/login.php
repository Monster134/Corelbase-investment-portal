<?php
/**
 * CORELBASE PRIVATE LIMITED — ADMINISTRATOR LOGIN PORTAL (/admin/login.php)
 * -----------------------------------------------------------------------------
 * Sections 12 & 32:
 * - Uses PHP password_verify() against database `admin_users`
 * - Enforces brute-force login throttling & temporary lockout
 * - Session timeout notification
 * - Zero hard-coded default passwords (directs to /install/ if no admin exists)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

Security::initSecureSession();
Security::sendSecurityHeaders(false);

if (($_GET['action'] ?? '') === 'logout') {
    try {
        $pdo = Database::getConnection();
    } catch (Throwable $e) {
        $pdo = null;
    }
    Auth::adminLogout($pdo);
    header('Location: login.php?logged_out=1');
    exit;
}

if (Auth::isAdminLoggedIn()) {
    header('Location: index.php');
    exit;
}

$csrfToken = Security::getCsrfToken();
$errorMsg = '';
$infoMsg = isset($_GET['logged_out']) ? 'You have been signed out securely.' : '';
if (!empty($_SESSION['_expired'])) {
    $infoMsg = 'Your session expired due to inactivity. Please sign in again.';
    unset($_SESSION['_expired']);
}

$hasAdminAccount = false;
try {
    $pdo = Database::getConnection();
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM `admin_users`")->fetchColumn();
    $hasAdminAccount = ($adminCount > 0);
} catch (Throwable $e) {
    $pdo = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Invalid CSRF security token. Please refresh the page.';
    } elseif (!$pdo) {
        $errorMsg = 'Database connection is not configured yet. Please run the /install/ wizard first.';
    } else {
        $identifier = Security::cleanInput($_POST['identifier'] ?? '', 180);
        $password = (string)($_POST['password'] ?? '');
        $res = Auth::adminLogin($pdo, $identifier, $password);
        if ($res['success']) {
            header('Location: index.php');
            exit;
        }
        $errorMsg = $res['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — Corelbase Investor Portal</title>
  <link rel="icon" type="image/png" href="../assets/brand/favicon.png">
  <link rel="stylesheet" href="../css/style.css">
</head>
<body>
  <div class="ambient-canvas" aria-hidden="true"></div>

  <main style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div class="glass-card" style="max-width:440px;width:100%;">
      <div style="text-align:center;margin-bottom:24px;">
        <img src="../assets/brand/logo.png" alt="Corelbase" style="width:62px;height:62px;background:#fff;padding:5px;border-radius:14px;margin-bottom:12px;">
        <div class="eyebrow" style="display:table;margin:0 auto 8px;">Restricted Administrative Access</div>
        <h1 style="font-size:1.55rem;">Corelbase Admin Portal</h1>
        <p style="font-size:0.84rem;color:var(--text-secondary);">Corelbase Private Limited • Investor &amp; Allotment Management</p>
      </div>

      <?php if (!$hasAdminAccount): ?>
        <div style="padding:14px;border-radius:12px;background:rgba(245,158,11,0.14);border:1px solid rgba(245,158,11,0.45);color:#fde68a;font-size:0.84rem;margin-bottom:18px;">
          <strong>Initial Setup Required:</strong> No administrator account has been created yet. Complete the <a href="../install/index.php" style="color:#fff;font-weight:700;">/install/ Setup Wizard</a> to configure your database and first Super Admin account.
        </div>
      <?php endif; ?>

      <?php if ($infoMsg !== ''): ?>
        <div style="padding:12px;border-radius:10px;background:rgba(16,185,129,0.14);border:1px solid rgba(16,185,129,0.4);color:#6ee7b7;font-size:0.84rem;margin-bottom:16px;">
          <?= Security::e($infoMsg) ?>
        </div>
      <?php endif; ?>

      <?php if ($errorMsg !== ''): ?>
        <div style="padding:12px;border-radius:10px;background:rgba(239,68,68,0.14);border:1px solid rgba(239,68,68,0.4);color:#fca5a5;font-size:0.84rem;margin-bottom:16px;">
          <?= Security::e($errorMsg) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="login.php" style="display:flex;flex-direction:column;gap:16px;" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
        <div class="form-group">
          <label class="form-label" for="identifier">Administrator Username or Email</label>
          <input type="text" class="form-control" id="identifier" name="identifier" placeholder="admin@corelbase.com" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input type="password" class="form-control" id="password" name="password" placeholder="••••••••••••" required>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">Sign In to Admin Dashboard →</button>
      </form>

      <div style="margin-top:20px;padding-top:14px;border-top:1px solid var(--border-subtle);display:flex;justify-content:space-between;font-size:0.8rem;color:var(--text-muted);">
        <a href="../index.html" style="color:var(--text-secondary);text-decoration:none;">← Public Investor Portal</a>
        <span>IP Logged: <?= Security::e(Security::getClientIp()) ?></span>
      </div>
    </div>
  </main>
</body>
</html>
