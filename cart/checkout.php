<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (
    in_array($origin, $allowed_origins) ||
    strpos($origin, 'localhost') !== false ||
    strpos($origin, '127.0.0.1') !== false ||
    strpos($origin, 'aminindpharm.ir') !== false
) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../auth/jwt_utils.php';
    require_once __DIR__ . '/bulk_pricing_helper.php';

    $conn = getPDO();
    if (!$conn) {
        throw new Exception('خطا در اتصال به پایگاه داده');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'خطا در بارگذاری فایل‌های مورد نیاز: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    error_log('Checkout error: ' . $e->getMessage());
    exit;
}

// ================= JWT =================
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

$data = json_decode(file_get_contents("php://input"), true);
$guestToken = $data['guest_token'] ?? null;

$address = $data['address'] ?? null;

$shippingId = isset($data['shipping_id']) ? (int)$data['shipping_id'] : null;


// ================= VALIDATION =================
if (!$userId && !$guestToken) {
    http_response_code(401);
    echo json_encode(['error' => 'برای ثبت سفارش باید وارد شوید یا guest_token داشته باشید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$shippingId) {
    http_response_code(400);
    echo json_encode(['error' => 'نحوه ارسال انتخاب نشده است'], JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی صحت روش ارسال
$stmt = $conn->prepare("SELECT id FROM shippings WHERE id = ?");
$stmt->execute([$shippingId]);
if (!$stmt->fetch()) {
    http_response_code(400);
    echo json_encode(['error' => 'نحوه ارسال نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= CART =================
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
        echo json_encode(['error' => 'سبد خرید یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} elseif (!empty($cart)) {
    $cartId = $cart['id'];
} else {
    http_response_code(404);
    echo json_encode(['error' => 'سبد خرید یافت نشد'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare("SELECT product_id, quantity FROM cart_items WHERE cart_id = ?");
$stmt->execute([$cartId]);
$items = $stmt->fetchAll();

if (empty($items)) {
    http_response_code(400);
    echo json_encode(['error' => 'سبد خرید خالی است'], JSON_UNESCAPED_UNICODE);
    exit;
}


// ================= CREATE ORDER =================
try {
    // بررسی وجود فیلدهای آدرس در جدول orders
    $stmt = $conn->prepare("SHOW COLUMNS FROM orders LIKE 'address'");
    $stmt->execute();
    $hasAddressField = $stmt->fetch();

    if ($hasAddressField && $address) {
        // اگر فیلدهای آدرس وجود دارند، آنها را همراه با shipping_id ذخیره کن
        $addressText = $address['address'] ?? '';
        $province = $address['province'] ?? '';
        $city = $address['city'] ?? '';
        $postalCode = $address['postal_code'] ?? '';
        $email = $address['email'] ?? null;
        
        if ($userId) {
            $stmt = $conn->prepare("INSERT INTO orders (user_id, shipping_id, address, province, city, postal_code, email, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')");
            $stmt->execute([$userId, $shippingId, $addressText, $province, $city, $postalCode, $email]);
        } else {
            $stmt = $conn->prepare("INSERT INTO orders (guest_token, shipping_id, address, province, city, postal_code, email, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')");
            $stmt->execute([$guestToken, $shippingId, $addressText, $province, $city, $postalCode, $email]);
        }
    } else {
        // اگر فیلدهای آدرس وجود ندارند، فقط shipping_id را ذخیره کن
        if ($userId) {
            $stmt = $conn->prepare("INSERT INTO orders (user_id, shipping_id, created_at, status) VALUES (?, ?, NOW(), 'pending')");
            $stmt->execute([$userId, $shippingId]);
        } else {
            $stmt = $conn->prepare("INSERT INTO orders (guest_token, shipping_id, created_at, status) VALUES (?, ?, NOW(), 'pending')");
            $stmt->execute([$guestToken, $shippingId]);
        }
    }

    $orderId = $conn->lastInsertId();
    
    if (!$orderId) {
        throw new Exception('خطا در ایجاد سفارش - order_id دریافت نشد');
    }
} catch (PDOException $e) {
    http_response_code(500);
    $errorMsg = 'خطا در ایجاد سفارش: ' . $e->getMessage();
    error_log('Checkout PDO error: ' . $e->getMessage());
    error_log('Checkout PDO error code: ' . $e->getCode());
    error_log('Checkout PDO error info: ' . json_encode($e->errorInfo ?? []));
    
    // بررسی خطای خاص: اگر فیلد shipping_id وجود ندارد
    if (strpos($e->getMessage(), 'shipping_id') !== false || strpos($e->getMessage(), "Unknown column 'shipping_id'") !== false) {
        $errorMsg = 'خطا: فیلد shipping_id در جدول orders وجود ندارد. لطفاً جدول را به‌روزرسانی کنید.';
    }
    
    echo json_encode(['error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    error_log('Checkout error: ' . $e->getMessage());
    echo json_encode(['error' => 'خطا در ایجاد سفارش: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================= ORDER ITEMS =================
try {
    foreach ($items as $item) {
        $quantity = (int)$item['quantity'];
        
        // فقط order_id, product_id و quantity را ذخیره می‌کنیم
        // قیمت از جدول products در زمان نیاز خوانده می‌شود
        $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$orderId, $item['product_id'], $quantity]);
    }

    // نکته: سبد خرید را در اینجا پاک نمی‌کنیم
    // سبد خرید فقط بعد از پرداخت موفق در verify-payment.php پاک می‌شود
    // این کار باعث می‌شود که اگر پرداخت ناموفق باشد، کاربر بتواند دوباره تلاش کند

    echo json_encode([
        'success' => true,
        'message' => 'سفارش با موفقیت ثبت شد',
        'order_id' => $orderId
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Checkout order_items error: ' . $e->getMessage());
    error_log('Checkout order_items error code: ' . $e->getCode());
    echo json_encode([
        'error' => 'خطا در ثبت آیتم‌های سفارش: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    error_log('Checkout order_items error: ' . $e->getMessage());
    echo json_encode([
        'error' => 'خطا در ثبت آیتم‌های سفارش: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
