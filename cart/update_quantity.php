<?php
require_once '../db_connection.php';
$auth = require_once '../auth/auth_check.php';
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

$conn = getPDO();

try {
    if (!$conn) {
        throw new Exception("اتصال به پایگاه داده برقرار نیست.");
    }

    $data = json_decode(file_get_contents("php://input"), true);

    $cartItemId = (int)($data['cart_item_id'] ?? 0);
    $newQuantity = (int)($data['quantity'] ?? 1);
    $guestToken = $data['guest_token'] ?? null;

    if (!$cartItemId || $newQuantity < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'اطلاعات نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بررسی وجود آیتم در سبد
    $stmt = $conn->prepare("
        SELECT ci.*, c.user_id, c.guest_token 
        FROM cart_items ci 
        JOIN carts c ON ci.cart_id = c.id 
        WHERE ci.id = ?
    ");
    $stmt->execute([$cartItemId]);
    $cartItem = $stmt->fetch();

    if (!$cartItem) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'آیتم سبد خرید یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بررسی دسترسی کاربر به این آیتم
    if ($userId) {
        // کاربر وارد شده - باید مالک سبد باشد
        if ($cartItem['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // کاربر مهمان - باید توکن مطابقت داشته باشد
        if ($cartItem['guest_token'] != $guestToken) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'دسترسی غیرمجاز.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // بررسی موجودی محصول
    $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
    $stmt->execute([$cartItem['product_id']]);
    $product = $stmt->fetch();

    if ($product && isset($product['stock']) && $newQuantity > $product['stock']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'موجودی کافی نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بروزرسانی تعداد
    $stmt = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
    $result = $stmt->execute([$newQuantity, $cartItemId]);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'تعداد با موفقیت بروزرسانی شد.',
            'cart_item_id' => $cartItemId,
            'new_quantity' => $newQuantity
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception("خطا در بروزرسانی تعداد.");
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'خطای سرور: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
