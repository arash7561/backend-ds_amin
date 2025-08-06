<?php
require_once '../db_connection.php';
header("Content-Type: application/json; charset=UTF-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

$userId     = $data->user_id ?? null;
$guestToken = $data->guest_token ?? null;

if (empty($userId) && empty($guestToken)) {
    echo json_encode(['status' => false, 'message' => 'شناسه کاربر یا توکن مهمان الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // پیدا کردن سبد خرید کاربر یا مهمان
    if ($userId) {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
        $stmt->execute([$userId]);
    } else {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
        $stmt->execute([$guestToken]);
    }

    $cart = $stmt->fetch();
    if (!$cart) {
        echo json_encode(['status' => true, 'cart_items' => [], 'total_price' => 0]);
        exit;
    }

    $cartId = $cart['id'];

    // گرفتن آیتم‌های سبد همراه با اطلاعات محصول
    $stmt = $conn->prepare("
        SELECT 
            ci.id AS cart_item_id,
            ci.quantity,
            p.id AS product_id,
            p.title,
            p.price,
            p.image
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.id
        WHERE ci.cart_id = ?
    ");
    $stmt->execute([$cartId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // محاسبه مجموع قیمت
    $totalPrice = 0;
    foreach ($items as &$item) {
        $itemTotal = $item['price'] * $item['quantity'];
        $item['total'] = $itemTotal;
        $totalPrice += $itemTotal;
    }

    echo json_encode([
        'status' => true,
        'cart_items' => $items,
        'total_price' => $totalPrice
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطا در سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
