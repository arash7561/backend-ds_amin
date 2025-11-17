<?php
// Load autoload.php from api/vendor directory
$autoloadPath = __DIR__ . '/../../vendor/autoload.php'; // api/vendor/autoload.php
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // Fallback: try root vendor directory
    $autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    } else {
        throw new Exception('vendor/autoload.php not found. Please run composer install.');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkJWT() {
    // CORS headers
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');

    // Bypass for localhost dev mode
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocalhost = ($clientIp === '127.0.0.1' || $clientIp === '::1' || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
    if ($isLocalhost && isset($_GET['dev'])) {
        return ['id' => 1, 'role' => 'admin'];
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
    }
    if (!$authHeader) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن ارسال نشده است']);
        exit;
    }
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'ساختار توکن اشتباه است']);
        exit;
    }

    $jwt = $matches[1];
    $secretKey = $_ENV['JWT_SECRET'] ?? 'secret_key';

    try {
        $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
        return (array)$decoded; // اطلاعات توکن (مثل id و role)
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر یا منقضی شده است']);
        exit;
    }
}
