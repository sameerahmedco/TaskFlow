<?php
/**
 * mailer.php — a small, dependency-free SMTP client, plus a notify()
 * dispatcher that checks the Administrator's Advanced Settings before
 * sending anything. Email is always best-effort: a send failure is
 * logged, never thrown back at the user as an error, since no task
 * action should ever fail just because email couldn't go out.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/secrets.php';

function get_app_settings(): array {
    $stmt = get_pdo()->query('SELECT * FROM app_settings WHERE id = 1');
    $row = $stmt->fetch();
    if (!$row) {
        // Table exists but the single row is missing for some reason — self-heal.
        get_pdo()->exec('INSERT IGNORE INTO app_settings (id) VALUES (1)');
        $stmt = get_pdo()->query('SELECT * FROM app_settings WHERE id = 1');
        $row = $stmt->fetch();
    }
    if ($row) $row['smtp_password'] = secret_decrypt((string) $row['smtp_password']);
    return $row ?: [];
}

/**
 * Sends one email via SMTP using the connection details in $settings.
 * Returns ['ok' => bool, 'error' => string|null].
 */
function smtp_send(array $settings, string $to, string $subject, string $body): array {
    $host = trim($settings['smtp_host'] ?? '');
    $port = (int) ($settings['smtp_port'] ?? 587);
    $encryption = $settings['smtp_encryption'] ?? 'tls';
    $username = $settings['smtp_username'] ?? '';
    $password = $settings['smtp_password'] ?? '';
    $fromEmail = $settings['from_email'] ?? '';
    $fromName = $settings['from_name'] ?? 'TaskFlow';

    // Refuse SMTP header/command injection and avoid plaintext authentication.
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $fromEmail . $to . $username . $fromName . $subject)) {
        return ['ok' => false, 'error' => 'Invalid SMTP address or message headers.'];
    }
    if ($encryption === 'none' && ($username !== '' || $password !== '')) {
        return ['ok' => false, 'error' => 'SMTP authentication requires TLS or SSL.'];
    }
    $subject = trim($subject);
    if ($host === '' || $fromEmail === '') {
        return ['ok' => false, 'error' => 'SMTP host and from-address must be configured first.'];
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : '';
    $errno = 0; $errstr = '';
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true, 'verify_peer_name' => true,
        'peer_name' => $host, 'SNI_enabled' => true,
    ]]);
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        return ['ok' => false, 'error' => "Could not connect to $host:$port ($errstr)"];
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // Multiline responses have a dash after the code (e.g. "250-"); a
            // space after the code ("250 ") marks the final line.
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function (string $cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function (string $response, string $code) {
        return substr($response, 0, strlen($code)) === $code;
    };

    try {
        $greeting = $read();
        if (!$expect($greeting, '220')) {
            throw new Exception('Server did not greet with 220: ' . trim($greeting));
        }

        $write('EHLO taskflow.local');
        $ehlo = $read();
        if (!$expect($ehlo, '250')) {
            throw new Exception('EHLO failed: ' . trim($ehlo));
        }

        if ($encryption === 'tls') {
            $write('STARTTLS');
            $tlsResp = $read();
            if (!$expect($tlsResp, '220')) {
                throw new Exception('STARTTLS refused: ' . trim($tlsResp));
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('TLS negotiation failed.');
            }
            $write('EHLO taskflow.local');
            $ehlo2 = $read();
            if (!$expect($ehlo2, '250')) {
                throw new Exception('EHLO after STARTTLS failed: ' . trim($ehlo2));
            }
        }

        if ($username !== '') {
            $write('AUTH LOGIN');
            $authResp = $read();
            if (!$expect($authResp, '334')) {
                throw new Exception('AUTH LOGIN not accepted: ' . trim($authResp));
            }
            $write(base64_encode($username));
            $userResp = $read();
            if (!$expect($userResp, '334')) {
                throw new Exception('Username rejected: ' . trim($userResp));
            }
            $write(base64_encode($password));
            $passResp = $read();
            if (!$expect($passResp, '235')) {
                throw new Exception('Authentication failed — check the username/password.');
            }
        }

        $write('MAIL FROM:<' . $fromEmail . '>');
        $mailResp = $read();
        if (!$expect($mailResp, '250')) {
            throw new Exception('MAIL FROM rejected: ' . trim($mailResp));
        }

        $write('RCPT TO:<' . $to . '>');
        $rcptResp = $read();
        if (!$expect($rcptResp, '250') && !$expect($rcptResp, '251')) {
            throw new Exception('Recipient rejected: ' . trim($rcptResp));
        }

        $write('DATA');
        $dataResp = $read();
        if (!$expect($dataResp, '354')) {
            throw new Exception('DATA not accepted: ' . trim($dataResp));
        }

        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Date: ' . date('r'),
        ];
        // Escape lines that start with a lone "." per SMTP data rules.
        $escapedBody = preg_replace('/^\./m', '..', $body);
        $write(implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.");
        $sendResp = $read();
        if (!$expect($sendResp, '250')) {
            throw new Exception('Message not accepted: ' . trim($sendResp));
        }

        $write('QUIT');
        fclose($fp);
        return ['ok' => true, 'error' => null];
    } catch (Exception $e) {
        if (is_resource($fp)) fclose($fp);
        error_log('SMTP send failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Maps a notify_* event key (matches an app_settings column) to the
 * broader personal-preference category a user opts in/out of:
 * "messages", "new_tasks", or "mentions".
 */
function notification_category(string $eventKey): string {
    switch ($eventKey) {
        case 'task_created':
        case 'task_assigned':
        case 'task_completed':
        case 'task_updated':
        case 'due_reminder':
            return 'new_tasks';
        case 'mentioned':
            return 'mentions';
        case 'comment_added':
        case 'new_message':
        default:
            return 'messages';
    }
}

/** Fetches (and lazily creates) a user's personal notification preferences. */
function get_user_notification_prefs(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT * FROM user_notification_prefs WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare('INSERT IGNORE INTO user_notification_prefs (user_id) VALUES (?)')->execute([$userId]);
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    }
    return $row ?: ['email_messages' => 1, 'email_new_tasks' => 1, 'email_mentions' => 1, 'browser_enabled' => 0, 'browser_messages' => 1, 'browser_new_tasks' => 1, 'browser_mentions' => 1];
}

/**
 * Fire-and-forget notification dispatcher. $eventKey must match a
 * notify_* column in app_settings (e.g. 'task_created'). $recipientUserIds
 * are the intended recipients — an email only goes to someone if BOTH the
 * Administrator's org-wide toggle for that event AND that recipient's own
 * personal category preference are on. Never throws — a notification
 * failure must never break the request that triggered it.
 */
function notify(string $eventKey, array $recipientUserIds, string $subject, string $body): void {
    try {
        $pdo = get_pdo();
        $category = notification_category($eventKey);
        $emailPrefColumn = 'email_' . $category;
        $browserPrefColumn = 'browser_' . $category;

        $settings = get_app_settings();
        $emailGloballyOn = !empty($settings['smtp_enabled']) && !empty($settings['notify_' . $eventKey]);

        $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds))));
        foreach ($recipientUserIds as $userId) {
            $stmt = $pdo->prepare('SELECT email, is_active FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user || !(int) $user['is_active']) continue;

            $prefs = get_user_notification_prefs($pdo, $userId);

            // Email channel — needs the admin's org-wide SMTP + event toggle,
            // AND this person's own email preference for this category.
            if ($emailGloballyOn && !empty($prefs[$emailPrefColumn]) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                if (getenv('TASKFLOW_MAIL_QUEUE') === '1') {
                    $pdo->prepare('INSERT INTO mail_queue (recipient_user_id,recipient_email,subject,body) VALUES (?,?,?,?)')
                        ->execute([$userId, $user['email'], mb_substr($subject, 0, 255), $body]);
                } else {
                    smtp_send($settings, $user['email'], $subject, $body);
                }
            }

            // Store in-app notifications even when native browser alerts are off.
            // The polling UI still checks the user's browser notification preferences.
            // Retention is limited by bin/maintenance.php.
            {
                $logStmt = $pdo->prepare(
                    'INSERT INTO notification_log (user_id, category, title, body) VALUES (?,?,?,?)'
                );
                $logStmt->execute([$userId, $category, mb_substr($subject, 0, 255), mb_substr($body, 0, 500)]);
            }
        }
    } catch (Exception $e) {
        error_log('notify() failed: ' . $e->getMessage());
    }
}
