<?php
require_once '../db_connection.php';
require_once __DIR__ . '/../vendor/autoload.php'; 

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json; charset=UTF-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

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

    // کلید مخفی JWT (ایده‌آل: این رو در فایل config یا env ذخیره کن)
    $secret_key = 'your-secret-key';

    // ساخت payload
    $payload = [
        'iss' => 'http://localhost', // منبع صادرکننده
        'iat' => time(),             // زمان صدور
        'exp' => time() + (15 * 24 * 60 * 60), // زمان انقضا (15 روز)
        'uid' => $user['id'],        // شناسه کاربر
        'mobile' => $user['mobile']  // (اختیاری) موبایل یا اطلاعات اضافه
    ];

    // ساخت توکن JWT
    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    // حذف رکورد otp_requests پس از موفقیت
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    echo json_encode([
        'status' => true,
        'message' => 'ورود موفقیت‌آمیز بود.',
        'token' => $jwt
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
