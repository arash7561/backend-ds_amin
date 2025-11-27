<?php

// ============================
//   CORS
// ============================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================
//   اتصال به دیتابیس
// ============================
require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();

// لود ملی‌پیامک
require_once __DIR__ . '/../vendor/autoload.php';
use Melipayamak\MelipayamakApi;

// ============================
//   دریافت ورودی
// ============================
$raw = file_get_contents("php://input");
$data = json_decode($raw);

$user_mobile = trim($data->mobile ?? '');
$message     = trim($data->message ?? '');

if (empty($user_mobile) || !preg_match('/^09\d{9}$/', $user_mobile)) {
    echo json_encode(['status' => false, 'message' => 'شماره مشتری نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($message)) {
    echo json_encode(['status' => false, 'message' => 'متن پیام الزامی است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {

    // ============================
    //   ذخیره پیام در دیتابیس
    // ============================
    $insert = $conn->prepare("
        INSERT INTO messages (mobile, message, created_at)
        VALUES (:mobile, :message, NOW())
    ");
    $insert->execute([
        ':mobile'  => $user_mobile,
        ':message' => $message
    ]);

    // ============================
    //   دریافت شماره ادمین از DB
    // ============================
    $stmt = $conn->prepare("SELECT mobile FROM admin_users WHERE role_id = '1' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || empty($admin['mobile'])) {
        echo json_encode(['status' => false, 'message' => 'شماره ادمین یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $admin_mobile = $admin['mobile'];

    // ============================
    //   ارسال پیامک با ملی‌پیامک
    // ============================
    $username = '9128375080';
    $password = '8T05B';
    $from     = '50002710065080';

    $text = "پیام جدید از مشتری:
شماره: $user_mobile
متن: $message";

    try {
        $api = new MelipayamakApi($username, $password);
        $sms = $api->sms();
        $sms->send($admin_mobile, $from, $text);
    } catch (Exception $e) {
        error_log("SMS Error: " . $e->getMessage());
    }

    echo json_encode([
        'status' => true,
        'message' => 'پیام شما با موفقیت ارسال و ذخیره شد.'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
