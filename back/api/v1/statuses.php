<?php
require_once dirname(__DIR__, 2) . '/cors.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';
// simple router: GET - list, POST - create, PUT - update, DELETE - delete
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

// GET: list statuses
if ($method === 'GET') {
	$stmt = $pdo->query('SELECT id, name, position FROM statuses ORDER BY position ASC');
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	jsonResponse(['statuses' => $rows]);
}

// For modifying endpoints require admin
$user = null;
try {
	$user = authenticate();
} catch (Exception $e) {
	jsonResponse(['error' => 'Unauthorized'], 401);
}

// normalize
if (is_object($user)) {
	$userId = $user->user_id ?? ($user->id ?? null);
	$isAdmin = property_exists($user, 'is_admin') ? $user->is_admin : ($user->isAdmin ?? 0);
} elseif (is_array($user)) {
	$userId = $user['user_id'] ?? $user['id'] ?? null;
	$isAdmin = $user['is_admin'] ?? $user['isAdmin'] ?? 0;
} else {
	$userId = null; $isAdmin = 0;
}

if (!$isAdmin) jsonResponse(['error' => 'Forbidden'], 403);

// POST: create
if ($method === 'POST') {
	$data = getJsonInput();
	$name = trim($data['name'] ?? '');
	$position = isset($data['position']) ? (int)$data['position'] : null;
	if ($name === '') jsonResponse(['error' => 'Name required'], 400);
	// uniqueness
	$stmt = $pdo->prepare('SELECT id FROM statuses WHERE name = ?');
	$stmt->execute([$name]);
	if ($stmt->fetch()) jsonResponse(['error' => 'Status with this name already exists'], 409);

	if ($position === null) {
		$stmt = $pdo->query('SELECT COALESCE(MAX(position),0)+1 FROM statuses');
		$position = (int)$stmt->fetchColumn();
	}

	$ins = $pdo->prepare('INSERT INTO statuses (name, position) VALUES (:name, :pos)');
	$ins->execute([':name' => $name, ':pos' => $position]);
	$id = (int)$pdo->lastInsertId();
	$stmt = $pdo->prepare('SELECT id, name, position FROM statuses WHERE id = ?');
	$stmt->execute([$id]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	jsonResponse(['status' => $row], 201);
}

// PUT: update
if ($method === 'PUT' || $method === 'PATCH') {
	// id can be query param or in body
	$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
	$data = getJsonInput();
	if (!$id) $id = isset($data['id']) ? (int)$data['id'] : null;
	if (!$id) jsonResponse(['error' => 'id required'], 400);

	$name = isset($data['name']) ? trim($data['name']) : null;
	$position = array_key_exists('position', $data) ? (int)$data['position'] : null;

	// ensure exists
	$stmt = $pdo->prepare('SELECT id FROM statuses WHERE id = ?');
	$stmt->execute([$id]);
	if (!$stmt->fetch()) jsonResponse(['error' => 'Not found'], 404);

	if ($name !== null && $name === '') jsonResponse(['error' => 'Name cannot be empty'], 400);
	if ($name !== null) {
		$stmt = $pdo->prepare('SELECT id FROM statuses WHERE name = ? AND id != ?');
		$stmt->execute([$name, $id]);
		if ($stmt->fetch()) jsonResponse(['error' => 'Another status with this name exists'], 409);
	}

	$updates = [];
	$params = [':id' => $id];
	if ($name !== null) { $updates[] = 'name = :name'; $params[':name'] = $name; }
	if ($position !== null) { $updates[] = 'position = :pos'; $params[':pos'] = $position; }
	if ($updates) {
		$sql = 'UPDATE statuses SET ' . implode(', ', $updates) . ' WHERE id = :id';
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
	}
	$stmt = $pdo->prepare('SELECT id, name, position FROM statuses WHERE id = ?');
	$stmt->execute([$id]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	jsonResponse(['status' => $row]);
}

// DELETE
if ($method === 'DELETE') {
	$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
	if (!$id) jsonResponse(['error' => 'id required'], 400);

	// check usage in tasks
	$stmt = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE status_id = ?');
	$stmt->execute([$id]);
	$cnt = (int)$stmt->fetchColumn();
	if ($cnt > 0) jsonResponse(['error' => 'Status in use by tasks', 'count' => $cnt], 409);

	$del = $pdo->prepare('DELETE FROM statuses WHERE id = ?');
	$del->execute([$id]);
	jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
