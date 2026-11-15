<?php
/** One-time, guarded installation; never available after config/.installed is created. */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
if (APP_INSTALLED) {
    header('Location: ../index.php', true, 302);
    exit;
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['setup_csrf'])) $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
$errors = [];
$installed = false;
$configDir = realpath(__DIR__ . '/../config');
$keyFile = $configDir . '/.setup-key';
$requirements = [
    'PHP 8.2 or newer' => PHP_VERSION_ID >= 80200,
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'Sodium encryption extension' => extension_loaded('sodium'),
    'OpenSSL extension' => extension_loaded('openssl'),
    'Multibyte strings (mbstring)' => extension_loaded('mbstring'),
    'Writable configuration directory' => is_writable($configDir),
    'One-time setup key created using CLI' => is_file($keyFile) && is_readable($keyFile),
];
$pendingConfig = is_file($configDir . '/config.local.php');
if ($pendingConfig) $requirements['Installer is not partially configured'] = false;
$ready = !in_array(false, $requirements, true);
$old = static fn(string $key, string $default=''): string => htmlspecialchars((string) ($_POST[$key] ?? $default), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$ready) {
        $errors[] = 'Complete the system checks before installation.';
    } elseif (empty($_POST['csrf']) || !hash_equals($_SESSION['setup_csrf'], (string) $_POST['csrf'])) {
        $errors[] = 'This setup session expired. Refresh the page and try again.';
    } elseif (($_SESSION['setup_attempts'] ?? 0) >= 10) {
        $errors[] = 'Too many setup attempts in this session. Restart setup from your browser.';
    } else {
        $_SESSION['setup_attempts'] = ($_SESSION['setup_attempts'] ?? 0) + 1;
        $sentKey = (string) ($_POST['setup_key'] ?? '');
        $expectedKey = trim((string) @file_get_contents($keyFile));
        if (strlen($expectedKey) !== 64 || !hash_equals($expectedKey, $sentKey)) {
            $errors[] = 'The one-time setup key is incorrect.';
        } else {
            $dbHost = trim((string) ($_POST['db_host'] ?? ''));
            $dbPort = (int) ($_POST['db_port'] ?? 3306);
            $dbName = trim((string) ($_POST['db_name'] ?? ''));
            $dbUser = trim((string) ($_POST['db_user'] ?? ''));
            $dbPass = (string) ($_POST['db_pass'] ?? '');
            $url = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
            $org = trim((string) ($_POST['org_name'] ?? ''));
            $firstTeam = trim((string) ($_POST['first_team'] ?? 'General'));
            $adminName = trim((string) ($_POST['admin_name'] ?? ''));
            $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
            $adminPassword = (string) ($_POST['admin_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');
            if (!preg_match('/\A[a-zA-Z0-9.\\_:-]{1,253}\z/', $dbHost)) $errors[] = 'Invalid database hostname.';
            if ($dbPort < 1 || $dbPort > 65535) $errors[] = 'Invalid database port.';
            if (!preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $dbName)) $errors[] = 'Invalid database name.';
            if ($dbUser === '' || strlen($dbUser) > 150) $errors[] = 'Database username is required.';
            if (!filter_var($url, FILTER_VALIDATE_URL) || (!str_starts_with($url, 'https://') && !preg_match('~^http://(localhost|127\\.0\\.0\\.1)(:[0-9]+)?(/|$)~i', $url))) {
                $errors[] = 'The public application URL must use HTTPS (except localhost development).';
            }
            if ($org === '' || mb_strlen($org) > 150) $errors[] = 'Organization name must be 1–150 characters.';
            if ($firstTeam === '' || mb_strlen($firstTeam) > 150) $errors[] = 'First team must be 1–150 characters.';
            if ($adminName === '' || mb_strlen($adminName) > 100) $errors[] = 'Administrator name must be 1–100 characters.';
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminEmail) > 190) $errors[] = 'Enter a valid administrator email.';
            if (strlen($adminPassword) < 14 || strlen($adminPassword) > 72) $errors[] = 'Use a unique administrator passphrase of 14–72 bytes.';
            if (!hash_equals($adminPassword, $confirm)) $errors[] = 'Administrator passwords do not match.';
            if (!$errors) {
                $lockHeld = false;
                $pdo = null;
                try {
                    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_TIMEOUT => 5,
                    ]);
                    $lockName = 'taskflow_install_' . substr(hash('sha256', $dbHost . ':' . $dbName), 0, 40);
                    $lk = $pdo->prepare('SELECT GET_LOCK(?, 5)');
                    $lk->execute([$lockName]);
                    if ((int) $lk->fetchColumn() !== 1) throw new RuntimeException('Installation busy.');
                    $lockHeld = true;
                    if (is_file($configDir . '/.installed') || is_file($configDir . '/config.local.php')) {
                        throw new RuntimeException('Installation is already locked.');
                    }
                    $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users'");
                    $exists->execute([$dbName]);
                    if ((int) $exists->fetchColumn() > 0 && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                        throw new RuntimeException('This database already contains user accounts. Refusing to claim an existing organization.');
                    }
                    $schema = (string) file_get_contents(__DIR__ . '/../schema.sql');
                    $schema = preg_replace('/--[^\n]*/', '', $schema);
                    $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema);
                    foreach ($statements as $statement) {
                        if (trim($statement) !== '') $pdo->exec($statement);
                    }
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,is_active,must_change_password) VALUES (?, ?, ?, ?, 1, 0)');
                    $stmt->execute([$adminName, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), 'administrator']);
                    $id = (int) $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO user_roles (user_id,role) VALUES (?, 'administrator')")->execute([$id]);
                    $pdo->prepare("INSERT IGNORE INTO app_settings (id) VALUES (1)")->execute();
                    $pdo->prepare('UPDATE app_settings SET organization_name=?,from_name=? WHERE id=1')->execute([$org, $org]);
                    $pdo->prepare('INSERT INTO teams (name,color) VALUES (?,?)')->execute([$firstTeam, '#1264E9']);
                    $newTeamId = (int) $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO lists (team_id,name,position) VALUES (?, 'Tasks', 0)")->execute([$newTeamId]);
                    $pdo->commit();
                    $config = [
                        'db_host' => $dbHost, 'db_port' => (string) $dbPort, 'db_name' => $dbName,
                        'db_user' => $dbUser, 'db_pass' => $dbPass, 'app_url' => $url,
                        'app_key' => base64_encode(random_bytes(32)),
                        'app_env' => str_starts_with($url, 'http://') ? 'development' : 'production',
                    ];
                    $configPath = $configDir . '/config.local.php';
                    $handle = @fopen($configPath, 'x');
                    if (!$handle) throw new RuntimeException('Could not write private config. Make config directory writable.');
                    chmod($configPath, 0600);
                    fwrite($handle, "<?php\n// Generated by the one-time TaskFlow installer. NEVER commit this file.\nreturn " . var_export($config, true) . ";\n");
                    fclose($handle);
                    $markerPath = $configDir . '/.installed';
                    $markerHandle = @fopen($markerPath, 'x');
                    if (!$markerHandle) throw new RuntimeException('Could not lock installer. Create config/.installed manually and restrict access.');
                    chmod($markerPath, 0600);
                    fwrite($markerHandle, "installed\n");
                    fclose($markerHandle);
                    @unlink($keyFile);
                    $installed = true;
                    unset($_SESSION['setup_csrf'], $_SESSION['setup_attempts']);
                } catch (Throwable $e) {
                    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                    error_log('TaskFlow install failed: ' . $e->getMessage());
                    // Avoid leaking DB credentials or server details in browser.
                    if (str_contains($e->getMessage(), 'already contains user accounts')) $errors[] = $e->getMessage();
                    else $errors[] = 'Installation failed. Check database credentials, database permissions and server error logs. Do not retry against a database already containing TaskFlow users.';
                } finally {
                    if ($pdo && $lockHeld) {
                        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $ignored) { }
                    }
                }
            }
        }
    }
}
$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up TaskFlow</title><link rel="icon" href="../assets/img/favicon-64.png">
<link rel="stylesheet" href="assets/installer.css"><link rel="stylesheet" href="assets/installer-v1.css"></head><body>
<div class="page">
<header class="masthead"><img src="../assets/img/taskflow-logo-192.png" alt="" width="48" height="48"><div><strong>TaskFlow</strong><span>SETUP · VERSION 1.10.0</span></div><span class="install-brand-pill">FIRST-RUN CONFIGURATION</span></header>
<?php if ($installed): ?>
<div class="panel completion"><div class="check-big" aria-hidden="true">✓</div><h1>Your workspace is ready.</h1><p>The first administrator has been created and the installer is permanently locked. You can now sign in securely.</p><a class="btn" href="../index.php">Open TaskFlow</a></div>
<?php else: ?>
<div class="installer-grid"><div class="installer-intro"><div class="hero"><span class="eyebrow">YOUR SPACE. YOUR RULES.</span><h1>Let's build your<br><em>workspace.</em></h1><p>A few simple steps to connect your database, name your organization, and securely create its first administrator.</p></div><div class="setup-highlights"><div><span class="setup-highlight-icon">01</span><div><strong>Your infrastructure</strong><p>Your data stays on the server and database you choose.</p></div></div><div><span class="setup-highlight-icon">02</span><div><strong>Private from day one</strong><p>No public registration, no default administrator password.</p></div></div><div><span class="setup-highlight-icon">03</span><div><strong>Secure by default</strong><p>One-time setup key, installer lock, and optional MFA.</p></div></div></div><p class="setup-help">Need help? See <code>docs/DEPLOYMENT.md</code> in the source package.</p></div><div class="installer-main">
<ol class="steps" aria-label="Setup progress"><li class="active"><span>1</span> System</li><li><span>2</span> Database</li><li><span>3</span> Organization</li><li><span>4</span> Security</li></ol>
<?php if ($pendingConfig): ?><div class="alert" role="alert">Private configuration exists but setup is not locked. Run <code>php bin/lock-existing-install.php</code> on the server after checking your database. This protects existing accounts from takeover.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert" role="alert"><strong>Unable to complete installation</strong><ul><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" id="setup-form" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $h($_SESSION['setup_csrf']) ?>">
<section class="panel step-panel" data-step="0"><span class="step-tag">STEP 01</span><h2>System check</h2><p>Run <code>php bin/setup-key.php</code> in your server terminal to create a secret setup key. Keep it private; it is deleted after setup.</p><div class="checks"><?php foreach ($requirements as $label=>$ok): ?><div class="check-row"><span class="dot <?= $ok ? 'good':'bad' ?>"><?= $ok ? '✓':'!' ?></span><span><?= $h($label) ?></span><strong><?= $ok ? 'Ready' : 'Required' ?></strong></div><?php endforeach; ?></div><?php if (!$ready): ?><p class="inline-warning">Fix the checks above and refresh the page before proceeding.</p><?php endif; ?></section>
<section class="panel step-panel" data-step="1" hidden><span class="step-tag">STEP 02</span><h2>Database connection</h2><p>Use an empty MySQL 8+ database and a dedicated user. TaskFlow will create its tables automatically.</p><div class="fields"><label>Database host <input name="db_host" required value="<?= $old('db_host','localhost') ?>" placeholder="localhost" maxlength="253"></label><label>Database port <input name="db_port" required value="<?= $old('db_port','3306') ?>" inputmode="numeric" type="number" min="1" max="65535"></label><label>Database name <input name="db_name" required value="<?= $old('db_name') ?>" placeholder="taskflow"></label><label>Database username <input name="db_user" required value="<?= $old('db_user') ?>" autocomplete="off"></label><label class="full">Database password <input name="db_pass" type="password" autocomplete="new-password" placeholder="Your private database password"></label></div><p class="hint">Credentials are written to a local, ignored PHP configuration file with restricted permissions; they are not sent to any external service.</p></section>
<section class="panel step-panel" data-step="2" hidden><span class="step-tag">STEP 03</span><h2>Your organization</h2><p>This will be your private team workspace.</p><div class="fields"><label class="full">Organization name <input name="org_name" value="<?= $old('org_name') ?>" required maxlength="150" placeholder="Acme Team"></label><label class="full">First team/department <input name="first_team" value="<?= $old('first_team','General') ?>" required maxlength="150" placeholder="General"></label><label class="full">Public application URL <input name="app_url" type="url" required value="<?= $old('app_url') ?>" placeholder="https://tasks.example.org"></label><label class="full">First administrator name <input name="admin_name" value="<?= $old('admin_name') ?>" required maxlength="100"></label><label class="full">First administrator email <input name="admin_email" value="<?= $old('admin_email') ?>" type="email" required autocomplete="email" maxlength="190"></label></div></section>
<section class="panel step-panel" data-step="3" hidden><span class="step-tag">STEP 04</span><h2>Secure your workspace</h2><p>Choose a unique, long administrator passphrase and provide the secret you generated on the server. These are never prefilled.</p><div class="fields"><label class="full">Administrator password <input name="admin_password" type="password" required minlength="14" maxlength="72" autocomplete="new-password" placeholder="14–72 bytes (a long passphrase is ideal)"></label><label class="full">Confirm password <input name="confirm_password" type="password" required minlength="14" maxlength="72" autocomplete="new-password"></label><label class="full">One-time setup key <input name="setup_key" type="password" required minlength="64" maxlength="64" autocomplete="off" placeholder="Paste the key from bin/setup-key.php"></label></div><div class="notice"><strong>After setup:</strong> The installer locks automatically, accounts require an administrator, and only HTTPS is accepted on public deployments.</div></section>
<div class="actions"><span class="step-counter" id="step-counter" aria-live="polite">Step 1 of 4</span><button type="button" id="back" class="btn btn-secondary" hidden>Back</button><button type="button" id="next" class="btn" <?= $ready ? '' : 'disabled' ?>>Continue</button><button type="submit" id="finish" class="btn" hidden <?= $ready ? '' : 'disabled' ?>>Create secure workspace</button></div>
</form></div></div>
<?php endif; ?><footer>TaskFlow · Self-hosted, built for teams</footer></div>
<script src="assets/installer.js" defer></script>
</body></html>
