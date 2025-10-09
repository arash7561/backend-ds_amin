<?php
require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
$auth = require_once '../auth/auth_check.php';
$userId = $auth['user_id'] ?? null;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: http://localhost:3000, http://localhost:3002');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

// گرفتن یا ساختن سبد خرید
if ($userId) {
    $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cart = $stmt->fetch();

    if (!$cart) {
        // اگر سبد برای کاربر وجود نداشت، بساز
        $stmt = $conn->prepare("INSERT INTO carts (user_id, created_at) VALUES (?, NOW())");
        $stmt->execute([$userId]);
        $cartId = $conn->lastInsertId();
    } else {
        $cartId = $cart['id'];
    }

} else {
    if (!$guestToken) {
        http_response_code(400);
        echo json_encode(['error' => 'توکن مهمان نیاز است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
    $stmt->execute([$guestToken]);
    $cart = $stmt->fetch();

    if (!$cart) {
        // اگر سبد برای مهمان وجود نداشت، بساز
        $stmt = $conn->prepare("INSERT INTO carts (guest_token, created_at) VALUES (?, NOW())");
        $stmt->execute([$guestToken]);
        $cartId = $conn->lastInsertId();
    } else {
        $cartId = $cart['id'];
    }
}

// حذف کالا از سبد
$stmt = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?");
$stmt->execute([$cartId, $productId]);

echo json_encode(['success' => true, 'message' => 'کالا با موفقیت از سبد حذف شد.'], JSON_UNESCAPED_UNICODE);
