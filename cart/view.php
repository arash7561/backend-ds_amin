<?php
// CORS headers - Allow from any localhost origin for development
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (in_array($origin, $allowed_origins) || (strpos($origin, 'http://localhost') !== false || strpos($origin, 'http://127.0.0.1') !== false)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../auth/jwt_utils.php';

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

$conn = getPDO();

$json = file_get_contents('php://input');
$data = json_decode($json);

$guestToken = $data->guest_token ?? null;

error_log("View cart - userId from auth: " . var_export($userId, true));
error_log("View cart - guestToken from body: " . var_export($guestToken, true));

// اگر کاربر لاگین نیست و توکن مهمان هم نداره
if (!$userId && !$guestToken) {
    error_log("View cart - No userId or guestToken found");
    echo json_encode(['status' => false, 'message' => 'شناسه کاربر یا توکن مهمان الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // پیدا کردن سبد خرید کاربر یا مهمان
    if ($userId) {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
        $stmt->execute([$userId]);
        error_log("View cart for userId: " . $userId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
        $stmt->execute([$guestToken]);
        error_log("View cart for guestToken: " . $guestToken);
    }

    $cart = $stmt->fetch();
    if (!$cart) {
        error_log("No cart found for userId: " . $userId . " or guestToken: " . $guestToken);
        echo json_encode(['status' => true, 'cart_items' => [], 'total_price' => 0]);
        exit;
    }

    $cartId = $cart['id'];
    error_log("Found cart with ID: " . $cartId);

    // گرفتن آیتم‌های سبد همراه با اطلاعات محصول و قطر و طول انتخاب شده
    $stmt = $conn->prepare("
        SELECT 
            ci.id AS cart_item_id,
            ci.quantity,
            ci.selected_diameter,
            ci.selected_length,
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
    
    error_log("Found " . count($items) . " items in cart");

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
