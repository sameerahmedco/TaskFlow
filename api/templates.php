<?php
/** Scoped reusable task templates with at most 30 checklist items. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/workload.php';
start_secure_session();
$me = current_user_record();
$method = effective_method();
$pdo = get_pdo();
if ($method === 'GET') {
    $teamId = filter_var($_GET['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$teamId) json_error('Select a team.', 400);
    require_team_access((int) $teamId);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $stmt = $pdo->prepare('SELECT id,title,description,priority,estimate_minutes,created_by,created_at FROM task_templates WHERE team_id=? ORDER BY id DESC LIMIT 101');
    $stmt->execute([(int) $teamId]);
    $templates = $stmt->fetchAll();
    $truncated = count($templates) > 100;
    if ($truncated) array_pop($templates);
    if ($templates) {
        $ids = array_column($templates, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $items = $pdo->prepare("SELECT template_id,content FROM task_template_items WHERE template_id IN ($marks) ORDER BY position,id");
        $items->execute($ids);
        $byTemplate = [];
        foreach ($items->fetchAll() as $row) $byTemplate[$row['template_id']][] = $row['content'];
        foreach ($templates as &$template) $template['items'] = $byTemplate[$template['id']] ?? [];
        unset($template);
    }
    json_response(['templates' => $templates,'truncated' => $truncated]);
}
if (!in_array($method, ['POST','PUT','DELETE'], true)) json_error('Method not allowed.', 405);
require_csrf();
$body = get_json_body();
$id = null;
if ($method === 'POST') {
    $teamId = filter_var($body['team_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$teamId) json_error('Select a team.', 400);
    require_team_access((int) $teamId, ['administrator','manager']);
} else {
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) json_error('Invalid template.', 400);
    $stmt = $pdo->prepare('SELECT team_id FROM task_templates WHERE id=?');
    $stmt->execute([(int) $id]);
    $teamId = (int) $stmt->fetchColumn();
    if (!$teamId) json_error('Template not found.', 404);
    require_team_access($teamId, ['administrator','manager']);
}
if ($method === 'DELETE') {
    $pdo->prepare('DELETE FROM task_templates WHERE id=? AND team_id=?')->execute([(int) $id,(int) $teamId]);
    json_response(['ok' => true]);
}
if (!is_string($body['title'] ?? null) || !is_string($body['description'] ?? '')) json_error('Invalid template fields.', 400);
$title = clean_str($body['title'], 255);
if ($title === '') json_error('Template title is required.', 400);
$description = clean_str($body['description'] ?? '', 5000);
$priority = $body['priority'] ?? 'normal';
if (!is_string($priority) || !in_array($priority, ['none','low','normal','high','urgent'], true)) json_error('Invalid template priority.', 400);
try { $estimate = taskflow_estimate_minutes($body['estimate_minutes'] ?? null); }
catch (InvalidArgumentException $error) { json_error($error->getMessage(), 400); }
$items = $body['items'] ?? [];
if (!is_array($items) || count($items) > 30) json_error('Templates allow at most 30 checklist items.', 400);
$cleanItems = [];
foreach ($items as $item) {
    if (!is_string($item) || strlen($item) > 1000) json_error('Invalid template checklist item.', 400);
    $content = clean_str($item, 255);
    if ($content !== '') $cleanItems[] = $content;
}
try {
    $pdo->beginTransaction();
    if ($method === 'POST') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM task_templates WHERE team_id=?');
        $count->execute([(int) $teamId]);
        if ((int) $count->fetchColumn() >= 100) throw new DomainException('This team has reached 100 templates.');
        $pdo->prepare('INSERT INTO task_templates (team_id,title,description,priority,estimate_minutes,created_by) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$teamId,$title,$description,$priority,$estimate,(int)$me['id']]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE task_templates SET title=?,description=?,priority=?,estimate_minutes=? WHERE id=? AND team_id=?')
            ->execute([$title,$description,$priority,$estimate,(int)$id,(int)$teamId]);
        $pdo->prepare('DELETE FROM task_template_items WHERE template_id=?')->execute([(int)$id]);
    }
    $stmt = $pdo->prepare('INSERT INTO task_template_items (template_id,content,position) VALUES (?,?,?)');
    foreach ($cleanItems as $pos => $content) $stmt->execute([(int)$id,$content,$pos]);
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(), 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Template save failed: '.$e->getMessage());
    json_error('Could not save template.', 500);
}
json_response(['ok' => true,'id' => (int)$id], $method === 'POST' ? 201 : 200);
