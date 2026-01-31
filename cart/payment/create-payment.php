<?php
/**
 * ایجاد تراکنش در زرین‌پال و برگرداندن لینک پرداخت
 */

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

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
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../../db_connection.php';
    require_once __DIR__ . '/../../auth/jwt_utils.php';
    
    // تنظیمات زرین‌پال
    $zarinpalConfig = require __DIR__ . '/../../config/zarinpal.php';
    
    $conn = getPDO();
    if (!$conn) {
        sendJson(500, 'error', 'خطا در اتصال به پایگاه داده');
    }
} catch (Exception $e) {
    sendJson(500, 'error', 'خطا در بارگذاری فایل‌های مورد نیاز: ' . $e->getMessage());
}

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
        error_log("User authenticated via JWT - User ID: $userId");
    } else {
        error_log("JWT token invalid or expired: " . ($authResult['error'] ?? 'Unknown error'));
    }
} else {
    error_log("No Authorization header found - user is guest");
}

// 2️⃣ دریافت داده‌ها
$data = json_decode(file_get_contents("php://input"), true);
$orderId = (int)($data['order_id'] ?? 0);

if (!$orderId) {
    sendJson(400, 'error', 'شناسه سفارش معتبر نیست');
}

// 3️⃣ بررسی سفارش
if ($userId) {
    // کاربر لاگین است - فقط با user_id بررسی می‌کنیم
    error_log("Checking order for logged-in user - Order ID: $orderId, User ID: $userId");
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$orderId, $userId]);
} else {
    // کاربر مهمان است - باید guest_token داشته باشد
    $guestToken = $data['guest_token'] ?? null;
    error_log("Checking order for guest - Order ID: $orderId, Guest Token: " . ($guestToken ?? 'NULL'));
    
    if (!$guestToken) {
        error_log("Guest token missing - cannot proceed with payment");
        sendJson(401, 'error', 'برای پرداخت باید وارد شوید یا guest_token داشته باشید');
    }
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND guest_token = ?");
    $stmt->execute([$orderId, $guestToken]);
}

$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    // لاگ کردن جزئیات برای دیباگ
    error_log("Order not found - Order ID: $orderId, User ID: " . ($userId ?? 'NULL') . ", Guest Token: " . ($data['guest_token'] ?? 'NULL'));
    
    // بررسی اینکه آیا سفارش وجود دارد اما با user_id یا guest_token مطابقت ندارد
    $stmt = $conn->prepare("SELECT id, user_id, guest_token, status, created_at FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $existingOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingOrder) {
        error_log("Order exists but doesn't match - Order: " . json_encode($existingOrder, JSON_UNESCAPED_UNICODE));
        $errorMsg = 'شما دسترسی به این سفارش ندارید. ';
        if ($userId && $existingOrder['user_id'] && $existingOrder['user_id'] != $userId) {
            $errorMsg .= 'این سفارش متعلق به کاربر دیگری است.';
        } elseif (!$userId && $existingOrder['guest_token'] && $existingOrder['guest_token'] != ($data['guest_token'] ?? null)) {
            $errorMsg .= 'guest_token با سفارش مطابقت ندارد.';
        } else {
            $errorMsg .= 'لطفاً دوباره تلاش کنید.';
        }
        sendJson(403, 'error', $errorMsg);
    } else {
        error_log("Order does not exist in database - Order ID: $orderId");
        sendJson(404, 'error', 'سفارش یافت نشد. لطفاً ابتدا سفارش را ایجاد کنید.');
    }
}

error_log("Order found successfully - Order ID: $orderId, Status: " . ($order['status'] ?? 'N/A'));

