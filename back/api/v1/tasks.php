<?php
require_once dirname(__DIR__, 2) . '/cors.php';
require_once dirname(__DIR__, 2) . '/bootstrap.php';

// helper: получить id из URL если вызывают /tasks/{id}
function getIdFromRequest() {
    // Попробуем извлечь число из конца REQUEST_URI
    $uri = $_SERVER['REQUEST_URI']; // например /api/v1/tasks/123
    $parts = explode('/', trim($uri, '/'));
    $last = end($parts);
    if (is_numeric($last)) return (int)$last;
    // также поддерживаем ?id=123
    if (isset($_GET['id']) && is_numeric($_GET['id'])) return (int)$_GET['id'];
    return null;
}

// helper: конвертировать результат fetch в читаемый формат (добавим теги)
function attachTags(PDO $pdo, array $tasks) : array {
    if (empty($tasks)) return $tasks;
    $ids = array_map(function($t){ return (int)$t['id']; }, $tasks);
    // подготовим IN
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT tt.task_id, tg.name
            FROM task_tags tt
            JOIN tags tg ON tg.id = tt.tag_id
            WHERE tt.task_id IN ($in)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $map = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$r['task_id'];
        $map[$tid][] = $r['name'];
    }
    foreach ($tasks as &$t) {
        $t['tags'] = $map[$t['id']] ?? [];
    }
    return $tasks;
}

// Получаем авторизованного пользователя (или возвращаем 401 внутри authenticate())
$user = null;
try {
    $user = authenticate(); // ожидаем объект или массив с полями user_id и is_admin
} catch (Exception $e) {
    // authenticate() в bootstrap может сам отправлять jsonResponse(401). На всякий случай:
    jsonResponse(['error' => 'Unauthorized'], 401);
}

// приводим к единому виду
if (is_object($user)) {
    $userId = $user->user_id ?? ($user->id ?? null);
    $isAdmin = property_exists($user, 'is_admin') ? $user->is_admin : ($user->isAdmin ?? 0);
} elseif (is_array($user)) {
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    $isAdmin = $user['is_admin'] ?? $user['isAdmin'] ?? 0;
} else {
    $userId = null;
    $isAdmin = 0;
}

