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
require_once __DIR__ . '/../jwt_utils.php';

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

// بررسی JWT
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$userId = null;

if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $jwt = $matches[1];
    $authResult = verify_jwt_token($jwt);
    if ($authResult['valid']) {
        $userId = $authResult['uid'];
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    // بررسی اینکه آیا کاربر بازاریاب است
    $stmt = $conn->prepare("SELECT id, user_id, code FROM affiliates WHERE user_id = ?");
    $stmt->execute([$userId]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$affiliate) {
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'شما بازاریاب نیستید'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $affiliateId = $affiliate['id']; // id از جدول affiliates
    $affiliateUserId = $affiliate['user_id']; // user_id از جدول affiliates

    // بررسی وجود ستون‌های affiliate_id و parent_affiliate_id در جدول users
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'affiliate_id'");
    $hasAffiliateIdColumn = $stmt->fetch();
    
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'parent_affiliate_id'");
    $hasParentAffiliateIdColumn = $stmt->fetch();
    
    // دریافت لیست کاربران زیرمجموعه
    $subUsers = [];
    
    // اول سعی می‌کنیم با affiliate_id (که باید id از affiliates باشد - مثل subsets.php)
    if ($hasAffiliateIdColumn) {
        error_log("Trying affiliate_id column with affiliate_id: " . $affiliateId);
        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.name,
                u.mobile,
                u.created_at,
                COUNT(DISTINCT o.id) AS order_count,
                COALESCE(SUM(o.total_price), 0) AS total_orders_amount
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
            WHERE u.affiliate_id = ?
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$affiliateId]);
        $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Found " . count($subUsers) . " sub users with affiliate_id = " . $affiliateId);
        
        // اگر با affiliate_id چیزی پیدا نشد، سعی می‌کنیم با user_id (که ممکن است در affiliate_id ذخیره شده باشد)
        if (count($subUsers) === 0 && $hasAffiliateIdColumn) {
            error_log("No results with affiliate_id, trying with user_id: " . $affiliateUserId);
            $stmt = $conn->prepare("
                SELECT 
                    u.id,
                    u.name,
                    u.mobile,
                    u.created_at,
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(o.total_price), 0) AS total_orders_amount
                FROM users u
                LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
                WHERE u.affiliate_id = ?
                GROUP BY u.id
                ORDER BY u.created_at DESC
            ");
            $stmt->execute([$affiliateUserId]);
            $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Found " . count($subUsers) . " sub users with affiliate_id = " . $affiliateUserId);
        }
    }
    
    // اگر هنوز چیزی پیدا نشد، بررسی parent_affiliate_id
    if (count($subUsers) === 0 && $hasParentAffiliateIdColumn) {
        error_log("Trying parent_affiliate_id column with user_id: " . $affiliateUserId);
        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.name,
                u.mobile,
                u.created_at,
                COUNT(DISTINCT o.id) AS order_count,
                COALESCE(SUM(o.total_price), 0) AS total_orders_amount
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
            WHERE u.parent_affiliate_id = ?
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$affiliateUserId]);
        $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Found " . count($subUsers) . " sub users with parent_affiliate_id = " . $affiliateUserId);
    }
    
    if (count($subUsers) === 0) {
        error_log("WARNING: No sub users found. Checked: affiliate_id=" . $affiliateId . ", affiliate_id=" . $affiliateUserId . ", parent_affiliate_id=" . $affiliateUserId);
    }

    echo json_encode([
        'status' => true,
        'affiliate_code' => $affiliate['code'],
        'sub_users' => $subUsers,
        'total_sub_users' => count($subUsers)
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("Sub users error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Sub users error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