// 4️⃣ محاسبه مبلغ نهایی سفارش
$stmt = $conn->prepare("SELECT oi.quantity, p.price, p.discount_price 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// تبدیل واحد پول: مبالغ در دیتابیس به تومان هستند
// اگر currency = IRR باشد، باید به ریال تبدیل کنیم (×10)
// اگر currency = IRT باشد، همان تومان را ارسال می‌کنیم (زرین‌پال خودش ×10 می‌کند)

// 5️⃣ ارسال درخواست ایجاد تراکنش به زرین‌پال
$merchantId   = $zarinpalConfig['merchant_id'];
$callbackUrl  = $zarinpalConfig['callback_url'];
$description  = $zarinpalConfig['description'] ?? ('پرداخت سفارش #' . $orderId);
$currency     = $zarinpalConfig['currency'] ?? 'IRT';
$mode         = $zarinpalConfig['mode'] ?? 'sandbox';

// تبدیل مبلغ: مبالغ در دیتابیس به تومان هستند
// اگر currency = IRR (ریال) باشد، باید ×10 کنیم
// اگر currency = IRT (تومان) باشد، همان را ارسال می‌کنیم (زرین‌پال خودش ×10 می‌کند)
$finalAmount = (int)$totalAmount;

if ($currency === 'IRR') {
    // تبدیل تومان به ریال
    $finalAmount = (int)($totalAmount * 10);
    $minAmount = 10000; // حداقل 10000 ریال
    $currencyName = 'ریال';
} else {
    // IRT - زرین‌پال خودش ×10 می‌کند
    $minAmount = 1000; // حداقل 1000 تومان
    $currencyName = 'تومان';
}

// بررسی حداقل مبلغ: زرین‌پال حداقل مبلغ مشخصی می‌خواهد
if ($finalAmount < $minAmount) {
    sendJson(400, 'error', "مبلغ سفارش باید حداقل {$minAmount} {$currencyName} باشد. مبلغ فعلی: " . number_format($finalAmount) . " {$currencyName}", [
        'amount' => $finalAmount,
        'min_amount' => $minAmount,
        'currency' => $currency,
        'total_amount' => $totalAmount
    ]);
}

// آدرس API بر اساس حالت
$requestUrl = $mode === 'production'
    ? 'https://api.zarinpal.com/pg/v4/payment/request.json'
    : 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';

$requestData = [
    'merchant_id' => $merchantId,
    'amount'      => $finalAmount,
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

// تنظیمات SSL - برای حل مشکل گواهینامه در localhost
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// اگر در localhost هستیم، SSL verification را غیرفعال می‌کنیم
$isLocalhost = (
    isset($_SERVER['HTTP_HOST']) && (
        strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false
    )
);

if ($isLocalhost) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$result = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    sendJson(500, 'error', 'خطا در اتصال به زرین‌پال: ' . $curlErr);
}

$resultData = json_decode($result, true);

// لاگ کردن پاسخ کامل از زرین‌پال برای دیباگ
error_log("=== ZARINPAL API RESPONSE ===");
error_log("HTTP Status: $httpStatus");
error_log("Response: " . json_encode($resultData, JSON_UNESCAPED_UNICODE));
error_log("=================================");

// بررسی خطاهای GraphQL (API جدید)
if (isset($resultData['errors']) && !empty($resultData['errors'])) {
    $error = $resultData['errors'];
    $errorCode = $error['code'] ?? null;
    $errorMessage = $error['message'] ?? 'خطا در ارتباط با زرین‌پال';
    
    // کد -12: Too many attempts
    if ($errorCode == -12) {
        sendJson(429, 'error', 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً چند دقیقه صبر کنید و دوباره تلاش کنید.', [
            'code' => $errorCode,
            'message' => $errorMessage,
            'retry_after' => 300 // 5 دقیقه
        ]);
    }
    
    sendJson(400, 'error', $errorMessage, [
        'code' => $errorCode,
        'errors' => $error
    ]);
}

// بررسی خطاهای REST API (API قدیمی)
if ($httpStatus !== 200 || !isset($resultData['data']['code'])) {
    sendJson(500, 'error', 'پاسخ نامعتبر از زرین‌پال', [
        'response' => $resultData
    ]);
}

$code = (int)$resultData['data']['code'];
if (!in_array($code, [100, 101])) {
    // تراکنش توسط زرین‌پال پذیرش نشده
    $errorMessage = $resultData['data']['message'] ?? 'خطا در ایجاد تراکنش در زرین‌پال';
    
    // تبدیل پیام‌های خطا به فارسی
    $persianMessages = [
        -9 => 'مبلغ باید حداقل 1000 تومان باشد',
        -12 => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً چند دقیقه صبر کنید و دوباره تلاش کنید.',
    ];
    
    if (isset($persianMessages[$code])) {
        $errorMessage = $persianMessages[$code];
    }
    
    sendJson(400, 'error', $errorMessage, [
        'code'    => $code,
        'message' => $errorMessage,
        'retry_after' => ($code == -12) ? 300 : null
    ]);
}

$authority = $resultData['data']['authority'] ?? null;

