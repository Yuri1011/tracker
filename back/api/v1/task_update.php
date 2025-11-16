<?php
require_once dirname(__DIR__, 2) . '/cors.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$data = getJsonInput();
$action = $data['action'] ?? null;

$user = authenticate();

if (is_object($user)) {
    $userId = $user->user_id ?? ($user->id ?? null);
    $isAdmin = property_exists($user, 'is_admin') ? $user->is_admin : ($user->isAdmin ?? 0);
} elseif (is_array($user)) {
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    $isAdmin = $user['is_admin'] ?? $user['isAdmin'] ?? 0;
} else {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if (!$isAdmin) jsonResponse(['error' => 'Forbidden'], 403);

if ($action === 'status') {
    $taskId = isset($data['task_id']) ? (int)$data['task_id'] : null;
    $statusId = isset($data['status_id']) ? (int)$data['status_id'] : null;
    if (!$taskId || !$statusId) jsonResponse(['error' => 'task_id and status_id required'], 400);

    $stmt = $pdo->prepare('UPDATE tasks SET status_id = :sid, updated_at = NOW() WHERE id = :tid');
    $stmt->execute([':sid' => $statusId, ':tid' => $taskId]);

    jsonResponse(['ok' => true]);
}

if ($action === 'response') {
    $taskId = isset($data['task_id']) ? (int)$data['task_id'] : null;
    $message = trim($data['message'] ?? '');
    if (!$taskId || $message === '') jsonResponse(['error' => 'task_id and message required'], 400);

    $ins = $pdo->prepare('INSERT INTO responses (task_id, admin_id, message, created_at) VALUES (:tid, :aid, :msg, NOW())');
    $ins->execute([':tid' => $taskId, ':aid' => $userId, ':msg' => $message]);
    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT r.id, r.message, r.created_at, u.username as admin_username FROM responses r LEFT JOIN users u ON u.id = r.admin_id WHERE r.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonResponse(['response' => $row], 201);
}

if ($action === 'tags') {
    $taskId = isset($data['task_id']) ? (int)$data['task_id'] : null;
    $tags = $data['tags'] ?? null; // expect array
    if (!$taskId || !is_array($tags)) jsonResponse(['error' => 'task_id and tags array required'], 400);

    foreach ($tags as $tname) {
        $tname = mb_strtolower(trim((string)$tname));
        if ($tname === '') continue;
        if (mb_strlen($tname) > 25) jsonResponse(['error' => 'Tag too long (max 25 characters): ' . $tname], 400);
    }

    try {
        $pdo->beginTransaction();

        $del = $pdo->prepare('DELETE FROM task_tags WHERE task_id = :tid');
        $del->execute([':tid' => $taskId]);

        $find = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $insTag = $pdo->prepare('INSERT INTO tags (name) VALUES (:name)');
        $link = $pdo->prepare('INSERT INTO task_tags (task_id, tag_id) VALUES (:tid, :tagid)');

        foreach ($tags as $tname) {
            $tname = mb_strtolower(trim((string)$tname));
            if ($tname === '') continue;
            $find->execute([$tname]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $tagId = (int)$row['id'];
            } else {
                $insTag->execute([':name' => $tname]);
                $tagId = (int)$pdo->lastInsertId();
            }
            try {
                $link->execute([':tid' => $taskId, ':tagid' => $tagId]);
            } catch (Exception $e) {  }
        }

        $pdo->commit();

        $pdo->prepare('DELETE FROM tags WHERE id NOT IN (SELECT DISTINCT tag_id FROM task_tags)')->execute();

        $stmt = $pdo->prepare('SELECT tg.name FROM task_tags tt JOIN tags tg ON tg.id = tt.tag_id WHERE tt.task_id = ?');
        $stmt->execute([$taskId]);
        $res = $stmt->fetchAll(PDO::FETCH_COLUMN);

        jsonResponse(['tags' => $res]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to update tags'], 500);
    }
}

jsonResponse(['error' => 'Unknown action'], 400);
