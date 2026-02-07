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
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../auth/jwt_utils.php';
require_once __DIR__ . '/bulk_pricing_helper.php';

// Fallback برای getallheaders() در WAMP
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        // همچنین Authorization را مستقیماً چک کن
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}

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

error_log("UserID: " . var_export($userId, true));
$conn = getPDO();

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
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

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

    // پیدا یا ساختن سبد خرید (قبل از چک موجودی برای محاسبه موجودی باقیمانده)
    $cartId = null;
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

    // بررسی موجودی (بعد از پیدا کردن cart برای محاسبه موجودی باقیمانده)
    // موجودی باید یک عدد مثبت باشد، در غیر این صورت موجودی کافی نیست
    $stock = isset($product['stock']) ? $product['stock'] : null;
    
    // لاگ برای دیباگ
    error_log("Product ID: {$productId}, Stock value: " . var_export($stock, true) . ", Stock type: " . gettype($stock) . ", Quantity: {$quantity}, is_null: " . (is_null($stock) ? 'true' : 'false'));
    
    // تبدیل به عدد برای مقایسه دقیق‌تر
    $stockInt = is_numeric($stock) ? (int)$stock : null;
    
    // اگر موجودی null، undefined، خالی، یا غیر عددی است، به عنوان 0 در نظر بگیر
    if ($stock === null || $stock === '' || $stock === false || !is_numeric($stock)) {
        $productStock = 0;
        error_log("Product ID: {$productId} - Stock is NULL/empty/false/non-numeric, treating as 0 (out of stock)");
    } else {
        $productStock = (int)$stock;
        error_log("Product ID: {$productId} - Product stock value: {$productStock}, Quantity requested: {$quantity}");
    }
    
    // بررسی موجودی: باید موجودی مثبت باشد
    if ($productStock <= 0) {
        // موجودی صفر یا منفی = موجودی کافی نیست
        error_log("Product ID: {$productId} - Stock is 0 or negative, rejecting");
        http_response_code(400);
        echo json_encode(['error' => 'موجودی کافی نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // محاسبه تعداد موجود در سبد خرید (برای همین محصول با همین قطر و طول)
    $quantityInCart = 0;
    if ($cartId) {
        $stmt = $conn->prepare("SELECT SUM(quantity) as total FROM cart_items WHERE cart_id = ? AND product_id = ? AND selected_diameter = ? AND selected_length = ?");
        $stmt->execute([$cartId, $productId, $selectedDiameter, $selectedLength]);
        $result = $stmt->fetch();
        $quantityInCart = $result ? (int)$result['total'] : 0;
    }
    
    // موجودی باقیمانده = موجودی کل - تعداد موجود در سبد
    $availableStock = $productStock - $quantityInCart;
    
    error_log("Product ID: {$productId} - Product stock: {$productStock}, Quantity in cart: {$quantityInCart}, Available stock: {$availableStock}, Requested quantity: {$quantity}");
    
    // بررسی موجودی باقیمانده
    if ($availableStock <= 0) {
        // موجودی باقیمانده صفر یا منفی = موجودی کافی نیست
        error_log("Product ID: {$productId} - Available stock is 0 or negative, rejecting");
        http_response_code(400);
        echo json_encode(['error' => 'موجودی کافی نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($quantity > $availableStock) {
        // موجودی باقیمانده کافی نیست
        error_log("Product ID: {$productId} - Insufficient available stock: requested {$quantity}, available {$availableStock}");
        http_response_code(400);
        echo json_encode(['error' => 'موجودی کافی نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    error_log("Product ID: {$productId} - Stock check passed: available {$availableStock}, requested {$quantity}");

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

    // اعمال قیمت‌گذاری حجمی (محصول و دسته‌بندی)
    $originalPrice = (float)$product['price'];
    $bulkPricingInfo = getBulkPricingInfo($conn, $productId, $newQuantity, $originalPrice, $product['cat_id'] ?? null);
    
    // بررسی تخفیف معمولی محصول
    $discountPriceValue = !empty($product['discount_price']) && (float)$product['discount_price'] > 0 && (float)$product['discount_price'] < $originalPrice 
        ? (float)$product['discount_price'] 
        : null;
    
    // تعیین قیمت نهایی
    if ($bulkPricingInfo['discount_applied']) {
        $finalPrice = $bulkPricingInfo['final_price'];
        $discountPercent = $bulkPricingInfo['discount_percent'];
    } else {
        $finalPrice = $discountPriceValue ? $discountPriceValue : $originalPrice;
        $discountPercent = $discountPriceValue ? ((($originalPrice - $discountPriceValue) / $originalPrice) * 100) : 0;
    }

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
            'original_price' => $originalPrice,
            'discount_percent' => round($discountPercent, 2),
            'price_after_discount' => round($finalPrice, 2),
            'bulk_pricing_applied' => $bulkPricingInfo['discount_applied'],
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
