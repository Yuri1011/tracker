<?php
require_once dirname(__DIR__, 2) . '/cors.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$user = authenticate();

// normalize
if (is_object($user)) {
    $userId = $user->user_id ?? ($user->id ?? null);
    $isAdmin = property_exists($user, 'is_admin') ? $user->is_admin : ($user->isAdmin ?? 0);
} elseif (is_array($user)) {
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    $isAdmin = $user['is_admin'] ?? $user['isAdmin'] ?? 0;
} else {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if (!$userId) jsonResponse(['error' => 'Unauthorized'], 401);

$stmt = $pdo->prepare('SELECT id, username, is_admin, created_at FROM users WHERE id = ?');
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

jsonResponse(['user' => $row]);
