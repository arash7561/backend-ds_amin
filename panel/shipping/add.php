<?php
require_once '../../db_connection.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$conn = getPDO();

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data) || empty($data['title'])) {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'title required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$title = htmlspecialchars(trim($data['title']));

if (empty($title)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'title cannot be empty'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $conn->prepare("INSERT INTO shippings (title) VALUES (?)");
    $stmt->execute([$title]);
    
    echo json_encode([
        'status' => true,
        'message' => 'روش ارسال با موفقیت اضافه شد.',
        'id' => $conn->lastInsertId()
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'error' => 'خطا در ثبت روش ارسال: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'error' => 'خطای غیرمنتظره: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
