<?php
require_once '../../db_connection.php';

/* CORS */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['id']) || empty($data['title'])) {
    http_response_code(400);
    echo json_encode(['status'=>false,'error'=>'id and title required'],JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)$data['id'];
$title = trim($data['title']);

if ($title === '') {
    http_response_code(400);
    echo json_encode(['status'=>false,'error'=>'title cannot be empty'],JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE shippings SET title = ? WHERE id = ?");
    $stmt->execute([$title, $id]);

    echo json_encode([
        'status' => true,
        'message' => 'روش ارسال ویرایش شد'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'=>false,
        'error'=>'خطا در ویرایش: '.$e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
