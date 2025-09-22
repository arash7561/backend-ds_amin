<?php
require_once '../db_connection.php';
$conn = getPDO();
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json; charset=UTF-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

$mobile = trim($data->mobile ?? '');
$guestToken = trim($data->guest_token ?? ''); // ✅ دریافت guest_token

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

    $userId = $user['id'];

    // ✅ انتقال سبد مهمان (اگر وجود دارد)
    if (!empty($guestToken)) {
        $stmt = $conn->prepare("SELECT id FROM carts WHERE guest_token = ?");
        $stmt->execute([$guestToken]);
        $guestCart = $stmt->fetch();

        if ($guestCart) {
            $stmt = $conn->prepare("UPDATE carts SET user_id = ?, guest_token = NULL WHERE id = ?");
            $stmt->execute([$userId, $guestCart['id']]);
        }
    }

    // حذف درخواست‌های قبلی همین شماره
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE mobile = ?");
    $stmt->execute([$mobile]);

    // تولید OTP و توکن ثبت نام
    $otp_code = random_int(100000, 999999);
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $register_token = bin2hex(random_bytes(16));

    // گرفتن نام کاربر از جدول users
    $stmt = $conn->prepare("SELECT name FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userInfo['name'] ?? '';

    // ذخیره در جدول otp_requests
    $stmt = $conn->prepare("INSERT INTO otp_requests (name, mobile, otp_code, otp_expires_at, register_token, created_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$userName, $mobile, $otp_code, $otp_expires_at, $register_token]);

    // ارسال پیامک (اختیاری)
    // send_sms($mobile, "کد تایید شما: $otp_code");

    echo json_encode([
        'status' => true,
        'message' => 'کد تایید ارسال شد.',
        'register_token' => $register_token
        // 'otp' => $otp_code // فقط برای تست
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'status' => false,
        'message' => 'خطای سرور: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
