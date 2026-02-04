<?php
/**
 * تأیید تراکنش برگشتی از زرین‌پال
 * این اسکریپت توسط callback_url در config/zarinpal.php فراخوانی می‌شود.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../db_connection.php';

// تنظیمات زرین‌پال
$zarinpalConfig = require __DIR__ . '/../../config/zarinpal.php';

$conn = getPDO();

// هلسپر برای ساخت URL ریدایرکت
function redirectToResult($success, $orderId = null, $message = null, $authority = null, $refId = null)
{
    // تشخیص محیط (localhost یا production)
    $isLocalhost = (
        isset($_SERVER['HTTP_HOST']) && (
            strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
            strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false
        )
    );
    
    // ساخت URL پایه فرانت‌اند
    if ($isLocalhost) {
        $baseUrl = 'http://localhost:3000';
    } else {
        $baseUrl = 'https://aminindpharm.ir';
    }
    
    // تعیین مسیر
    if ($success) {
        $path = '/order-success';
    } else {
        $path = '/payment-failed';
    }

    $params = [];
    if ($orderId) {
        $params['order_id'] = $orderId;
    }
    if ($message) {
        $params['msg'] = urlencode($message);
    }
    // اضافه کردن authority برای دیباگ
    if ($authority) {
        $params['authority'] = $authority;
    }
    // اضافه کردن ref_id به عنوان کد پیگیری
    if ($refId) {
        $params['ref_id'] = $refId;
    }

    $url = $baseUrl . $path;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit;
}

// 1️⃣ دریافت پارامترهای GET از callback
$authorityRaw = $_GET['Authority'] ?? ($_GET['authority'] ?? null);
$statusParam = $_GET['Status'] ?? ($_GET['status'] ?? null);

// لاگ کردن پارامترهای دریافتی برای دیباگ
error_log("Payment callback received - Authority (raw): " . ($authorityRaw ?? 'NULL') . ", Status: " . ($statusParam ?? 'NULL'));
error_log("All GET params: " . json_encode($_GET, JSON_UNESCAPED_UNICODE));

if (!$authorityRaw) {
    error_log("Payment callback error: Authority parameter is missing");
    redirectToResult(false, null, 'پارامتر authority معتبر نیست', null);
}

// ⚠️ مهم: authority کامل را از callback دریافت می‌کنیم
// authority در دیتابیس کامل ذخیره شده است (مثل S0000000000000000000000000000001edln)
// از callback هم authority کامل می‌آید (مثل S0000000000000000000000000000001edln)
// پس باید authority کامل را جستجو کنیم
$authority = $authorityRaw; // authority کامل برای جستجو در دیتابیس
error_log("Authority from callback (full): $authority (length: " . strlen($authority) . ")");

// 2️⃣ اگر کاربر پرداخت را لغو کرده باشد
if (strtolower($statusParam) !== 'ok') {
    // جستجو با چند روش برای پیدا کردن authority
    $payment = null;
    
    // جستجوی دقیق با authority کامل
    $stmt = $conn->prepare("SELECT * FROM payments WHERE authority = ?");
    $stmt->execute([$authority]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // اگر پیدا نشد، با 36 کاراکتر اول جستجو کن
    if (!$payment) {
        $authority36 = substr($authority, 0, 36);
        $stmt = $conn->prepare("SELECT * FROM payments WHERE authority = ? OR authority LIKE ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$authority36, $authority36 . '%']);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($payment) {
        $stmt = $conn->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
        $stmt->execute([$payment['id']]);
        $orderId = $payment['order_id'];
    } else {
        $orderId = null;
    }

    redirectToResult(false, $orderId, 'پرداخت توسط کاربر لغو شد', $authority);
}

// 3️⃣ پیدا کردن رکورد پرداخت برای دانستن مبلغ
// ⚠️ مهم: authority کامل در دیتابیس ذخیره شده است
// جستجو با چند روش:
// 1. جستجوی دقیق با authority کامل از callback
// 2. جستجو با 36 کاراکتر اول (برای سازگاری با رکوردهای قدیمی)
// 3. جستجو با prefix (36 کاراکتر اول) برای پیدا کردن authority کامل

$payment = null;

// روش 1: جستجوی دقیق با authority کامل از callback
$stmt = $conn->prepare("SELECT * FROM payments WHERE authority = ?");
$stmt->execute([$authority]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    // روش 2: جستجو با 36 کاراکتر اول (برای سازگاری با رکوردهای قدیمی)
    $authority36 = substr($authority, 0, 36);
    $stmt = $conn->prepare("SELECT * FROM payments WHERE authority = ?");
    $stmt->execute([$authority36]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($payment) {
        error_log("Payment found with 36-char search - DB Authority: " . $payment['authority'] . ", Search: $authority36");
    }
}

if (!$payment) {
    // روش 3: جستجو با prefix (36 کاراکتر اول) برای پیدا کردن authority کامل
    $prefix = substr($authority, 0, 36); // 36 کاراکتر اول برای جستجوی بهتر
    $stmt = $conn->prepare("SELECT * FROM payments WHERE authority LIKE ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($payment) {
        error_log("Payment found with prefix search - DB Authority: " . $payment['authority'] . ", Search: $authority");
    }
}

if (!$payment) {
    // لاگ کردن برای دیباگ
    error_log("Payment not found in database - Authority (normalized): $authority, Authority (raw): $authorityRaw");
    
    // بررسی تمام پرداخت‌های pending برای دیباگ
    $stmt = $conn->prepare("SELECT id, authority, order_id, status, created_at FROM payments WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
    $stmt->execute();
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Recent pending payments: " . json_encode($recentPayments, JSON_UNESCAPED_UNICODE));
    
    redirectToResult(false, null, 'پرداخت یافت نشد. لطفاً با پشتیبانی تماس بگیرید.', $authority);
}

$orderId = $payment['order_id'];
$amount  = (int)$payment['amount']; // این مبلغ همان مقداری است که به زرین‌پال ارسال شده

// استفاده از authority از دیتابیس (authority کامل)
// authority در دیتابیس کامل ذخیره شده است (مثل S0000000000000000000000000000001edln)
$dbAuthority = $payment['authority'];
error_log("Authority from database (full): $dbAuthority (length: " . strlen($dbAuthority) . ")");
error_log("Authority from callback (full): $authority (length: " . strlen($authority) . ")");

// نرمال‌سازی authority برای ارسال به زرین‌پال: باید دقیقاً 36 کاراکتر باشد
// برای verify به زرین‌پال، فقط 36 کاراکتر اول را استفاده می‌کنیم
// authority در دیتابیس می‌تواند بیشتر از 36 کاراکتر باشد (کامل)
$verifyAuthority = substr($dbAuthority, 0, 36);
$verifyAuthorityLength = strlen($verifyAuthority);

if ($verifyAuthorityLength < 36) {
    // اگر کمتر از 36 کاراکتر است، این یک مشکل جدی است
    error_log("❌ ERROR: Authority in database is less than 36 characters: $verifyAuthorityLength");
    error_log("Authority from DB: $dbAuthority");
    redirectToResult(false, $orderId, 'خطا: authority در دیتابیس معتبر نیست (کمتر از 36 کاراکتر)', $authority);
}

error_log("✅ Authority for verify (first 36 chars): $verifyAuthority (length: " . strlen($verifyAuthority) . ")");
error_log("✅ Authority full in DB: $dbAuthority (length: " . strlen($dbAuthority) . ")");

// 4️⃣ فراخوانی متد verify زرین‌پال
$merchantId = $zarinpalConfig['merchant_id'];
$mode       = $zarinpalConfig['mode'] ?? 'sandbox';
$currency   = $zarinpalConfig['currency'] ?? 'IRT';

// بررسی merchant_id
if (empty($merchantId) || $merchantId === 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx') {
    error_log("WARNING: Invalid or test merchant_id detected: $merchantId");
    // در sandbox mode، باید از merchant_id معتبر استفاده شود
    // اگر merchant_id تستی است، خطا می‌دهیم
    if ($mode === 'sandbox' && $merchantId === 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx') {
        redirectToResult(false, $orderId, 'لطفاً merchant_id معتبر را در فایل config/zarinpal.php تنظیم کنید', $authority);
    }
}

$verifyUrl = $mode === 'production'
    ? 'https://api.zarinpal.com/pg/v4/payment/verify.json'
    : 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';

// مبلغ در payments table همان مقداری است که به زرین‌پال ارسال شده
// مهم: authority باید دقیقاً 36 کاراکتر باشد
$verifyData = [
    'merchant_id' => $merchantId,
    'amount'      => $amount, // همان مبلغی که در create-payment ارسال کردیم
    'authority'   => $verifyAuthority, // authority نرمال شده به 36 کاراکتر
    'currency'    => $currency, // باید همان currency باشد که در create-payment استفاده کردیم
];

error_log("Verify request data: " . json_encode($verifyData, JSON_UNESCAPED_UNICODE));

$ch = curl_init($verifyUrl);
curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verifyData));
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
    redirectToResult(false, $orderId, 'خطا در اتصال به زرین‌پال', $authority);
}

$resultData = json_decode($result, true);

// لاگ کردن پاسخ کامل برای دیباگ
error_log("Zarinpal verify response - HTTP: $httpStatus, Response: " . json_encode($resultData, JSON_UNESCAPED_UNICODE));
error_log("Zarinpal verify request - Amount: $amount, Currency: $currency, Authority: $authority, Merchant: $merchantId");

// بررسی خطاهای GraphQL (API جدید)
if (isset($resultData['errors']) && !empty($resultData['errors'])) {
    $error = $resultData['errors'];
    $errorMessage = is_array($error) && isset($error['message']) 
        ? $error['message'] 
        : (is_string($error) ? $error : 'خطا در ارتباط با زرین‌پال');
    error_log("Zarinpal verify error: " . json_encode($error, JSON_UNESCAPED_UNICODE));
    redirectToResult(false, $orderId, $errorMessage, $authority);
}

if ($httpStatus !== 200) {
    error_log("Zarinpal verify invalid HTTP status - HTTP: $httpStatus, Response: " . json_encode($resultData, JSON_UNESCAPED_UNICODE));
    redirectToResult(false, $orderId, 'خطا در ارتباط با زرین‌پال (HTTP: ' . $httpStatus . ')', $authority);
}

if (!isset($resultData['data']['code'])) {
    error_log("Zarinpal verify missing code - Response: " . json_encode($resultData, JSON_UNESCAPED_UNICODE));
    redirectToResult(false, $orderId, 'پاسخ نامعتبر از زرین‌پال', $authority);
}

$code  = (int)$resultData['data']['code'];
$refId = $resultData['data']['ref_id'] ?? null;

// 5️⃣ تفسیر نتیجه verify
if (in_array($code, [100, 101])) {
    $paymentStatus = 'success';
} else {
    $paymentStatus = 'failed';
}

// 6️⃣ آپدیت جدول payments
$stmt = $conn->prepare("UPDATE payments SET status = ?, ref_id = ? WHERE id = ?");
$stmt->execute([$paymentStatus, $refId, $payment['id']]);

// 7️⃣ اگر پرداخت موفق بود، سفارش و موجودی را آپدیت کن
if ($paymentStatus === 'success') {
    // آپدیت وضعیت سفارش
    $stmt = $conn->prepare("UPDATE orders SET status = 'paid' WHERE id = ?");
    $stmt->execute([$orderId]);

    // ارسال پیامک به ادمین: سفارش جدید دارید
    try {
        require_once __DIR__ . '/../../panel/order/orders.php';
        sendOrderNotificationToAdmin($orderId, $conn);
    } catch (Exception $e) {
        error_log("خطا در ارسال پیامک به ادمین برای سفارش #$orderId: " . $e->getMessage());
    }

    // ارسال پیامک تایید پرداخت به کاربر
    try {
        require_once __DIR__ . '/../../panel/order/orders.php';
        sendPaymentConfirmationToUser($orderId, $refId, $conn);
    } catch (Exception $e) {
        error_log("خطا در ارسال پیامک تایید پرداخت به کاربر برای سفارش #$orderId: " . $e->getMessage());
    }

    // کاهش موجودی محصولات
    $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $stmt->execute([$item['quantity'], $item['product_id']]);
    }

    // پاک کردن سبد خرید بعد از پرداخت موفق
    // پیدا کردن cart_id مربوط به این order
    $stmt = $conn->prepare("SELECT user_id, guest_token FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        $cartId = null;
        if ($order['user_id']) {
            $stmt = $conn->prepare("SELECT id FROM carts WHERE user_id = ?");
            $stmt->execute([$order['user_id']]);
            $cart = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cart) {
                $cartId = $cart['id'];
            }
        } elseif ($order['guest_token']) {
            $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
            $stmt->execute([$order['guest_token']]);
            $cart = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cart) {
                $cartId = $cart['id'];
            }
        }
        
        // پاک کردن سبد خرید
        if ($cartId) {
            $stmt = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
            $stmt->execute([$cartId]);
        }
    }

    redirectToResult(true, $orderId, 'پرداخت با موفقیت انجام شد. کد پیگیری: ' . $refId, $authority, $refId);
}

// در صورت ناموفق بودن
// تبدیل کدهای خطا به پیام‌های فارسی
$errorMessages = [
    -9 => 'مبلغ باید حداقل 1000 تومان باشد',
    -10 => 'درخواست نامعتبر است',
    -11 => 'درگاه پرداخت یافت نشد',
    -12 => 'تعداد درخواست‌های شما بیش از حد مجاز است',
    -15 => 'تراکنش تکراری است',
    -16 => 'تراکنش یافت نشد',
    -30 => 'مبلغ با مبلغ پرداخت شده مطابقت ندارد',
    -31 => 'درخواست نامعتبر است',
    -32 => 'اطلاعات ارسالی صحیح نیست',
    -33 => 'مبلغ کمتر از حداقل مجاز است',
    -34 => 'مبلغ بیشتر از حداکثر مجاز است',
    -35 => 'تعداد درخواست‌ها بیش از حد مجاز است',
    -40 => 'درخواست نامعتبر است',
    -50 => 'تراکنش یافت نشد',
    -51 => 'تراکنش تکراری است',
    -52 => 'مبلغ با مبلغ پرداخت شده مطابقت ندارد',
];

$errorMessage = isset($errorMessages[$code]) 
    ? $errorMessages[$code] 
    : 'پرداخت ناموفق بود. کد وضعیت: ' . $code;

error_log("Payment verify failed - Code: $code, Order ID: $orderId, Authority: $authority");

redirectToResult(false, $orderId, $errorMessage);