// لاگ کردن authority دریافت شده
error_log("=== AUTHORITY RECEIVED FROM ZARINPAL ===");
error_log("Authority: " . ($authority ?? 'NULL'));
error_log("Authority type: " . gettype($authority));
if ($authority) {
    error_log("Authority length: " . strlen($authority));
    error_log("Authority trimmed: " . trim($authority));
}
error_log("Full resultData['data']: " . json_encode($resultData['data'] ?? [], JSON_UNESCAPED_UNICODE));
error_log("=========================================");

if (!$authority) {
    error_log("ERROR: Authority is null or empty!");
    error_log("Full response data: " . json_encode($resultData, JSON_UNESCAPED_UNICODE));
    sendJson(500, 'error', 'authority معتبر از زرین‌پال دریافت نشد', [
        'response' => $resultData,
        'http_status' => $httpStatus
    ]);
}

// نرمال‌سازی authority: حذف تمام whitespace ها و کاراکترهای غیرقابل مشاهده
// زرین‌پال authority را به صورت alphanumeric می‌دهد (حروف و اعداد)
$originalAuthority = $authority;

error_log("=== AUTHORITY NORMALIZATION ===");
error_log("Original authority (raw): " . var_export($originalAuthority, true));
error_log("Original authority length: " . strlen($originalAuthority));
error_log("Original authority hex: " . bin2hex($originalAuthority));

// 1. حذف تمام whitespace ها (فضا، newline، tab، carriage return، و غیره)
$authority = preg_replace('/\s+/', '', $authority);
error_log("After whitespace removal: " . var_export($authority, true) . " (length: " . strlen($authority) . ")");

// 2. حذف کاراکترهای غیرقابل چاپ
$authority = preg_replace('/[\x00-\x1F\x7F]/', '', $authority);
error_log("After non-printable removal: " . var_export($authority, true) . " (length: " . strlen($authority) . ")");

// 3. فقط نگه داشتن کاراکترهای alphanumeric (A-Z, a-z, 0-9)
// زرین‌پال authority معمولاً فقط شامل حروف و اعداد است
$authority = preg_replace('/[^A-Za-z0-9]/', '', $authority);
error_log("After alphanumeric filter: " . var_export($authority, true) . " (length: " . strlen($authority) . ")");

if (empty($authority)) {
    error_log("ERROR: Authority is empty after normalization!");
    error_log("Original authority: " . var_export($originalAuthority, true));
    error_log("Original authority hex: " . bin2hex($originalAuthority));
    sendJson(500, 'error', 'authority معتبر از زرین‌پال دریافت نشد (خالی است)');
}

// 4. اطمینان از اینکه authority معتبر است
// ⚠️ مهم: authority باید دقیقاً همان چیزی باشد که از sandbox دریافت کردیم
// زرین‌پال authority را باید دقیقاً 36 کاراکتر بدهد
$authorityLength = strlen($authority);

if ($authorityLength < 1) {
    error_log("❌ ERROR: Authority is empty after normalization!");
    error_log("Original authority: " . var_export($originalAuthority, true));
    sendJson(500, 'error', 'authority معتبر از زرین‌پال دریافت نشد (خالی است)');
}

// بررسی اینکه authority معتبر است
// ⚠️ مهم: authority باید همان چیزی باشد که از sandbox دریافت کردیم
// زرین‌پال معمولاً authority را 36 کاراکتر می‌دهد، اما ممکن است بیشتر هم بدهد
// ما باید همان authority را که از sandbox دریافت کردیم، نگه داریم (بدون قطع کردن)
if ($authorityLength < 1) {
    error_log("❌ ERROR: Authority is empty after normalization!");
    error_log("Original authority: " . var_export($originalAuthority, true));
    sendJson(500, 'error', 'authority معتبر از زرین‌پال دریافت نشد (خالی است)');
}

// اگر کمتر از 36 کاراکتر است، خطا می‌دهیم (چون زرین‌پال باید 36 کاراکتر بدهد)
if ($authorityLength < 36) {
    error_log("❌ CRITICAL ERROR: Authority from sandbox is less than 36 characters!");
    error_log("Authority length: $authorityLength");
    error_log("Authority value: " . var_export($authority, true));
    error_log("Original authority from sandbox: " . var_export($originalAuthority, true));
    error_log("Original authority hex: " . bin2hex($originalAuthority));
    sendJson(500, 'error', "authority از زرین‌پال معتبر نیست - طول آن $authorityLength کاراکتر است (باید حداقل 36 کاراکتر باشد). لطفاً با پشتیبانی تماس بگیرید.");
}

