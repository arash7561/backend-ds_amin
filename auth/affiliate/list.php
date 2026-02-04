<?php
// CORS headers
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

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (
    in_array($origin, $allowed_origins) ||
    strpos($origin, 'localhost') !== false ||
    strpos($origin, '127.0.0.1') !== false ||
    strpos($origin, 'aminindpharm.ir') !== false
) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'فقط درخواست GET مجاز است'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Fallback برای getallheaders() در WAMP
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}

// Function to get Authorization header robustly
function getAuthorizationHeader() {
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } else {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            }
        }
    }
    return $authHeader;
}

try {
    // بررسی احراز هویت ادمین
    $authHeader = getAuthorizationHeader();
    
    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن احراز هویت الزامی است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token = $matches[1];
    $secretKey = $_ENV['JWT_SECRET'] ?? 'your-secret-key';

    try {
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
        $user = (array)$decoded;
        
        // بررسی اینکه کاربر ادمین است یا نه
        if (!isset($user['aid']) && (!isset($user['role']) || $user['role'] !== 'admin')) {
            http_response_code(403);
            echo json_encode(['status' => false, 'message' => 'دسترسی غیرمجاز. فقط ادمین می‌تواند لیست بازاریاب‌ها را مشاهده کند'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (\Firebase\JWT\ExpiredException $e) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن منقضی شده است'], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Firebase\JWT\SignatureInvalidException $e) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        error_log("JWT decode error in list.php: " . $e->getMessage());
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    error_log("Auth error in list.php: " . $e->getMessage());
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    $stmt = $conn->query("
        SELECT 
            a.id AS affiliate_id,
            a.user_id,
            a.code AS affiliate_code,
            a.created_at,
            u.name AS user_name,
            u.mobile AS user_mobile
        FROM affiliates a
        LEFT JOIN users u ON u.id = a.user_id
        ORDER BY a.created_at DESC
    ");

    echo json_encode([
        'status' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("Affiliate list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Affiliate list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
