<?php
require __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// подключение к БД
$dsn = 'mysql:host=mysql;dbname=tracker;charset=utf8mb4';
$dbUser = 'root';
$dbPass = 'root';

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка подключения к базе: ' . $e->getMessage()]);
    exit;
}

//отправка JSON-ответа
function jsonResponse($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

//получаем JSON из запроса
//получаем JSON из запроса (кешируем, чтобы можно было читать тело несколько раз)
function getJsonInput() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input');
    $cached = json_decode($raw, true);
    return $cached;
}

//JWT параметры (читаем из окружения, если не задан — используем дефолт)
$JWT_SECRET = getenv('JWT_SECRET') ?: "supersecretkey";

//Создание JWT токен
function generateToken($userId, $isAdmin) {
    global $JWT_SECRET;

    $payload = [
        "user_id"  => $userId,
        "is_admin" => $isAdmin,
        "iat"      => time(),
        "exp"      => time() + 60 * 60 * 24  // срок действия 24 часа
    ];

    return JWT::encode($payload, $JWT_SECRET, 'HS256');
}

//Проверка токена
function authenticate() {
    global $JWT_SECRET;

    if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
        jsonResponse(["error" => "Требуется заголовок Authorization"], 401);
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'];
    if (!str_starts_with($header, "Bearer ")) {
        jsonResponse(["error" => "Некорректный формат токена"], 401);
    }

    $token = substr($header, 7);

    try {
        return JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    } catch (Exception $e) {
        jsonResponse(["error" => "Неверный или истёкший токен"], 401);
    }
}