// ⚠️ مهم: authority کامل را در دیتابیس ذخیره می‌کنیم (نه فقط 36 کاراکتر)
// در callback URL، زرین‌پال authority کامل را برمی‌گرداند
// برای URL پرداخت و verify، فقط 36 کاراکتر اول را استفاده می‌کنیم
$authorityForUrl = $authority; // authority کامل برای ذخیره در دیتابیس
if ($authorityLength > 36) {
    error_log("⚠️ WARNING: Authority from sandbox is more than 36 characters: $authorityLength");
    error_log("Original authority: " . var_export($authority, true));
    // برای URL پرداخت، فقط 36 کاراکتر اول را استفاده می‌کنیم
    // اما authority کامل را در دیتابیس ذخیره می‌کنیم
    $authorityForUrl = substr($authority, 0, 36);
    error_log("✅ Authority full: $authority (will be saved in DB)");
    error_log("✅ Authority for URL (first 36 chars): $authorityForUrl");
} else {
    $authorityForUrl = $authority;
}

// لاگ کردن authority نهایی (همان که از sandbox دریافت کردیم)
error_log("✅ Authority from sandbox (full, for DB): $authority (length: $authorityLength)");
error_log("✅ Authority for URL (first 36 chars): $authorityForUrl");
error_log("✅ Original authority from sandbox: " . var_export($originalAuthority, true));
error_log("=========================================");

