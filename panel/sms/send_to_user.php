<?php
/**
 * ارسال پیامک به یک کاربر خاص توسط ادمین (از طریق ملی‌پیامک)
 * POST: user_id (یا mobile) + message
 */

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Melipayamak\MelipayamakApi;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست POST مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = isset($input['user_id']) ? (int) $input['user_id'] : null;
$mobile = isset($input['mobile']) ? trim(preg_replace('/\D/', '', $input['mobile'])) : null;
$message = isset($input['message']) ? trim($input['message']) : '';

if (empty($message)) {
    echo json_encode(['status' => false, 'message' => 'متن پیام الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($message) > 500) {
    echo json_encode(['status' => false, 'message' => 'طول پیام حداکثر ۵۰۰ کاراکتر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    if (!$mobile && $userId) {
        $stmt = $conn->prepare("SELECT mobile, name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user['mobile'])) {
            echo json_encode(['status' => false, 'message' => 'کاربر یافت نشد یا شماره موبایل ندارد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $mobile = $user['mobile'];
    }

    if (empty($mobile) || !preg_match('/^09\d{9}$/', $mobile)) {
        echo json_encode(['status' => false, 'message' => 'شماره موبایل معتبر نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $username = '9128375080';
    $password = '8T05B';
    $from = '50002710065080';

    $api = new MelipayamakApi($username, $password);
    $sms = $api->sms();
    $sms->send($mobile, $from, $message);

    echo json_encode([
        'status' => true,
        'message' => 'پیام با موفقیت ارسال شد.',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("SMS send_to_user error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در ارسال پیام: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
