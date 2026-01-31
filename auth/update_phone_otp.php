<?php
// CORS headers - Allow from localhost and production domain
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
    'https://aminindpharm.ir',
    'http://aminindpharm.ir'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (in_array($origin, $allowed_origins) || 
    (strpos($origin, 'http://localhost') !== false || 
     strpos($origin, 'http://127.0.0.1') !== false ||
     strpos($origin, 'https://aminindpharm.ir') !== false ||
     strpos($origin, 'http://aminindpharm.ir') !== false)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
header("Content-Type: application/json; charset=UTF-8");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$json = file_get_contents('php://input');
$data = json_decode($json);

$register_token = trim($data->register_token ?? '');
$otp_code = trim($data->otp_code ?? '');

if (empty($register_token) || empty($otp_code)) {
    echo json_encode(['status' => false, 'message' => 'توکن ثبتنام و کد تایید الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get JWT token from Authorization header
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    echo json_encode(['status' => false, 'message' => 'توکن احراز هویت یافت نشد'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $matches[1];

// Decode JWT token
$tokenParts = explode('.', $token);
if (count($tokenParts) !== 3) {
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(base64_decode($tokenParts[1]), true);
if (!$payload || !isset($payload['uid'])) {
    echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = $payload['uid'];

try {
    // جستجو رکورد درخواست OTP با توکن و کد
    $stmt = $conn->prepare("SELECT * FROM otp_requests WHERE register_token = ? AND otp_code = ?");
    $stmt->execute([$register_token, $otp_code]);
    $request = $stmt->fetch();

    if (!$request) {
        echo json_encode(['status' => false, 'message' => 'کد تایید اشتباه است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // چک کردن انقضای کد OTP
    if (strtotime($request['otp_expires_at']) < time()) {
        echo json_encode(['status' => false, 'message' => 'کد تایید منقضی شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newMobile = $request['mobile'];

    // چک کردن وجود شماره جدید برای کاربر دیگر
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ? AND id != ?");
    $stmt->execute([$newMobile, $userId]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        echo json_encode(['status' => false, 'message' => 'این شماره موبایل قبلا ثبت شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بروزرسانی شماره تلفن کاربر فعلی
    $stmt = $conn->prepare("UPDATE users SET mobile = ? WHERE id = ?");
    $result = $stmt->execute([$newMobile, $userId]);

    if ($result) {
        // حذف درخواست OTP
        $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
        $stmt->execute([$request['id']]);

        echo json_encode(['status' => true, 'message' => 'شماره تلفن با موفقیت بروزرسانی شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در بروزرسانی شماره تلفن'], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>