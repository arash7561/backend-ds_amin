<?php
require_once __DIR__ . '../../db_connection.php';
$auth = require_once '../auth/auth_check.php'; // تغییر مهم
$userId = $auth['user_id'] ?? null;
header('Content-Type: application/json');

// اتصال DB
$conn = $conn ?? null;
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای اتصال به پایگاه داده']);
    exit;
}

// بررسی اینکه آیا کاربر لاگین کرده
$auth = $auth ?? [];
$userId = $auth['user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'برای ثبت سفارش، ابتدا باید وارد شوید.']);
    exit;
}

// خواندن اطلاعات POST
$data = json_decode(file_get_contents("php://input"), true);
$guestToken = $data['guest_token'] ?? null;

// بررسی سبد خرید کاربر
$stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
$stmt->execute([$userId]);
$cart = $stmt->fetch();

if (!$cart && $guestToken) {
    // اگر کاربر لاگین کرده ولی سبدش از قبل خالیه و سبد مهمان وجود داره، منتقل کن
    $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
    $stmt->execute([$guestToken]);
    $guestCart = $stmt->fetch();

    if ($guestCart) {
        // تغییر مالکیت سبد به کاربر
        $stmt = $conn->prepare("UPDATE carts SET user_id = ?, guest_token = NULL WHERE id = ?");
        $stmt->execute([$userId, $guestCart['id']]);
        $cartId = $guestCart['id'];
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'سبد خرید یافت نشد']);
        exit;
    }
} elseif ($cart) {
    $cartId = $cart['id'];
} else {
    http_response_code(404);
    echo json_encode(['error' => 'سبد خرید یافت نشد']);
    exit;
}

// بررسی آیتم‌های سبد
$stmt = $conn->prepare("SELECT * FROM cart_items WHERE cart_id = ?");
$stmt->execute([$cartId]);
$items = $stmt->fetchAll();

if (!$items) {
    http_response_code(400);
    echo json_encode(['error' => 'سبد خرید خالی است']);
    exit;
}

// ✅ ثبت سفارش
$stmt = $conn->prepare("INSERT INTO orders (user_id, created_at) VALUES (?, NOW())");
$stmt->execute([$userId]);
$orderId = $conn->lastInsertId();

// ثبت جزئیات سفارش
foreach ($items as $item) {
    $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
    $stmt->execute([$orderId, $item['product_id'], $item['quantity']]);
}

// حذف آیتم‌های سبد
$stmt = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
$stmt->execute([$cartId]);

echo json_encode([
    'success' => true,
    'message' => 'سفارش با موفقیت ثبت شد',
    'order_id' => $orderId
]);
