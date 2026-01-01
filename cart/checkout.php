<?php
// CORS headers - Allow from localhost and production domain
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
    'https://aminindpharm.ir',
    'http://aminindpharm.ir'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (in_array($origin, $allowed_origins) || 
    (strpos($origin, 'http://localhost') !== false || 
     strpos($origin, 'http://127.0.0.1') !== false ||
     strpos($origin, 'https://aminindpharm.ir') !== false ||
     strpos($origin, 'http://aminindpharm.ir') !== false)) {
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
$conn = getPDO();
require_once __DIR__ . '/../auth/jwt_utils.php';
require_once __DIR__ . '/bulk_pricing_helper.php';

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

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای اتصال به پایگاه داده']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$guestToken = $data['guest_token'] ?? null;
$address = $data['address'] ?? null;

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

// بررسی وجود فیلدهای آدرس در جدول orders
$stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'address'");
$stmt->execute();
$hasAddressField = $stmt->fetch();

if ($hasAddressField && $address) {
    // اگر فیلدهای آدرس وجود دارند، آنها را ذخیره کن
    $addressText = $address['address'] ?? '';
    $province = $address['province'] ?? '';
    $city = $address['city'] ?? '';
    $postalCode = $address['postal_code'] ?? '';
    $email = $address['email'] ?? null;
    
    if ($userId) {
        $stmt = $conn->prepare("INSERT INTO orders (user_id, address, province, city, postal_code, email, created_at, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending')");
        $stmt->execute([$userId, $addressText, $province, $city, $postalCode, $email]);
    } else {
        $stmt = $conn->prepare("INSERT INTO orders (guest_token, address, province, city, postal_code, email, created_at, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending')");
        $stmt->execute([$guestToken, $addressText, $province, $city, $postalCode, $email]);
    }
} else {
    // اگر فیلدهای آدرس وجود ندارند، فقط user_id یا guest_token را ذخیره کن
    if ($userId) {
        $stmt = $conn->prepare("INSERT INTO orders (user_id, created_at, status) VALUES (?, NOW(), 'pending')");
        $stmt->execute([$userId]);
    } else {
        $stmt = $conn->prepare("INSERT INTO orders (guest_token, created_at, status) VALUES (?, NOW(), 'pending')");
        $stmt->execute([$guestToken]);
    }
}

$orderId = $conn->lastInsertId();

foreach ($items as $item) {
    // دریافت اطلاعات محصول برای محاسبه قیمت
    $stmt = $conn->prepare("SELECT price, discount_price FROM products WHERE id = ?");
    $stmt->execute([$item['product_id']]);
    $product = $stmt->fetch();
    
    if ($product) {
        $originalPrice = (float)$product['price'];
        $quantity = (int)$item['quantity'];
        
        // بررسی تخفیف معمولی محصول
        $discountPriceValue = !empty($product['discount_price']) && (float)$product['discount_price'] > 0 && (float)$product['discount_price'] < $originalPrice 
            ? (float)$product['discount_price'] 
            : null;
        
        // اعمال قیمت‌گذاری حجمی
        $bulkPricingInfo = getBulkPricingInfo($conn, $item['product_id'], $quantity, $originalPrice);
        
        // تعیین قیمت نهایی
        if ($bulkPricingInfo['discount_applied']) {
            $finalPrice = $bulkPricingInfo['final_price'];
        } else {
            $finalPrice = $discountPriceValue ? $discountPriceValue : $originalPrice;
        }
        
        // ذخیره در order_items با قیمت نهایی
        // بررسی وجود فیلد unit_price در جدول order_items
        $stmt = $conn->prepare("SHOW COLUMNS FROM order_items LIKE 'unit_price'");
        $stmt->execute();
        $hasUnitPrice = $stmt->fetch();
        
        if ($hasUnitPrice) {
            $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$orderId, $item['product_id'], $quantity, $finalPrice]);
        } else {
            // اگر فیلد unit_price وجود ندارد، فقط product_id و quantity را ذخیره کن
            $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmt->execute([$orderId, $item['product_id'], $quantity]);
        }
    } else {
        // اگر محصول پیدا نشد، بدون قیمت ذخیره کن
        $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$orderId, $item['product_id'], $item['quantity']]);
    }
}

// نکته: سبد خرید را در اینجا پاک نمی‌کنیم
// سبد خرید فقط بعد از پرداخت موفق در verify-payment.php پاک می‌شود
// این کار باعث می‌شود که اگر پرداخت ناموفق باشد، کاربر بتواند دوباره تلاش کند

echo json_encode([
    'success' => true,
    'message' => 'سفارش با موفقیت ثبت شد',
    'order_id' => $orderId
], JSON_UNESCAPED_UNICODE);
