<?php
/**
 * تأیید تراکنش برگشتی از زرین‌پال
 * این اسکریپت توسط callback_url در config/zarinpal.php فراخوانی می‌شود.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../db_connection.php';

// تنظیمات زرین‌پال
$zarinpalConfig = require __DIR__ . '/../../config/zarinpal.php';

$conn = getPDO();

// هلسپر برای ساخت URL ریدایرکت
function redirectToResult($success, $orderId = null, $message = null)
{
    // این آدرس‌ها را با مسیرهای واقعی فرانت‌اند خودت هماهنگ کن
    if ($success) {
        $url = '/payment-success';
    } else {
        $url = '/payment-failed';
    }

    $params = [];
    if ($orderId) {
        $params['order_id'] = $orderId;
    }
    if ($message) {
        $params['msg'] = urlencode($message);
    }

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit;
}

// 1️⃣ دریافت پارامترهای GET از callback
$authority = $_GET['Authority'] ?? ($_GET['authority'] ?? null);
$statusParam = $_GET['Status'] ?? ($_GET['status'] ?? null);

if (!$authority) {
    redirectToResult(false, null, 'پارامتر authority معتبر نیست');
}

// 2️⃣ اگر کاربر پرداخت را لغو کرده باشد
if (strtolower($statusParam) !== 'ok') {
    // فقط رکورد را به failed تغییر می‌دهیم اگر وجود داشت
    $stmt = $conn->prepare("SELECT * FROM payments WHERE authority = ?");
    $stmt->execute([$authority]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        $stmt = $conn->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
        $stmt->execute([$payment['id']]);
        $orderId = $payment['order_id'];
    } else {
        $orderId = null;
    }

    redirectToResult(false, $orderId, 'پرداخت توسط کاربر لغو شد');
}

// 3️⃣ پیدا کردن رکورد پرداخت برای دانستن مبلغ
$stmt = $conn->prepare("SELECT * FROM payments WHERE authority = ?");
$stmt->execute([$authority]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    redirectToResult(false, null, 'پرداخت یافت نشد');
}

$orderId = $payment['order_id'];
$amount  = (int)$payment['amount'];

// 4️⃣ فراخوانی متد verify زرین‌پال
$merchantId = $zarinpalConfig['merchant_id'];
$mode       = $zarinpalConfig['mode'] ?? 'sandbox';

$verifyUrl = $mode === 'production'
    ? 'https://api.zarinpal.com/pg/v4/payment/verify.json'
    : 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';

$verifyData = [
    'merchant_id' => $merchantId,
    'amount'      => $amount,
    'authority'   => $authority,
];

$ch = curl_init($verifyUrl);
curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verifyData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
]);

$result = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    redirectToResult(false, $orderId, 'خطا در اتصال به زرین‌پال');
}

$resultData = json_decode($result, true);

if ($httpStatus !== 200 || !isset($resultData['data']['code'])) {
    redirectToResult(false, $orderId, 'پاسخ نامعتبر از زرین‌پال');
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

    // کاهش موجودی محصولات
    $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $stmt->execute([$item['quantity'], $item['product_id']]);
    }

    redirectToResult(true, $orderId, 'پرداخت با موفقیت انجام شد. کد پیگیری: ' . $refId);
}

// در صورت ناموفق بودن
redirectToResult(false, $orderId, 'پرداخت ناموفق بود. کد وضعیت: ' . $code);
