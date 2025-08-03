<?php
require_once '../db_connection.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json; charset=UTF-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

$mobile = trim($data->mobile ?? '');

if (empty($mobile) || !preg_match('/^09\d{9}$/', $mobile)) {
    echo json_encode(['status' => false, 'message' => 'شماره موبایل نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // حذف رکوردهای خیلی قدیمی‌تر از 5 دقیقه (برای تمیزکاری)
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE created_at < NOW() - INTERVAL 5 MINUTE");
    $stmt->execute();

    // بررسی وجود کاربر
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => false, 'message' => 'شماره موبایل ثبت نشده است. ابتدا ثبت‌نام کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // جلوگیری از ارسال مکرر OTP (حداقل 120 ثانیه فاصله)
    $stmt = $conn->prepare("SELECT created_at FROM otp_requests WHERE mobile = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$mobile]);
    $last_request = $stmt->fetchColumn();

    if ($last_request && strtotime($last_request) > strtotime('-120 seconds')) {
        echo json_encode(['status' => false, 'message' => 'لطفاً بعد از ۲ دقیقه دوباره تلاش کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تولید OTP و توکن ثبت نام
    $otp_code = random_int(100000, 999999); // امن‌تر از rand
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $register_token = bin2hex(random_bytes(16)); // توکن 32 حرفی تصادفی

    // ذخیره در جدول otp_requests
    $stmt = $conn->prepare("INSERT INTO otp_requests (mobile, otp_code, otp_expires_at, register_token, created_at) 
                            VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$mobile, $otp_code, $otp_expires_at, $register_token]);

    // ارسال پیامک (باید خودت این تابع رو بسازی)
    // send_sms($mobile, "کد تایید شما: $otp_code");

    echo json_encode([
        'status' => true,
        'message' => 'کد تایید ارسال شد.',
        'register_token' => $register_token
        // 'otp' => $otp_code // فقط در محیط تست، در نسخه نهایی حذف شود
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'status' => false,
        'message' => 'خطای سرور: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
