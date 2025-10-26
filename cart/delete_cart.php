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
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../auth/jwt_utils.php';

// دریافت توکن از هدر Authorization
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$userId = null;

if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $jwt = $matches[1];
    $authResult = verify_jwt_token($jwt);
    if ($authResult['valid']) {
        $userId = $authResult['uid'];
    }
}

$conn = getPDO();

if (!$conn) {
    throw new Exception("اتصال به پایگاه داده برقرار نیست.");
}

$data = json_decode(file_get_contents("php://input"), true);

$productId = (int)($data['product_id'] ?? 0);
$guestToken = $data['guest_token'] ?? null;

if (!$productId) {
    http_response_code(400);
    echo json_encode(['error' => 'شناسه کالا معتبر نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// پیدا کردن سبد خرید کاربر یا مهمان
$cartId = null;

if ($userId) {
    // کاربر لاگین شده
    $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cart = $stmt->fetch();
    
    if ($cart) {
        $cartId = $cart['id'];
    }
} else {
    // کاربر مهمان
    if (!$guestToken) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'توکن مهمان نیاز است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
    $stmt->execute([$guestToken]);
    $cart = $stmt->fetch();
    
    if ($cart) {
        $cartId = $cart['id'];
    }
}

// اگر سبد وجود نداشت
if (!$cartId) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'سبد خرید یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی وجود محصول در سبد
$stmt = $conn->prepare("SELECT id FROM cart_items WHERE cart_id = ? AND product_id = ?");
$stmt->execute([$cartId, $productId]);
$cartItem = $stmt->fetch();

if (!$cartItem) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'محصول در سبد خرید یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// حذف محصول از سبد
$stmt = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?");
$result = $stmt->execute([$cartId, $productId]);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'محصول با موفقیت از سبد حذف شد.'], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'خطا در حذف محصول.'], JSON_UNESCAPED_UNICODE);
}
