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

// Load autoload.php from api/vendor directory
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    $autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Fallback for getallheaders() in WAMP
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
if (!function_exists('getAuthorizationHeader')) {
    function getAuthorizationHeader() {
        $headers = [];
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            if (isset($allHeaders['Authorization'])) {
                $headers['Authorization'] = $allHeaders['Authorization'];
            }
        }
        return $headers['Authorization'] ?? '';
    }
}

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
        echo json_encode(['status' => false, 'message' => 'دسترسی غیرمجاز. فقط ادمین می‌تواند زیرمجموعه‌ها را مشاهده کند'], JSON_UNESCAPED_UNICODE);
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
    error_log("JWT decode error in admin_sub_users.php: " . $e->getMessage());
    http_response_code(401);
    echo json_encode(['status' => false, 'message' => 'احراز هویت ناموفق: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// دریافت affiliate_id از query parameter
$affiliateUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

if (!$affiliateUserId) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شناسه بازاریاب الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    // بررسی اینکه آیا این کاربر بازاریاب است
    $stmt = $conn->prepare("SELECT id, user_id, code FROM affiliates WHERE user_id = ?");
    $stmt->execute([$affiliateUserId]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$affiliate) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'بازاریاب یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $affiliateId = $affiliate['id'];
    $affiliateUser = $affiliate['user_id'];

    // بررسی وجود ستون affiliate_id در جدول users
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'affiliate_id'");
    $hasAffiliateIdColumn = $stmt->fetch();
    
    $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'parent_affiliate_id'");
    $hasParentAffiliateIdColumn = $stmt->fetch();

    // دریافت لیست کاربران زیرمجموعه
    $subUsers = [];
    
    if ($hasAffiliateIdColumn) {
        // در verify_otp.php، affiliate_id در جدول users برابر با user_id از جدول affiliates ذخیره می‌شود
        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.name,
                u.mobile,
                u.created_at,
                COUNT(DISTINCT o.id) AS order_count
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
            WHERE u.affiliate_id = ?
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$affiliateUser]);
        $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // محاسبه total_orders_amount برای هر کاربر
        foreach ($subUsers as &$user) {
            $totalAmount = 0;
            $orderStmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND status != 'cancelled'");
            $orderStmt->execute([$user['id']]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as $order) {
                $paymentStmt = $conn->prepare("SELECT amount FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
                $paymentStmt->execute([$order['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($payment && !empty($payment['amount'])) {
                    $totalAmount += (float)$payment['amount'];
                } else {
                    $itemStmt = $conn->prepare("
                        SELECT oi.quantity, p.price, p.discount_price 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?
                    ");
                    $itemStmt->execute([$order['id']]);
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($items as $item) {
                        $price = !empty($item['discount_price']) && $item['discount_price'] > 0 
                                 ? (float)$item['discount_price'] 
                                 : (float)$item['price'];
                        $totalAmount += $price * (int)$item['quantity'];
                    }
                }
            }
            $user['total_orders_amount'] = $totalAmount;
        }
        unset($user);
    } elseif ($hasParentAffiliateIdColumn) {
        $stmt = $conn->prepare("
            SELECT 
                u.id,
                u.name,
                u.mobile,
                u.created_at,
                COUNT(DISTINCT o.id) AS order_count
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id AND o.status != 'cancelled'
            WHERE u.parent_affiliate_id = ?
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$affiliateUser]);
        $subUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // محاسبه total_orders_amount برای هر کاربر
        foreach ($subUsers as &$user) {
            $totalAmount = 0;
            $orderStmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND status != 'cancelled'");
            $orderStmt->execute([$user['id']]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as $order) {
                $paymentStmt = $conn->prepare("SELECT amount FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
                $paymentStmt->execute([$order['id']]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($payment && !empty($payment['amount'])) {
                    $totalAmount += (float)$payment['amount'];
                } else {
                    $itemStmt = $conn->prepare("
                        SELECT oi.quantity, p.price, p.discount_price 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?
                    ");
                    $itemStmt->execute([$order['id']]);
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($items as $item) {
                        $price = !empty($item['discount_price']) && $item['discount_price'] > 0 
                                 ? (float)$item['discount_price'] 
                                 : (float)$item['price'];
                        $totalAmount += $price * (int)$item['quantity'];
                    }
                }
            }
            $user['total_orders_amount'] = $totalAmount;
        }
        unset($user);
    }

    echo json_encode([
        'status' => true,
        'affiliate_code' => $affiliate['code'],
        'affiliate_user_id' => $affiliateUser,
        'sub_users' => $subUsers,
        'total_sub_users' => count($subUsers)
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log("Admin sub users error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Admin sub users error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای غیرمنتظره: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
