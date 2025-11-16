<?php
require_once dirname(__DIR__, 2) . '/cors.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';

// HTTP method, support override via JSON `_method` for clients that send POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $body = getJsonInput();
    if (is_array($body) && isset($body['_method'])) {
        $maybe = strtoupper($body['_method']);
        if (in_array($maybe, ['PUT', 'PATCH', 'DELETE'])) {
            $method = $maybe;
        }
    }
}

// GET: list tags with usage count
if ($method === 'GET') {
    $stmt = $pdo->query('SELECT t.id, t.name, COALESCE((SELECT COUNT(*) FROM task_tags tt WHERE tt.tag_id = t.id),0) as usage_count FROM tags t ORDER BY t.name ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['tags' => $rows]);
}

// modifying endpoints require admin
$user = null;
try {
    $user = authenticate();
} catch (Exception $e) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if (is_object($user)) {
    $isAdmin = property_exists($user, 'is_admin') ? $user->is_admin : ($user->isAdmin ?? 0);
} elseif (is_array($user)) {
    $isAdmin = $user['is_admin'] ?? $user['isAdmin'] ?? 0;
} else {
    $isAdmin = 0;
}

if (!$isAdmin) jsonResponse(['error' => 'Forbidden'], 403);

if ($method === 'POST') {
    $data = getJsonInput();
    $name = mb_strtolower(trim($data['name'] ?? ''));
    if ($name === '') jsonResponse(['error' => 'Name required'], 400);
    if (mb_strlen($name) > 25) jsonResponse(['error' => 'Tag too long (max 25 characters)'], 400);
    // uniqueness
    $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
    $stmt->execute([$name]);
    if ($stmt->fetch()) jsonResponse(['error' => 'Tag with this name already exists'], 409);

    $ins = $pdo->prepare('INSERT INTO tags (name) VALUES (:name)');
    $ins->execute([':name' => $name]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT id, name FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    jsonResponse(['tag' => $row], 201);
}

// PUT/PATCH: update
if ($method === 'PUT' || $method === 'PATCH') {
    $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
    $data = getJsonInput();
    if (!$id) $id = isset($data['id']) ? (int)$data['id'] : null;
    if (!$id) jsonResponse(['error' => 'id required'], 400);

    $name = isset($data['name']) ? mb_strtolower(trim($data['name'])) : null;
    $stmt = $pdo->prepare('SELECT id FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Not found'], 404);

    if ($name !== null) {
        if ($name === '') jsonResponse(['error' => 'Name cannot be empty'], 400);
        if (mb_strlen($name) > 25) jsonResponse(['error' => 'Tag too long (max 25 characters)'], 400);
        $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = ? AND id != ?');
        $stmt->execute([$name, $id]);
        if ($stmt->fetch()) jsonResponse(['error' => 'Another tag with this name exists'], 409);
        $upd = $pdo->prepare('UPDATE tags SET name = :name WHERE id = :id');
        $upd->execute([':name' => $name, ':id' => $id]);
    }
    $stmt = $pdo->prepare('SELECT id, name FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    jsonResponse(['tag' => $row]);
}

// DELETE: forbid if used by tasks
if ($method === 'DELETE') {
    $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) jsonResponse(['error' => 'id required'], 400);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM task_tags WHERE tag_id = ?');
    $stmt->execute([$id]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) jsonResponse(['error' => 'Tag in use by tasks', 'count' => $cnt], 409);
    $del = $pdo->prepare('DELETE FROM tags WHERE id = ?');
    $del->execute([$id]);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