// 6️⃣ ثبت رکورد پرداخت در دیتابیس
// مهم: باید $finalAmount را ذخیره کنیم (همان مبلغی که به زرین‌پال ارسال کردیم)
// Authority از sandbox زرین‌پال دریافت شده و باید در دیتابیس ذخیره شود
try {
    // بررسی اینکه authority خالی نیست
    if (empty($authority) || trim($authority) === '') {
        error_log("❌ ERROR: Authority is empty or null!");
        error_log("Authority value: " . var_export($authority, true));
        sendJson(500, 'error', 'authority معتبر نیست - خالی است');
    }
    
    // authority قبلاً نرمال شده است
    // بررسی نهایی: authority باید خالی نباشد
    if (empty($authority) || strlen($authority) === 0) {
        error_log("❌ CRITICAL ERROR: Authority is empty before saving to DB!");
        error_log("Authority value: " . var_export($authority, true));
        sendJson(500, 'error', 'خطا در ذخیره authority - authority خالی است');
    }
    
    // بررسی نهایی: authority باید حداقل 36 کاراکتر باشد
    // ⚠️ مهم: authority کامل را در دیتابیس ذخیره می‌کنیم (می‌تواند بیشتر از 36 کاراکتر باشد)
    if (strlen($authority) < 36) {
        error_log("❌ CRITICAL ERROR: Authority length is less than 36 before saving to DB: " . strlen($authority));
        error_log("Authority value: " . var_export($authority, true));
        sendJson(500, 'error', "خطا در ذخیره authority - طول آن " . strlen($authority) . " کاراکتر است (باید حداقل 36 کاراکتر باشد)");
    }
    
    error_log("=== SAVING AUTHORITY TO DATABASE ===");
    error_log("Authority to save: $authority");
    error_log("Authority length: " . strlen($authority));
    
    $insertUserId = $userId ?? 0; // برای کاربران مهمان از 0 استفاده می‌کنیم
    
    error_log("=== INSERTING/UPDATING PAYMENT RECORD ===");
    error_log("Order ID: $orderId");
    error_log("Authority (from sandbox): $authority");
    error_log("Authority length: " . strlen($authority));
    error_log("Amount: $finalAmount");
    error_log("User ID: " . ($insertUserId ?: 'NULL (0)'));
    
    // استفاده از INSERT ... ON DUPLICATE KEY UPDATE برای جلوگیری از race condition
    // اگر authority تکراری باشد، فقط order_id و amount را آپدیت می‌کنیم
    // مهم: authority از sandbox زرین‌پال دریافت شده و باید در دیتابیس ذخیره شود
    $sql = "INSERT INTO payments (order_id, user_id, amount, authority, status, created_at) 
            VALUES (?, ?, ?, ?, 'pending', NOW())
            ON DUPLICATE KEY UPDATE 
                order_id = IF(order_id != VALUES(order_id), VALUES(order_id), order_id),
                user_id = IF(user_id = 0 AND VALUES(user_id) != 0, VALUES(user_id), user_id),
                amount = IF(amount != VALUES(amount), VALUES(amount), amount),
                authority = VALUES(authority)"; // اطمینان از اینکه authority همیشه به‌روز می‌شود
    
    error_log("SQL Query: " . $sql);
    error_log("SQL Params: order_id=$orderId, user_id=$insertUserId, amount=$finalAmount, authority=$authority");
    
    $stmt = $conn->prepare($sql);
    
    // بررسی اینکه prepare موفق شد
    if (!$stmt) {
        $errorInfo = $conn->errorInfo();
        error_log("ERROR: Prepare failed - " . json_encode($errorInfo));
        sendJson(500, 'error', 'خطا در آماده‌سازی query: ' . ($errorInfo[2] ?? 'Unknown error'));
    }
    
    error_log("Executing INSERT with params: order_id=$orderId, user_id=$insertUserId, amount=$finalAmount, authority=$authority");
    $result = $stmt->execute([$orderId, $insertUserId, $finalAmount, $authority]);
    
    if ($result) {
        // اگر رکورد جدید بود، lastInsertId را می‌گیریم
        // اگر رکورد موجود بود، باید با SELECT پیدا کنیم
        $paymentId = $conn->lastInsertId();
        
        if ($paymentId) {
            error_log("✅ New payment record inserted - Payment ID: $paymentId");
        } else {
            // رکورد موجود بود، باید پیدا کنیم
            $stmt = $conn->prepare("SELECT id FROM payments WHERE authority = ?");
            $stmt->execute([$authority]);
            $existingPayment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingPayment) {
                $paymentId = $existingPayment['id'];
                error_log("✅ Existing payment record updated - Payment ID: $paymentId");
            } else {
                error_log("⚠️ WARNING: No payment ID returned and not found in database!");
                // تلاش برای پیدا کردن با LIKE
                $prefix = substr($authority, 0, 30);
                $stmt = $conn->prepare("SELECT id FROM payments WHERE authority LIKE ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$prefix . '%']);
                $similarPayment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($similarPayment) {
                    $paymentId = $similarPayment['id'];
                    error_log("✅ Found similar payment - Payment ID: $paymentId");
                }
            }
        }
        
        // بررسی فوری که آیا authority درست ثبت شده است
        if ($paymentId) {
            $stmt = $conn->prepare("SELECT id, order_id, authority, amount, status FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $verifyPayment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($verifyPayment) {
                error_log("✅ Payment verified in database:");
                error_log("  - ID: " . $verifyPayment['id']);
                error_log("  - Order ID: " . $verifyPayment['order_id']);
                error_log("  - Authority in DB: " . $verifyPayment['authority']);
                error_log("  - Authority original: $authority");
                error_log("  - Match: " . ($verifyPayment['authority'] === $authority ? 'YES ✅' : 'NO ❌'));
                error_log("  - Amount: " . $verifyPayment['amount']);
                error_log("  - Status: " . $verifyPayment['status']);
                
                // اگر authority مطابقت ندارد، هشدار می‌دهیم اما ادامه می‌دهیم
                if ($verifyPayment['authority'] !== $authority) {
                    error_log("⚠️ WARNING: Authority mismatch!");
                    error_log("  Expected: $authority");
                    error_log("  Got: " . $verifyPayment['authority']);
                    // استفاده از authority موجود در دیتابیس (همان authority که از sandbox دریافت شده)
                    // ⚠️ مهم: نباید authority را تغییر دهیم - باید همان authority از sandbox را نگه داریم
                    $dbAuthority = trim($verifyPayment['authority']);
                    $dbAuthority = preg_replace('/\s+/', '', $dbAuthority);
                    $dbAuthority = preg_replace('/[^A-Za-z0-9]/', '', $dbAuthority);
                    
                    // بررسی اینکه authority از دیتابیس معتبر است (باید 36 کاراکتر باشد)
                    if (strlen($dbAuthority) !== 36) {
                        error_log("❌ CRITICAL ERROR: Authority from DB is not 36 characters: " . strlen($dbAuthority));
                        error_log("DB Authority: " . var_export($dbAuthority, true));
                        error_log("Original authority: " . var_export($authority, true));
                        // استفاده از authority اصلی (نه از دیتابیس)
                        error_log("⚠️ Keeping original authority instead of DB authority");
                    } else {
                        // استفاده از authority از دیتابیس (بدون قطع کردن)
                        $authority = $dbAuthority;
                        error_log("✅ Using authority from DB (from sandbox): $authority (length: " . strlen($authority) . ")");
                    }
                }
            } else {
                error_log("❌ ERROR: Payment record not found after insert! Payment ID: $paymentId");
            }
        } else {
            error_log("❌ ERROR: No payment ID available for verification!");
        }
    } else {
        $errorInfo = $stmt->errorInfo();
        error_log("❌ ERROR: Failed to insert/update payment record!");
        error_log("Error Info: " . json_encode($errorInfo));
        error_log("Params used: order_id=$orderId, user_id=$insertUserId, amount=$finalAmount, authority=$authority");
        sendJson(500, 'error', 'خطا در ثبت اطلاعات پرداخت: ' . ($errorInfo[2] ?? 'Unknown error'));
    }
} catch (PDOException $e) {
    // اگر جدول payments وجود ندارد، خطا را لاگ کن
    error_log("Payment table error: " . $e->getMessage());
    
    // بررسی خطای duplicate entry
    if (strpos($e->getMessage(), "Duplicate entry") !== false || strpos($e->getMessage(), "23000") !== false) {
        error_log("Duplicate entry error caught: " . $e->getMessage());
        
        // اگر authority تکراری است، همان رکورد موجود را پیدا می‌کنیم و ادامه می‌دهیم
        $stmt = $conn->prepare("SELECT id, order_id, status FROM payments WHERE authority = ?");
        $stmt->execute([$authority]);
        $existingPayment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingPayment) {
            error_log("✅ Duplicate authority handled via fallback: $authority - Using existing payment record ID: " . $existingPayment['id']);
            
            // اگر order_id متفاوت است، آپدیت می‌کنیم
            if ($existingPayment['order_id'] != $orderId) {
                $updateUserId = $userId ?? 0;
                $stmt = $conn->prepare("UPDATE payments SET order_id = ?, user_id = ?, amount = ? WHERE id = ?");
                $stmt->execute([$orderId, $updateUserId, $finalAmount, $existingPayment['id']]);
                error_log("Updated payment record - Order ID changed from " . $existingPayment['order_id'] . " to $orderId");
            }
            
            // ادامه می‌دهیم با همان authority موجود - خطا نمی‌دهیم
        } else {
            // اگر authority تکراری است اما در دیتابیس پیدا نشد، این یک مشکل جدی است
            error_log("❌ CRITICAL: Duplicate entry error but payment not found in database!");
            error_log("Authority: $authority");
            error_log("Error: " . $e->getMessage());
            
            // تلاش برای پیدا کردن با LIKE
            $prefix = substr($authority, 0, 30);
            $stmt = $conn->prepare("SELECT id, order_id, authority FROM payments WHERE authority LIKE ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$prefix . '%']);
            $similarPayment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($similarPayment) {
                error_log("Found similar authority: " . $similarPayment['authority']);
                // استفاده از authority از دیتابیس (همان authority که از sandbox دریافت شده)
                // ⚠️ مهم: نباید authority را تغییر دهیم - باید همان authority از sandbox را نگه داریم
                $dbAuthority = trim($similarPayment['authority']);
                $dbAuthority = preg_replace('/\s+/', '', $dbAuthority);
                $dbAuthority = preg_replace('/[^A-Za-z0-9]/', '', $dbAuthority);
                
                // بررسی اینکه authority از دیتابیس معتبر است (باید 36 کاراکتر باشد)
                if (strlen($dbAuthority) !== 36) {
                    error_log("❌ CRITICAL ERROR: Authority from DB is not 36 characters: " . strlen($dbAuthority));
                    error_log("DB Authority: " . var_export($dbAuthority, true));
                    error_log("Original authority: " . var_export($authority, true));
                    // استفاده از authority اصلی (نه از دیتابیس)
                    error_log("⚠️ Keeping original authority instead of DB authority");
                } else {
                    // استفاده از authority از دیتابیس (بدون قطع کردن)
                    $authority = $dbAuthority;
                    error_log("✅ Using authority from DB (from sandbox): $authority (length: " . strlen($authority) . ")");
                }
            } else {
                sendJson(500, 'error', 'خطا در ثبت اطلاعات پرداخت: authority تکراری است و در دیتابیس یافت نشد');
            }
        }
    } else if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Base table or view not found") !== false) {
        sendJson(500, 'error', 'جدول payments در دیتابیس وجود ندارد. لطفاً جدول را ایجاد کنید.', [
            'error_details' => $e->getMessage(),
            'table_name' => 'payments'
        ]);
    } else {
        sendJson(500, 'error', 'خطا در ثبت اطلاعات پرداخت: ' . $e->getMessage());
    }
}

