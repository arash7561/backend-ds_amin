<?php

require_once '../db_connection.php';

$json = file_get_contents('php://input');
$data = json_decode($json);

$name = trim($data->name ?? '');
$mobile = trim($data->mobile ?? '');

$error = null;

if(empty($name)) {
    $error = 'نام و نام خانوادگی خالی است';
}
elseif(empty($mobile)) {
    $error = 'شماره موبایل خالی است';
}
else {
    // بررسی وجود شماره موبایل
    $query = "SELECT * FROM users WHERE mobile = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    // کد OTP 6 رقمی تولید کن
    $otp_code = rand(100000, 999999);
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes')); // کد تا 5 دقیقه اعتبار داره

    if($user === false){
        // ثبت نام کاربر جدید با کد OTP و تایید نشده
        $query = "INSERT INTO users (name, mobile, otp_code, otp_expires_at, is_verified, created_at) VALUES (?, ?, ?, ?, 0, NOW())";
        $stmt = $conn->prepare($query);
        $res = $stmt->execute([$name, $mobile, $otp_code, $otp_expires_at]);

        if($res){
            // ارسال پیامک کد OTP به شماره موبایل (اینجا فقط کامنت)
            // send_sms($mobile, "کد تایید شما: $otp_code");

            $response = ['status' => true, 'message' => 'ثبت نام با موفقیت انجام شد. کد تایید ارسال شد.'];
        } else {
            $response = ['status' => false, 'message' => 'ثبت نام با موفقیت انجام نشد.'];
        }
    } else {
        // اگر قبلا ثبت نام شده، فقط کد OTP رو آپدیت کن و دوباره بفرست
        $query = "UPDATE users SET otp_code = ?, otp_expires_at = ?, is_verified = 0 WHERE mobile = ?";
        $stmt = $conn->prepare($query);
        $res = $stmt->execute([$otp_code, $otp_expires_at, $mobile]);

        if($res){
            // ارسال پیامک مجدد کد OTP
            // send_sms($mobile, "کد تایید شما: $otp_code");

            $response = ['status' => true, 'message' => 'کد تایید مجدداً ارسال شد.'];
        } else {
            $response = ['status' => false, 'message' => 'خطا در ارسال کد تایید.'];
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

if($error !== null) {
    echo json_encode(['status' => false, 'message' => $error], JSON_UNESCAPED_UNICODE);
}
