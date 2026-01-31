<?php
// CORS headers - Allow from any localhost origin for development
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (in_array($origin, $allowed_origins) || (strpos($origin, 'http://localhost') !== false || strpos($origin, 'http://127.0.0.1') !== false)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../db_connection.php'; 

// علامت‌گذاری که auth_check با require فراخوانی شده
$_ENV['AUTH_CHECK_REQUIRED'] = true;
$auth = require_once __DIR__ . '/../../auth/auth_check.php';
unset($_ENV['AUTH_CHECK_REQUIRED']);

$userId = $auth['user_id'] ?? null;

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => false,
        'message' => 'فقط درخواست POST مجاز است'
    ]);
    exit();
}

error_log("Add Favorite - UserID: " . var_export($userId, true));

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
    // Check if product exists and is active
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$productId]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            'status' => false,
            'message' => 'محصول یافت نشد'
        ]);
        exit();
    }
    
    // Check if already in favorites
    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    
    if ($stmt->fetch()) {
        echo json_encode([
            'status' => true,
            'message' => 'محصول قبلاً در علاقه‌مندی‌ها موجود است'
        ]);
        exit();
    }
    
    // Add to favorites
    $stmt = $conn->prepare("INSERT INTO favorites (user_id, product_id, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$userId, $productId]);
    
    echo json_encode([
        'status' => true,
        'message' => 'محصول به علاقه‌مندی‌ها اضافه شد'
    ]);
    
} catch (PDOException $e) {
    error_log("Add favorite error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در افزودن به علاقه‌مندی‌ها'
    ]);
}
?>
