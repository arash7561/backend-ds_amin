<?php
require_once __DIR__ . '/../../db_connection.php'; 
$auth = require_once __DIR__ . '/../../auth/auth_check.php';
$userId = $auth['user_id'] ?? null;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: http://localhost:3002');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => false,
        'message' => 'فقط درخواست POST مجاز است'
    ]);
    exit();
}

error_log("Remove Favorite - UserID: " . var_export($userId, true));

if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'status' => false,
        'message' => 'احراز هویت نامعتبر'
    ]);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['product_id'])) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'message' => 'شناسه محصول الزامی است'
    ]);
    exit();
}

$productId = (int)$input['product_id'];

$conn = getPDO();

try {
    // Check if favorite exists
    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    
    if (!$stmt->fetch()) {
        echo json_encode([
            'status' => true,
            'message' => 'محصول در علاقه‌مندی‌ها موجود نیست'
        ]);
        exit();
    }
    
    // Remove from favorites
    $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    
    echo json_encode([
        'status' => true,
        'message' => 'محصول از علاقه‌مندی‌ها حذف شد'
    ]);
    
} catch (PDOException $e) {
    error_log("Remove favorite error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در حذف از علاقه‌مندی‌ها'
    ]);
}
?>
