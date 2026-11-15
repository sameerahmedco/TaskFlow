<?php
/**
 * priorities.php — "Weekly Goals" board.
 * Each person lists as many goals as they want for the week; everyone on
 * the team can see everyone else's. A person edits their own list; an
 * Administrator (any team) or that team's Manager may also edit a
 * teammate's list on their behalf via target_user_id.
 *
 * GET  ?team_id=X&week_offset=0
 *      -> every active team member's list for that week, each tagged
 *         with roles/department so the UI can always show them.
 * POST { week_offset, items:[{content,is_done}], target_user_id? }
 *      -> FULLY REPLACES the target's list for that week — whatever is
 *         sent is the new list; nothing from the old list survives
 *         unless it's re-sent.
 */
require_once __DIR__ . '/../includes/functions.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();

const MAX_GOALS_PER_WEEK = 20; // generous ceiling just to prevent abuse, not a UI limit

if ($method === 'GET') {
    $teamId = (int) ($_GET['team_id'] ?? 0);
    require_team_access($teamId);
    $weekOffset = (int) ($_GET['week_offset'] ?? 0);
    [$monday, ] = week_bounds($weekOffset);
    $weekStart = $monday->format('Y-m-d');

    $members = $pdo->prepare('SELECT id, name, avatar_color FROM users WHERE team_id = ? AND is_active = 1 ORDER BY name');
    $members->execute([$teamId]);
    $members = $members->fetchAll();
    $members = attach_roles_and_team($pdo, $members);

    $stmt = $pdo->prepare(
        'SELECT wp.user_id, wp.position, wp.content, wp.is_done
         FROM weekly_priorities wp JOIN users u ON u.id = wp.user_id
         WHERE u.team_id = ? AND wp.week_start = ? ORDER BY wp.user_id, wp.position'
    );
    $stmt->execute([$teamId, $weekStart]);
    $rows = $stmt->fetchAll();

    $byUser = [];
    foreach ($rows as $r) {
        $byUser[$r['user_id']][] = ['position' => (int) $r['position'], 'content' => $r['content'], 'is_done' => (bool) $r['is_done']];
    }
    foreach ($members as &$m) {
        $m['priorities'] = $byUser[$m['id']] ?? [];
    }

    json_response(['week_start' => $weekStart, 'members' => $members]);
}

require_csrf();
$body = get_json_body();

if ($method === 'POST') {
    $weekOffset = (int) ($body['week_offset'] ?? 0);
    [$monday, ] = week_bounds($weekOffset);
    $weekStart = $monday->format('Y-m-d');

    // Work out WHO this save applies to: yourself by default, or someone
    // else if you're an Administrator, or the Manager of that person's team.
    $targetUserId = !empty($body['target_user_id']) ? (int) $body['target_user_id'] : (int) $me['id'];

    if ($targetUserId === (int) $me['id']) {
        if (!$me['team_id']) {
            json_error('You need to be on a team to set weekly goals.');
        }
        $targetTeamId = (int) $me['team_id'];
    } else {
        $stmt = $pdo->prepare('SELECT team_id, is_active FROM users WHERE id = ?');
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch();
        if (!$target || !(int) $target['is_active']) {
            json_error('That teammate was not found.', 404);
        }
        $targetTeamId = (int) $target['team_id'];
        $canEditOthers = in_array('administrator', $me['roles'], true)
            || (in_array('manager', $me['roles'], true) && (int) $me['team_id'] === $targetTeamId);
        if (!$canEditOthers) {
            json_error('You can only edit your own goals, or a teammate\'s if you\'re their Manager or an Administrator.', 403);
        }
    }

    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    // Keep only non-empty entries, in the order the client sent them.
    $clean = [];
    foreach ($items as $item) {
        $content = clean_str($item['content'] ?? '', 255);
        if ($content === '') continue;
        $clean[] = ['content' => $content, 'is_done' => !empty($item['is_done'])];
        if (count($clean) >= MAX_GOALS_PER_WEEK) break;
    }

    // Full replace for this user+week: whatever was sent IS the new list.
    // Nothing from the previous save survives unless it was resent — this
    // is deliberate so edits never leave stale leftovers behind.
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM weekly_priorities WHERE user_id = ? AND week_start = ?')->execute([$targetUserId, $weekStart]);
        $insert = $pdo->prepare('INSERT INTO weekly_priorities (user_id, week_start, position, content, is_done) VALUES (?,?,?,?,?)');
        foreach ($clean as $i => $item) {
            $insert->execute([$targetUserId, $weekStart, $i + 1, $item['content'], $item['is_done'] ? 1 : 0]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        json_error('Could not save these goals.', 500);
    }

    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
