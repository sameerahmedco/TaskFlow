<?php
require_once __DIR__ . '/../config/config.php';
if (!APP_INSTALLED) { header('Location: ../install/', true, 302); exit; }
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Recover your account | TaskFlow</title>
<link rel="icon" href="../assets/img/favicon-64.png" type="image/png">
<link rel="stylesheet" href="../invite/invite.css">
<link rel="stylesheet" href="recover.css">
<script src="recover.js" defer></script>
</head>
<body>
<main class="invite-layout">
  <div class="brand"><img src="../assets/img/taskflow-logo-192.png" alt="" width="40" height="40"> TaskFlow</div>
  <section class="invite-card" aria-labelledby="recovery-title">
    <p class="eyebrow">ACCOUNT SECURITY</p>
    <h1 id="recovery-title">Password recovery</h1>
    <p id="recover-status" role="status" aria-live="polite">Enter your work email address to request a recovery link.</p>
    <form id="recover-request-form">
      <label for="recover-email">Work email</label>
      <input id="recover-email" type="email" maxlength="190" required autocomplete="email" placeholder="you@organization.org">
      <button type="submit" id="recover-request-btn">Email recovery link</button>
    </form>
    <form id="recover-reset-form" hidden>
      <label for="recover-password">New password</label>
      <input id="recover-password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
      <label for="recover-confirm">Confirm new password</label>
      <input id="recover-confirm" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
      <p class="hint">Use a unique passphrase of 12–72 bytes. This reset signs out other devices and keeps your authenticator MFA enabled.</p>
      <button type="submit" id="recover-reset-btn">Update password</button>
    </form>
    <a href="../" id="recover-login">Return to sign in</a>
  </section>
  <p class="privacy">Recovery requires administrator-configured SMTP. Links are private, one-time and expire after 30 minutes. If email is unavailable, contact your organization administrator.</p>
</main>
</body>
</html>