// 7️⃣ بررسی نهایی که authority در دیتابیس ذخیره شده است
error_log("=== FINAL VERIFICATION ===");
$stmt = $conn->prepare("SELECT id, authority, order_id, status FROM payments WHERE authority = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$authority]);
$finalCheck = $stmt->fetch(PDO::FETCH_ASSOC);

if ($finalCheck) {
    error_log("✅ FINAL CHECK: Authority found in database!");
    error_log("  - Payment ID: " . $finalCheck['id']);
    error_log("  - Authority: " . $finalCheck['authority']);
    error_log("  - Order ID: " . $finalCheck['order_id']);
    error_log("  - Status: " . $finalCheck['status']);
} else {
    error_log("⚠️ WARNING: Authority not found in final check!");
    error_log("  - Searching authority: $authority");
    
    // تلاش برای پیدا کردن با prefix
    $prefix = substr($authority, 0, 30);
    $stmt = $conn->prepare("SELECT id, authority, order_id FROM payments WHERE authority LIKE ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $prefixCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($prefixCheck) {
        error_log("✅ Found with prefix search:");
        error_log("  - Payment ID: " . $prefixCheck['id']);
        error_log("  - Authority in DB: " . $prefixCheck['authority']);
        error_log("  - Authority searched: $authority");
    }
}
error_log("==========================");

// 8️⃣ ساخت لینک درگاه پرداخت بر اساس mode
// برای URL پرداخت، فقط 36 کاراکتر اول authority را استفاده می‌کنیم
// authority کامل در دیتابیس ذخیره شده است
if (empty($authorityForUrl) || strlen($authorityForUrl) === 0) {
    error_log("❌ CRITICAL ERROR: Authority for URL is empty!");
    error_log("Authority value: " . var_export($authorityForUrl, true));
    sendJson(500, 'error', 'خطا در ساخت لینک پرداخت - authority خالی است');
}

// بررسی نهایی: authority برای URL باید حداقل 36 کاراکتر باشد
if (strlen($authorityForUrl) < 36) {
    error_log("❌ CRITICAL ERROR: Authority for URL is less than 36 characters: " . strlen($authorityForUrl));
    error_log("Authority value: " . var_export($authorityForUrl, true));
    sendJson(500, 'error', "خطا در ساخت لینک پرداخت - authority معتبر نیست (طول: " . strlen($authorityForUrl) . " کاراکتر، باید حداقل 36 کاراکتر باشد)");
}

if ($mode === 'production') {
    // آدرس درگاه پرداخت واقعی
    $paymentUrl = "https://www.zarinpal.com/pg/StartPay/" . $authorityForUrl;
} else {
    // آدرس درگاه پرداخت sandbox (تست)
    $paymentUrl = "https://sandbox.zarinpal.com/pg/StartPay/" . $authorityForUrl;
}

error_log("Payment URL created: $paymentUrl");
error_log("Authority in URL (first 36 chars): $authorityForUrl (length: " . strlen($authorityForUrl) . ")");
error_log("Authority full (saved in DB): $authority (length: " . strlen($authority) . ")");

// 9️⃣ پاسخ نهایی به فرانت‌اند
// authority کامل در دیتابیس ذخیره شده، اما برای URL فقط 36 کاراکتر اول استفاده می‌شود
error_log("=== FINAL RESPONSE ===");
error_log("Payment URL: $paymentUrl");
error_log("Authority for URL (first 36 chars): $authorityForUrl (length: " . strlen($authorityForUrl) . ")");
error_log("Authority full (saved in DB): $authority (length: " . strlen($authority) . ")");
error_log("Amount: $finalAmount");

sendJson(200, 'success', 'در حال انتقال به درگاه پرداخت', [
    'payment_url' => $paymentUrl,
    'authority'   => $authorityForUrl, // authority برای URL (36 کاراکتر اول)
    'authority_full' => $authority, // authority کامل (ذخیره شده در دیتابیس)
    'amount'      => $finalAmount,
    'original_amount' => $totalAmount, // مبلغ اصلی به تومان
    'currency'    => $currency
]);
