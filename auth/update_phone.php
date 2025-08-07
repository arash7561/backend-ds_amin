<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function sendResponse($status, $message, $data = null) {
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'فقط درخواست POST مجاز است');
}

// بررسی CSRF Token
$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? '';
if (empty($csrfToken) || strlen($csrfToken) !== 64) {
    sendResponse(false, 'توکن CSRF نامعتبر است');
}

// بررسی Authorization Header
$authHeader = $headers['Authorization'] ?? '';
if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    sendResponse(false, 'توکن احراز هویت یافت نشد');
}

$jwt = $matches[1];
$secretKey = "your-secret-key-2024";

try {
    $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
    $userId = $decoded->user_id;
} catch (Exception $e) {
    sendResponse(false, 'توکن نامعتبر است');
}

$input = json_decode(file_get_contents('php://input'), true);
$phone = trim($input['phone'] ?? '');

if (empty($phone)) {
    sendResponse(false, 'شماره تلفن نمی‌تواند خالی باشد');
}

// اعتبارسنجی شماره تلفن ایرانی
if (!preg_match('/^09[0-9]{9}$/', $phone)) {
    sendResponse(false, 'شماره تلفن باید با 09 شروع شده و 11 رقم باشد');
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // بررسی تکراری نبودن شماره تلفن
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE mobile = ? AND id != ?");
    $checkStmt->execute([$phone, $userId]);
    
    if ($checkStmt->rowCount() > 0) {
        sendResponse(false, 'این شماره تلفن قبلاً ثبت شده است');
    }
    
    $stmt = $pdo->prepare("UPDATE users SET mobile = ? WHERE id = ?");
    $result = $stmt->execute([$phone, $userId]);
    
    if ($result) {
        sendResponse(true, 'شماره تلفن با موفقیت بروزرسانی شد', ['phone' => $phone]);
    } else {
        sendResponse(false, 'خطا در بروزرسانی شماره تلفن');
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    sendResponse(false, 'خطا در ارتباط با پایگاه داده');
}
?>