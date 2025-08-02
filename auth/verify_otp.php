<?php
require_once '../db_connection.php';
header("Content-Type: application/json; charset=UTF-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

$register_token = trim($data->register_token ?? '');
$otp_code = trim($data->otp_code ?? '');

if (empty($register_token) || empty($otp_code)) {
    echo json_encode(['status' => false, 'message' => 'توکن ثبت‌نام و کد تایید الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جستجو رکورد درخواست OTP با توکن و کد
    $stmt = $conn->prepare("SELECT * FROM otp_requests WHERE register_token = ? AND otp_code = ?");
    $stmt->execute([$register_token, $otp_code]);
    $request = $stmt->fetch();

    if (!$request) {
        echo json_encode(['status' => false, 'message' => 'توکن یا کد تایید اشتباه است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // چک کردن انقضای کد OTP
    if (strtotime($request['otp_expires_at']) < time()) {
        echo json_encode(['status' => false, 'message' => 'کد تایید منقضی شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mobile = $request['mobile'];
    $name = $request['name'];

    // چک کردن وجود کاربر در جدول users
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode(['status' => false, 'message' => 'این شماره موبایل قبلا ثبت شده است. لطفا وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ثبت نهایی کاربر
    $stmt = $conn->prepare("INSERT INTO users (name, mobile, is_verified, created_at) VALUES (?, ?, 1, NOW())");
    $stmt->execute([$name, $mobile]);

    // حذف درخواست OTP (اختیاری)
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    echo json_encode(['status' => true, 'message' => 'ثبت نام با موفقیت انجام شد.'], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
