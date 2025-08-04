<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=UTF-8');

$secret_key = 'your-secret-key';  // کلید مخفی JWT

// دریافت هدر Authorization
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!$authHeader) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'توکن ارسال نشده']);
    exit;
}



// استخراج توکن از "Bearer <token>"
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'فرمت توکن اشتباه است']);
    exit;
}

$jwt = $matches[1];

try {
    // اعتبارسنجی و دکد توکن
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $decoded_array = (array) $decoded;

    // تعریف متغیرهای گلوبال
    $auth_user_id = $decoded_array['uid'] ?? null;
    $auth_user_mobile = $decoded_array['mobile'] ?? null;

    // ✅ نمایش اطلاعات کاربر برای تست و اطمینان
    echo json_encode([
        'status' => true,
        'message' => 'توکن معتبر است.',
        'user_id' => $auth_user_id,
        'mobile' => $auth_user_mobile
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر: ' . $e->getMessage()]);
    exit;
}
