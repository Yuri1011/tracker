<?php
require_once dirname(__DIR__, 2) . '/cors.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';

//тип запроса
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(["error" => "Метод не поддерживается"], 405);
}

$data = getJsonInput();

$action = $data['action'] ?? null;

if ($action === "register") {
    handleRegister($pdo, $data);
} 
else if ($action === "login") {
    handleLogin($pdo, $data);
} 
else {
    jsonResponse(["error" => "Неизвестное действие"], 400);
}

//Регистрация пользователя
function handleRegister($pdo, $data)
{
    //Проверка ответа JSON
    if (!$data || !isset($data['username']) || !isset($data['password'])) {
        jsonResponse(["error" => "Передайте username и password"], 400);
    }

    $username = trim($data['username']);
    $password = trim($data['password']);

    // Проверка пустых значений
    if ($username === "" || $password === "") {
        jsonResponse(["error" => "Поля username и password не должны быть пустыми"], 400);
    }

    //Проверка на существование пользователя
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        jsonResponse(["error" => "Пользователь с таким именем уже существует"], 409);
    }

    // Хешируем пароль
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Сохранение пользователя
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$username, $passwordHash]);

    jsonResponse(["message" => "Пользователь успешно зарегистрирован"], 201);
}

//Вход пользователя
function handleLogin($pdo, $data)
{
    global $JWT_SECRET;

    if (!isset($data['username'], $data['password'])) {
        jsonResponse(["error" => "Передайте username и password"], 400);
    }

    $username = trim($data['username']);
    $password = trim($data['password']);

    // Ищем пользователя
    $stmt = $pdo->prepare("SELECT id, username, password_hash, is_admin FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(["error" => "Неверное имя пользователя или пароль"], 401);
    }

    if (!password_verify($password, $user['password_hash'])) {
        jsonResponse(["error" => "Неверное имя пользователя или пароль"], 401);
    }

    // Генерируем токен
    $token = generateToken($user['id'], $user['is_admin']);

    // Возвращаем токен и безопасную информацию о пользователе (без password_hash)
    $userSafe = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'is_admin' => (int)$user['is_admin']
    ];

    jsonResponse(["token" => $token, "user" => $userSafe]);
}
