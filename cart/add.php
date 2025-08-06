<?php
require_once __DIR__ . '../../db_connection.php';
$auth = require_once '../auth/auth_check.php';
$userId = $auth['user_id'] ?? null;

header('Content-Type: application/json');

try {
    if (!$conn) {
        throw new Exception("اتصال به پایگاه داده برقرار نیست.");
    }

    $data = json_decode(file_get_contents("php://input"), true);

    $productId = (int)($data['product_id'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 1);
    $guestToken = $data['guest_token'] ?? null;

    if (!$productId || $quantity < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'اطلاعات نامعتبر است.']);
        exit;
    }

    // بررسی وجود کالا
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'کالا یافت نشد.']);
        exit;
    }

    // بررسی موجودی
    if (isset($product['stock']) && $quantity > $product['stock']) {
        http_response_code(400);
        echo json_encode(['error' => 'موجودی کافی نیست.']);
        exit;
    }

    // پیدا یا ساختن سبد خرید
    if ($userId) {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $cart = $stmt->fetch();

        if (!$cart) {
            $stmt = $conn->prepare("INSERT INTO carts (user_id) VALUES (?)");
            $stmt->execute([$userId]);
            $cartId = $conn->lastInsertId();
        } else {
            $cartId = $cart['id'];
        }
    } else {
        // کاربر مهمان
        if (!$guestToken) {
            $guestToken = bin2hex(random_bytes(16));
            $stmt = $conn->prepare("INSERT INTO carts (guest_token) VALUES (?)");
            $stmt->execute([$guestToken]);
            $cartId = $conn->lastInsertId();
        } else {
            $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
            $stmt->execute([$guestToken]);
            $cart = $stmt->fetch();

            if (!$cart) {
                $stmt = $conn->prepare("INSERT INTO carts (guest_token) VALUES (?)");
                $stmt->execute([$guestToken]);
                $cartId = $conn->lastInsertId();
            } else {
                $cartId = $cart['id'];
            }
        }
    }

    // افزودن کالا به سبد
    $stmt = $conn->prepare("SELECT id FROM cart_items WHERE cart_id = ? AND product_id = ?");
    $stmt->execute([$cartId, $productId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE cart_items SET quantity = quantity + ? WHERE id = ?");
        $stmt->execute([$quantity, $existing['id']]);
    } else {
        $stmt = $conn->prepare("INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$cartId, $productId, $quantity]);
    }

    // پاسخ نهایی
    $response = [
        'success' => true,
        'message' => 'کالا با موفقیت به سبد اضافه شد.',
        'cart_id' => $cartId,
    ];

    if (!$userId) {
        $response['guest_token'] = $guestToken;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'خطای سرور: ' . $e->getMessage()
    ]);
}
