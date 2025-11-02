<?php
require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'نام کاربری و رمز عبور الزامی است']);
    exit;
}

try {
    $conn = getPDO();

    // پیدا کردن ادمین
    $stmt = $conn->prepare("SELECT id, username, password_hash, role_id FROM admin_users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است']);
        exit;
    }

    // ساخت JWT
    $secretKey = $_ENV['JWT_SECRET'] ?? 'secret_key';
    $issuer = $_ENV['JWT_ISSUER'] ?? 'yourdomain.com';
    $accessTTL = $_ENV['JWT_ACCESS_TTL'] ?? 3600; // یک ساعت

    $payload = [
        'iss' => $issuer,
        'iat' => time(),
        'exp' => time() + $accessTTL,
        'sub' => $admin['id'],
        'aid' => $admin['id'], // شناسه ادمین برای تشخیص در get_user_info
        'role_id' => $admin['role_id'],
        'username' => $admin['username']
    ];

    $jwt = JWT::encode($payload, $secretKey, 'HS256');

    echo json_encode([
        'status' => true,
        'message' => 'ورود موفقیت‌آمیز',
        'token' => $jwt
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()]);
}
