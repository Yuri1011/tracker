<?php
header('Content-Type: text/plain; charset=utf-8');

$dsn = 'mysql:host=127.0.0.1;dbname=tracker;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass);
    echo "УСПЕХ: подключение к базе прошло успешно!";
} catch (PDOException $e) {
    echo "ОШИБКА: " . $e->getMessage();
}
