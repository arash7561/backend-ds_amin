<?php
// CORS headers - باید در ابتدا باشد
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

require_once '../db_connection.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json);

$register_token = trim($data->register_token ?? '');
$otp_code = trim($data->otp_code ?? '');

if (empty($register_token) || empty($otp_code)) {
    echo json_encode(['status' => false, 'message' => 'توکن و کد تایید الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

    $mobile = $request['mobile'];

    // تولید JWT token
    $payload = [
        'mobile' => $mobile,
        'iat' => time(),
        'exp' => time() + (24 * 60 * 60) // 24 ساعت
    ];
    
    $jwt_token = bin2hex(random_bytes(32)); // توکن ساده برای تست

    // حذف درخواست OTP
    $stmt = $conn->prepare("DELETE FROM otp_requests WHERE id = ?");
    $stmt->execute([$request['id']]);

    echo json_encode([
        'status' => true, 
        'message' => 'ورود موفقیت آمیز بود.',
        'token' => $jwt_token
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>