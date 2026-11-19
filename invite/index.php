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
<title>Join your organization | TaskFlow</title>
<link rel="icon" href="../assets/img/favicon-64.png" type="image/png">
<link rel="stylesheet" href="invite.css">
<script src="invite.js" defer></script>
</head>
<body>
<main class="invite-layout">
  <div class="brand"><img src="../assets/img/taskflow-logo-192.png" alt="" width="40" height="40"> TaskFlow</div>
  <section class="invite-card" aria-labelledby="page-title">
    <p class="eyebrow">ORGANIZATION INVITATION</p>
    <h1 id="page-title">Join your team</h1>
    <p id="invite-status" role="status" aria-live="polite">Checking your invitation…</p>
    <div id="invite-details" hidden></div>
    <form id="invite-form" hidden>
      <label for="invite-password">Create a strong password</label>
      <input id="invite-password" type="password" autocomplete="new-password" required minlength="12" maxlength="72">
      <label for="invite-password-confirm">Confirm password</label>
      <input id="invite-password-confirm" type="password" autocomplete="new-password" required minlength="12" maxlength="72">
      <p class="hint">Use a unique passphrase, 12–72 bytes. You can enable authenticator MFA in your account settings after signing in.</p>
      <button type="submit" id="invite-submit">Activate my account</button>
    </form>
    <a href="../" id="invite-login">Back to TaskFlow sign in</a>
  </section>
  <p class="privacy">Only accept an invitation you recognize. Invite links can be used once and expire after 72 hours.</p>
</main>
</body>
</html>
