<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkJWT() {
    // CORS headers
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');

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
