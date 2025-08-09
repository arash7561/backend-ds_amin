<?php
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../vendor/autoload.php';

// استفاده از فایل کانفیگ برای کلید مخفی JWT
$secret_key = 'your-secret-key'; // بهتره از env یا config بخونی

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json; charset=UTF-8");

// گرفتن داده‌ی خام و بررسی اولیه
$json = file_get_contents('php://input');
$data = json_decode($json);

// بررسی وجود داده
if (!$data) {
    echo json_encode(['status' => false, 'message' => 'داده ارسال نشده یا نادرست است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$register_token = trim($data->register_token ?? '');
$otp_code = trim($data->otp_code ?? '');

if (empty($register_token) || empty($otp_code)) {
    echo json_encode(['status' => false, 'message' => 'توکن و کد تایید الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جستجوی رکورد otp_requests
    $stmt = $conn->prepare("SELECT * FROM otp_requests WHERE register_token = ? AND otp_code = ?");
    $stmt->execute([$register_token, $otp_code]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        echo json_encode(['status' => false, 'message' => 'کد تایید اشتباه است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strtotime($request['otp_expires_at']) < time()) {
        echo json_encode(['status' => false, 'message' => 'کد تایید منقضی شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mobile = $request['mobile'];

    // بررسی وجود کاربر
    $stmt = $conn->prepare("SELECT * FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد. ابتدا ثبت نام کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اگر وضعیت کاربر غیرفعال بود
    if (isset($user['status']) && $user['status'] !== 'active') {
        echo json_encode(['status' => false, 'message' => 'حساب شما غیرفعال است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ساخت payload برای توکن

    $guest_token = $request['guest_token'];
    
    $payload = [
        'iss' => 'http://localhost', // منبع صادرکننده
        'iat' => time(),             // زمان صدور
        'exp' => time() + 3600,      // زمان انقضا (یک ساعت)
        'uid' => $user['id'],        // شناسه کاربر
        'mobile' => $user['mobile']  // (اختیاری) موبایل یا اطلاعات اضافه
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    // حذف رکورد otp_requests پس از موفقیت
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    // پاسخ موفقیت
    echo json_encode([
        'status' => true,
        'message' => 'ورود موفقیت‌آمیز بود.',
        'token' => $jwt,
        'uid' => $user['id']
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // فقط برای تست - در محیط واقعی فقط پیام کلی بده
    echo json_encode(['status' => false, 'message' => 'خطای سرور. لطفاً بعداً تلاش کنید.'], JSON_UNESCAPED_UNICODE);
}
