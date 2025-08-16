<?php
require_once __DIR__ . '../../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function checkJWT() {
    header('Content-Type: application/json; charset=utf-8');

    $headers = apache_request_headers();

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن ارسال نشده است']);
        exit;
    }

    $authHeader = $headers['Authorization'];
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
