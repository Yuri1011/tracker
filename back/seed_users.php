<?php
require __DIR__ . '/bootstrap.php';

// Create two users: admin/admin and user/user if they do not exist
$users = [
    ['username' => 'admin', 'password' => 'admin', 'is_admin' => 1],
    ['username' => 'user', 'password' => 'user', 'is_admin' => 0],
];

foreach ($users as $u) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$u['username']]);
    if ($stmt->fetch()) {
        echo "User {$u['username']} already exists\n";
        continue;
    }
    $hash = password_hash($u['password'], PASSWORD_DEFAULT);
    $ins = $pdo->prepare('INSERT INTO users (username, password_hash, is_admin) VALUES (:username, :hash, :admin)');
    $ins->execute([':username' => $u['username'], ':hash' => $hash, ':admin' => $u['is_admin']]);
    echo "Created user {$u['username']}\n";
}

echo "Done.\n";
