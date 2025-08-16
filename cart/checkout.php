<?php
require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
$auth = require_once '../auth/auth_check.php';

header('Content-Type: application/json');

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای اتصال به پایگاه داده']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$guestToken = $data['guest_token'] ?? null;

$userId = $auth['user_id'] ?? null;

if (!$userId && !$guestToken) {
    http_response_code(401);
    echo json_encode(['error' => 'برای ثبت سفارش باید وارد شوید یا guest_token داشته باشید.']);
    exit;
}

if ($userId) {
    $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cart = $stmt->fetch();
}

if (empty($cart) && $guestToken) {
    $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
    $stmt->execute([$guestToken]);
    $guestCart = $stmt->fetch();

    if ($guestCart) {
        if ($userId) {
            $stmt = $conn->prepare("UPDATE carts SET user_id = ?, guest_token = NULL WHERE id = ?");
            $stmt->execute([$userId, $guestCart['id']]);
        }
        $cartId = $guestCart['id'];
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'سبد خرید یافت نشد']);
        exit;
    }
} elseif (!empty($cart)) {
    $cartId = $cart['id'];
} else {
    http_response_code(404);
    echo json_encode(['error' => 'سبد خرید یافت نشد']);
    exit;
}

if (empty($cartId)) {
    http_response_code(404);
    echo json_encode(['error' => 'سبد خرید یافت نشد']);
    exit;
}

$stmt = $conn->prepare("SELECT product_id, quantity FROM cart_items WHERE cart_id = ?");
$stmt->execute([$cartId]);
$items = $stmt->fetchAll();

if (empty($items)) {
    http_response_code(400);
    echo json_encode(['error' => 'سبد خرید خالی است']);
    exit;
}

if ($userId) {
    $stmt = $conn->prepare("INSERT INTO orders (user_id, created_at, status) VALUES (?, NOW(), 'pending')");
    $stmt->execute([$userId]);
} else {
    $stmt = $conn->prepare("INSERT INTO orders (guest_token, created_at, status) VALUES (?, NOW(), 'pending')");
    $stmt->execute([$guestToken]);
}

$orderId = $conn->lastInsertId();

foreach ($items as $item) {
    $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
    $stmt->execute([$orderId, $item['product_id'], $item['quantity']]);
}

$stmt = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
$stmt->execute([$cartId]);

echo json_encode([
    'success' => true,
    'message' => 'سفارش با موفقیت ثبت شد',
    'order_id' => $orderId
], JSON_UNESCAPED_UNICODE);
