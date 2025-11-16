<?php
require_once dirname(__DIR__, 2) . '/cors.php';
header('Content-Type: application/json; charset=utf-8');
$input = file_get_contents('php://input');
echo json_encode([
    'input_raw' => $input,
    'post' => $_POST,
    'headers' => getallheaders(),
    'method' => $_SERVER['REQUEST_METHOD']
], JSON_UNESCAPED_UNICODE);

?>
