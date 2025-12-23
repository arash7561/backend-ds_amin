<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../../db_connection.php';

// JWT Auth
$_ENV['AUTH_CHECK_REQUIRED'] = true;
$userData = require __DIR__ . '/auth_check.php';

// JSON helper
function sendJson($statusCode, $type, $text, $extra = []) {
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'status' => $statusCode,
        'type' => $type,
        'text' => $text
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// 1️⃣ بررسی لاگین
if (empty($userData['user_id'])) {
    sendJson(401, 'error', 'کاربر احراز هویت نشده است');
}
$userId = (int)$userData['user_id'];

// 2️⃣ اعتبارسنجی ورودی‌ها
$requiredFields = ['order_id','price','description'];
foreach ($requiredFields as $field) {
    if (empty($_POST[$field])) {
        sendJson(400, 'warning', 'اطلاعات ورودی نامعتبر است');
    }
}

$orderId = (int)$_POST['order_id'];
$priceToman = (int)$_POST['price'];
$description = trim(strip_tags($_POST['description']));

if (!is_numeric($priceToman) || $priceToman < 1000) {
    sendJson(400, 'warning', 'حداقل مبلغ پرداخت باید 1000 تومان باشد');
}

// تبدیل تومان → ریال
$price = $priceToman * 10;

// اتصال به دیتابیس
$pdo = getPDO();

// 3️⃣ بررسی سفارش و کاربر
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :order_id AND user_id = :user_id");
$stmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    sendJson(404, 'error', 'سفارش پیدا نشد یا متعلق به کاربر نیست');
}

// 4️⃣ گرفتن اطلاعات کاربر از جدول users
$stmt = $pdo->prepare("SELECT name, mobile FROM users WHERE id = :user_id");
$stmt->execute([':user_id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    sendJson(404, 'error', 'کاربر پیدا نشد');
}

$userFullName = $user['name'];
$mobile = $user['mobile'];

// 5️⃣ ایجاد authority یکتا
$authority = bin2hex(random_bytes(8));

// 6️⃣ ثبت پرداخت در جدول payments
$stmt = $pdo->prepare("
    INSERT INTO payments (order_id, user_id, amount, authority, description, status, created_at)
    VALUES (:order_id, :user_id, :amount, :authority, :description, 'pending', NOW())
");
$ok = $stmt->execute([
    ':order_id' => $orderId,
    ':user_id' => $userId,
    ':amount' => $price,
    ':authority' => $authority,
    ':description' => $description
]);

if (!$ok) {
    sendJson(500, 'error', 'خطا در ایجاد پرداخت');
}

// 7️⃣ ساخت URL درگاه (فرضی)
$paymentUrl = '/go-to-gateway?authority=' . urlencode($authority) . '&amount=' . $price;

// 8️⃣ پاسخ نهایی
sendJson(200, 'success', 'در حال انتقال به درگاه پرداخت', [
    'url' => $paymentUrl,
    'authority' => $authority,
    'amount' => $price
]);
