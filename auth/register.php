<?php

require_once '../db_connection.php';
$conn = getPDO();
header("Content-Type: application/json; charset=UTF-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

$name   = trim($data->username ?? '');
$mobile = trim($data->mobile ?? '');
$guest_token = trim($data->guest_token ?? '');
$response = [];

// بررسی اولیه
if (empty($name)) {
    echo json_encode(['status' => false, 'message' => 'نام کاربری الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($mobile) || !preg_match('/^09\d{9}$/', $mobile)) {
    echo json_encode(['status' => false, 'message' => 'شماره موبایل نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // آیا قبلاً کاربر ثبت‌نام شده؟
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'این شماره قبلاً ثبت‌نام شده. لطفاً وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف OTP قبلی برای این شماره (اگر وجود دارد)
    $conn->prepare("DELETE FROM otp_requests WHERE mobile = ?")->execute([$mobile]);

    // ساخت OTP و توکن ثبت‌نام موقت
    $otp_code = rand(100000, 999999);
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $register_token = bin2hex(random_bytes(32));

    // درج اطلاعات در جدول موقت otp_requests
    $stmt = $conn->prepare("INSERT INTO otp_requests (name, mobile, otp_code, otp_expires_at, register_token, guest_token) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $mobile, $otp_code, $otp_expires_at, $register_token, $guest_token]);

    echo json_encode([
        'status' => true,
        'message' => 'کد تایید ارسال شد.',
        'register_token' => $register_token,
        // 'otp' => $otp_code // فقط برای تست – در نسخه نهایی حذف شود
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
