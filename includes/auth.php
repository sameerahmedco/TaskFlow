<?php
/** Successful login helper shared by password-only and MFA challenge flows. */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

function finish_taskflow_login(PDO $pdo, array $user, bool $remember): array {
    session_regenerate_id(true);
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_enroll'], $_SESSION['csrf_token']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['auth_version'] = (int) $user['auth_version'];
    $_SESSION['last_activity'] = time();
    forget_remember_token();
    if ($remember) create_remember_token((int) $user['id']);

    $teamName = null;
    if ($user['team_id']) {
        $stmt = $pdo->prepare('SELECT name FROM teams WHERE id = ?');
        $stmt->execute([$user['team_id']]);
        $teamName = $stmt->fetchColumn() ?: null;
    }
    $rolesStmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = ?');
    $rolesStmt->execute([$user['id']]);
    return [
        'user' => [
            'id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'],
            'roles' => $rolesStmt->fetchAll(PDO::FETCH_COLUMN),
            'team_id' => $user['team_id'] ? (int) $user['team_id'] : null,
            'team_name' => $teamName, 'avatar_color' => $user['avatar_color'],
            'must_change_password' => (bool) $user['must_change_password'],
        ],
        'csrf_token' => csrf_token(),
    ];
}
