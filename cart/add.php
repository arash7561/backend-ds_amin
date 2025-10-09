<?php
require_once __DIR__ . '../../db_connection.php'; 
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

error_log("UserID: " . var_export($userId, true));
$conn = getPDO();
function getDiscountPercentByQuantity($quantity, $rules) {
    $applicable_discount = 0;
    foreach ($rules as $rule) {
        if ($quantity >= $rule['min_quantity'] && $rule['discount_percent'] > $applicable_discount) {
            $applicable_discount = $rule['discount_percent'];
        }
    }
    return $applicable_discount;
}

try {
    if (!$conn) {
        throw new Exception("اتصال به پایگاه داده برقرار نیست.");
    }

    $data = json_decode(file_get_contents("php://input"), true);

    $productId = (int)($data['product_id'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 1);

    // فقط اگر کاربر لاگین نیست توکن مهمان بگیر
    $guestToken = null;
    if (!$userId) {
        $guestToken = $data['guest_token'] ?? null;
    }

    $selectedDiameter = $data['selected_diameter'] ?? null;
    $selectedLength = $data['selected_length'] ?? null;

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

    // اعتبارسنجی قطر و طول انتخابی بر اساس dimensions
    $dimensions = json_decode($product['dimensions'], true);
    
    // پشتیبانی از هر دو فرمت: {"diameters": [], "lengths": []} و {"diameter": "1", "length": "1"}
    $validDiameters = [];
    $validLengths = [];
    
    if (isset($dimensions['diameters']) && is_array($dimensions['diameters'])) {
        $validDiameters = $dimensions['diameters'];
    } elseif (isset($dimensions['diameter'])) {
        $validDiameters = [$dimensions['diameter']];
    }
    
    if (isset($dimensions['lengths']) && is_array($dimensions['lengths'])) {
        $validLengths = $dimensions['lengths'];
    } elseif (isset($dimensions['length'])) {
        $validLengths = [$dimensions['length']];
    }

    // اگر dimensions خالی است یا قطر/طول تعریف نشده، اعتبارسنجی را رد کن
    if (empty($validDiameters) && empty($validLengths)) {
        // اگر dimensions خالی است، اجازه اضافه کردن بده
        error_log("Product {$productId} has empty dimensions, skipping validation");
    } else {
        // اعتبارسنجی فقط اگر dimensions تعریف شده باشد
        if (!empty($validDiameters) && !in_array($selectedDiameter, $validDiameters)) {
            http_response_code(400);
            echo json_encode(['error' => 'قطر انتخاب شده معتبر نیست.']);
            exit;
        }
        if (!empty($validLengths) && !in_array($selectedLength, $validLengths)) {
            http_response_code(400);
            echo json_encode(['error' => 'طول انتخاب شده معتبر نیست.']);
            exit;
        }
    }

    // بررسی موجودی
    if (isset($product['stock']) && $quantity > $product['stock']) {
        http_response_code(400);
        echo json_encode(['error' => 'موجودی کافی نیست.']);
        exit;
    }

    // پیدا یا ساختن سبد خرید
    if ($userId) {
        // کاربر وارد شده
        $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $cart = $stmt->fetch();

        if (!$cart) {
            $stmt = $conn->prepare("INSERT INTO carts (user_id) VALUES (?)");
            $stmt->execute([$userId]);
            $cartId = $conn->lastInsertId();
            error_log("New cart created for userId={$userId}, cartId={$cartId}");
        } else {
            $cartId = $cart['id'];
            error_log("Existing cart found for userId={$userId}, cartId={$cartId}");
        }
    } else {
        // کاربر مهمان
        if (!$guestToken) {
            do {
                $guestToken = bin2hex(random_bytes(16));
                $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
                $stmt->execute([$guestToken]);
                $existingToken = $stmt->fetch();
            } while ($existingToken);

            $stmt = $conn->prepare("INSERT INTO carts (guest_token) VALUES (?)");
            $stmt->execute([$guestToken]);
            $cartId = $conn->lastInsertId();
            error_log("New guest cart created with token={$guestToken}, cartId={$cartId}");
        } else {
            $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
            $stmt->execute([$guestToken]);
            $cart = $stmt->fetch();

            if (!$cart) {
                $stmt = $conn->prepare("INSERT INTO carts (guest_token) VALUES (?)");
                $stmt->execute([$guestToken]);
                $cartId = $conn->lastInsertId();
                error_log("Guest cart token not found, new cart created token={$guestToken}, cartId={$cartId}");
            } else {
                $cartId = $cart['id'];
                error_log("Existing guest cart found token={$guestToken}, cartId={$cartId}");
            }
        }
    }

    // بررسی وجود آیتم با محصول و قطر و طول مشابه در سبد
    $stmt = $conn->prepare("SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? AND selected_diameter = ? AND selected_length = ?");
    $stmt->execute([$cartId, $productId, $selectedDiameter, $selectedLength]);
    $existing = $stmt->fetch();

    if ($existing) {
        $newQuantity = $existing['quantity'] + $quantity;
        $stmt = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
        $stmt->execute([$newQuantity, $existing['id']]);
        error_log("Updated cart_item id={$existing['id']} quantity={$newQuantity}");
    } else {
        $newQuantity = $quantity;
        $stmt = $conn->prepare("INSERT INTO cart_items (cart_id, product_id, quantity, selected_diameter, selected_length) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$cartId, $productId, $quantity, $selectedDiameter, $selectedLength]);
        error_log("Inserted new cart_item for cartId={$cartId}, productId={$productId}");
    }

    // دریافت قوانین تخفیف
    $stmt = $conn->prepare("SELECT min_quantity, discount_percent FROM product_discount_rules WHERE product_id = ? ORDER BY min_quantity ASC");
    $stmt->execute([$productId]);
    $discountRules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $discountPercent = getDiscountPercentByQuantity($newQuantity, $discountRules);
    $priceAfterDiscount = $product['price'] - ($product['price'] * $discountPercent / 100);

    $response = [
        'success' => true,
        'message' => 'کالا با موفقیت به سبد اضافه شد.',
        'cart_id' => $cartId,
        'product' => [
            'id' => $productId,
            'title' => $product['title'],
            'quantity' => $newQuantity,
            'selected_diameter' => $selectedDiameter,
            'selected_length' => $selectedLength,
            'original_price' => $product['price'],
            'discount_percent' => $discountPercent,
            'price_after_discount' => round($priceAfterDiscount, 2),
        ]
    ];

    if (!$userId) {
        $response['guest_token'] = $guestToken;  // فقط مهمان باید توکن بگیره
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'خطای سرور: ' . $e->getMessage()
    ]);
}