// Если authenticate() возвращает null (и не вызвал jsonResponse), то считаем неавторизованным
if (!$userId) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------
// POST /api/v1/tasks  - создать задачу
// тело: { title, description, tags: ["a","b"] }
// ---------------------------
if ($method === 'POST') {
    $data = getJsonInput();
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '');
    $tags = $data['tags'] ?? [];

    if ($title === '') jsonResponse(['error' => 'Title required'], 400);

    // Вставляем задачу (по умолчанию статус_id = 1 -> ToDo)
    $stmt = $pdo->prepare("INSERT INTO tasks (user_id, title, description) VALUES (:uid, :title, :desc)");
    $stmt->execute([
        ':uid' => $userId,
        ':title' => $title,
        ':desc' => $description
    ]);
    $taskId = (int)$pdo->lastInsertId();

    // Обработка тэгов (создать если не существует, связать)
    if (is_array($tags) && count($tags) > 0) {
        // normalize: trim + lowercase, remove empties and duplicates
        $tags = array_values(array_filter(array_map(function($t){ return mb_strtolower(trim((string)$t)); }, $tags)));
        $tags = array_unique($tags);
        foreach ($tags as $tname) {
            if ($tname === '') continue;
            // вставляем тег если отсутствует
            $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
            $stmt->execute([$tname]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $tagId = (int)$row['id'];
            } else {
                $ins = $pdo->prepare("INSERT INTO tags (name) VALUES (:name)");
                $ins->execute([':name' => $tname]);
                $tagId = (int)$pdo->lastInsertId();
            }
            // связываем в task_tags (игнорируем ошибки дублирования)
            try {
                $link = $pdo->prepare("INSERT INTO task_tags (task_id, tag_id) VALUES (:tid, :tagid)");
                $link->execute([':tid' => $taskId, ':tagid' => $tagId]);
            } catch (Exception $e) { /* игнорируем */ }
        }
    }

    // Вернём созданную задачу
    $stmt = $pdo->prepare("SELECT t.*, s.name as status_name FROM tasks t JOIN statuses s ON s.id = t.status_id WHERE t.id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    // добавим теги
    $task['tags'] = [];
    $stmt = $pdo->prepare("SELECT tg.name FROM task_tags tt JOIN tags tg ON tg.id = tt.tag_id WHERE tt.task_id = ?");
    $stmt->execute([$taskId]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $task['tags'][] = $r['name'];

    jsonResponse(['task' => $task], 201);
}

// ---------------------------
// GET /api/v1/tasks       - список (с фильтрами/сортировкой/paging)
// GET /api/v1/tasks/{id}  - одна задача
// ---------------------------
if ($method === 'GET') {
    $idFromUrl = getIdFromRequest();

    if ($idFromUrl) {
        // Получить одну задачу: владелец или админ
        $stmt = $pdo->prepare("SELECT t.*, s.name as status_name, u.username as author FROM tasks t
                               JOIN statuses s ON s.id = t.status_id
                               JOIN users u ON u.id = t.user_id
                               WHERE t.id = ?");
        $stmt->execute([$idFromUrl]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) jsonResponse(['error' => 'Not found'], 404);
        if (!$isAdmin && $task['user_id'] != $userId) jsonResponse(['error' => 'Forbidden'], 403);

        // тэги
        $stmt = $pdo->prepare("SELECT tg.name FROM task_tags tt JOIN tags tg ON tg.id = tt.tag_id WHERE tt.task_id = ?");
        $stmt->execute([$idFromUrl]);
        $tags = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $tags[] = $r['name'];
        $task['tags'] = $tags;

        // ответы (responses)
        $stmt = $pdo->prepare("SELECT r.id, r.message, r.created_at, u.username as admin_username
                               FROM responses r LEFT JOIN users u ON u.id = r.admin_id
                               WHERE r.task_id = ? ORDER BY r.created_at ASC");
        $stmt->execute([$idFromUrl]);
        $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $task['responses'] = $responses;

        jsonResponse(['task' => $task]);
    }

    // Получение списка
    // параметры: search, status_id, sort_by, sort_dir, page, per_page
    $search = trim($_GET['search'] ?? '');
    $statusId = isset($_GET['status_id']) && is_numeric($_GET['status_id']) ? (int)$_GET['status_id'] : null;
    $sortBy = in_array($_GET['sort_by'] ?? '', ['created_at','updated_at','id']) ? $_GET['sort_by'] : 'created_at';
    $sortDir = (strtolower($_GET['sort_dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(5, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    // Базовый SQL (админ видит все, пользователь — только свои)
    $where = [];
    $params = [];

    if (!$isAdmin) {
        $where[] = "t.user_id = :uid";
        $params[':uid'] = $userId;
    }

    if ($statusId) {
        $where[] = "t.status_id = :sid";
        $params[':sid'] = $statusId;
    }

    // filter by tag name (exact match)
    $tagFilter = isset($_GET['tag']) ? trim($_GET['tag']) : '';
    if ($tagFilter !== '') {
        $where[] = "EXISTS (SELECT 1 FROM task_tags tt JOIN tags tg ON tg.id = tt.tag_id WHERE tt.task_id = t.id AND tg.name = :tagname)";
        $params[':tagname'] = mb_strtolower($tagFilter);
    }

    if ($search !== '') {
        $where[] = "(t.title LIKE :q OR t.description LIKE :q)";
        $params[':q'] = '%' . $search . '%';
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    // total
    $countSql = "SELECT COUNT(*) FROM tasks t $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // data
        $sql = "SELECT t.id, t.title, t.description, t.status_id, s.name as status_name, t.user_id, u.username as author, t.created_at, t.updated_at
            FROM tasks t
            JOIN statuses s ON s.id = t.status_id
            LEFT JOIN users u ON u.id = t.user_id
            $whereSql
            ORDER BY t.$sortBy $sortDir
            LIMIT :offset, :perpage";
    $stmt = $pdo->prepare($sql);
    // привязываем именованные + offset/perpage
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(':perpage', (int)$perPage, PDO::PARAM_INT);
    $stmt->execute();
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // добавим тэги
    $tasks = attachTags($pdo, $tasks);

    jsonResponse([
        'tasks' => $tasks,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

// остальные методы не поддерживаем
jsonResponse(['error' => 'Method not allowed'], 405);
