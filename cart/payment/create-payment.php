<?php
/**
 * ایجاد تراکنش در زرین‌پال و برگرداندن لینک پرداخت
 */

// CORS headers
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
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $origin);
}
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../auth/jwt_utils.php';

// تنظیمات زرین‌پال
$zarinpalConfig = require __DIR__ . '/../../config/zarinpal.php';

$conn = getPDO();

// ساده‌ترین هلسپر برای خروجی JSON
function sendJson($status, $type, $message, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge([
        'status' => $status,
        'type' => $type,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// 1️⃣ بررسی کاربر با JWT
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

// 2️⃣ دریافت داده‌ها
$data = json_decode(file_get_contents("php://input"), true);
$orderId = (int)($data['order_id'] ?? 0);

if (!$orderId) {
    sendJson(400, 'error', 'شناسه سفارش معتبر نیست');
}

// 3️⃣ بررسی سفارش
if ($userId) {
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$orderId, $userId]);
} else {
    $guestToken = $data['guest_token'] ?? null;
    if (!$guestToken) {
        sendJson(401, 'error', 'برای پرداخت باید وارد شوید یا guest_token داشته باشید');
    }
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND guest_token = ?");
    $stmt->execute([$orderId, $guestToken]);
}

$order = $stmt->fetch();
if (!$order) {
    sendJson(404, 'error', 'سفارش یافت نشد');
}

// 4️⃣ محاسبه مبلغ نهایی سفارش
$stmt = $conn->prepare("SELECT oi.quantity, p.price, p.discount_price 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

if (!$items) {
    sendJson(400, 'error', 'هیچ آیتمی در سفارش یافت نشد');
}

$totalAmount = 0;
foreach ($items as $item) {
    $price = !empty($item['discount_price']) && $item['discount_price'] > 0 
             ? $item['discount_price'] 
             : $item['price'];
    $totalAmount += $price * $item['quantity'];
}

// اگر واحد مبلغ شما با تنظیمات زرین‌پال (IRR / IRT) فرق دارد، اینجا تبدیل کنید
// فعلاً فرض: مبالغ شما مطابق تنظیم 'currency' در config است.

// 5️⃣ ارسال درخواست ایجاد تراکنش به زرین‌پال
$merchantId   = $zarinpalConfig['merchant_id'];
$callbackUrl  = $zarinpalConfig['callback_url'];
$description  = $zarinpalConfig['description'] ?? ('پرداخت سفارش #' . $orderId);
$currency     = $zarinpalConfig['currency'] ?? 'IRR';
$mode         = $zarinpalConfig['mode'] ?? 'sandbox';

// آدرس API بر اساس حالت
$requestUrl = $mode === 'production'
    ? 'https://api.zarinpal.com/pg/v4/payment/request.json'
    : 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';

$requestData = [
    'merchant_id' => $merchantId,
    'amount'      => (int)$totalAmount,
    'callback_url'=> $callbackUrl,
    'description' => $description,
    'currency'    => $currency,
    'metadata'    => [
        // در صورت نیاز می‌توانی ایمیل/موبایل کاربر را اینجا اضافه کنی
    ]
];

$ch = curl_init($requestUrl);
curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
]);

$result = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    sendJson(500, 'error', 'خطا در اتصال به زرین‌پال: ' . $curlErr);
}

$resultData = json_decode($result, true);

if ($httpStatus !== 200 || !isset($resultData['data']['code'])) {
    sendJson(500, 'error', 'پاسخ نامعتبر از زرین‌پال', [
        'response' => $resultData
    ]);
}

$code = (int)$resultData['data']['code'];
if (!in_array($code, [100, 101])) {
    // تراکنش توسط زرین‌پال پذیرش نشده
    sendJson(400, 'error', 'خطا در ایجاد تراکنش در زرین‌پال', [
        'code'    => $code,
        'message' => $resultData['data']['message'] ?? ''
    ]);
}

$authority = $resultData['data']['authority'] ?? null;
if (!$authority) {
    sendJson(500, 'error', 'authority معتبر از زرین‌پال دریافت نشد');
}

// 6️⃣ ثبت رکورد پرداخت در دیتابیس
$stmt = $conn->prepare("INSERT INTO payments (order_id, user_id, amount, authority, status, created_at) 
                        VALUES (?, ?, ?, ?, 'pending', NOW())");
$stmt->execute([$orderId, $userId, $totalAmount, $authority]);

// 7️⃣ ساخت لینک درگاه پرداخت بر اساس mode
if ($mode === 'production') {
    // آدرس جدید/قدیم تولید می‌تواند متفاوت باشد؛ این یکی از آدرس‌های رایج است
    $paymentUrl = "https://www.zarinpal.com/pg/StartPay/" . $authority;
} else {
    $paymentUrl = "https://sandbox.zarinpal.com/pg/v4/payment/startpay/" . $authority;
}

// 8️⃣ پاسخ نهایی به فرانت‌اند
sendJson(200, 'success', 'در حال انتقال به درگاه پرداخت', [
    'payment_url' => $paymentUrl,
    'authority'   => $authority,
    'amount'      => $totalAmount
]);
