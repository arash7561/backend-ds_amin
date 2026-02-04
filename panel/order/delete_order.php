<?php
/**
 * حذف فاکتور (سفارش) توسط ادمین
 * POST: { "order_id": number } یا { "id": number }
 */

// CORS headers - باید قبل از هر خروجی باشند
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => false,
        'message' => 'Method not allowed. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../admin/middleware.php';

try {
    $conn = getPDO();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در اتصال به پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی احراز هویت ادمین
try {
    // Debug: Log authorization header
    $authHeaderDebug = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeaderDebug = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeaderDebug = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    error_log("Delete Order - Auth header present: " . ($authHeaderDebug ? 'YES' : 'NO'));
    error_log("Delete Order - Auth header value: " . ($authHeaderDebug ? substr($authHeaderDebug, 0, 30) . '...' : 'NULL'));
    
    $user = checkJWT();
    error_log("Delete Order - User decoded: " . json_encode($user, JSON_UNESCAPED_UNICODE));
    
    // بررسی اینکه کاربر ادمین است یا نه (aid از login_admin.php یا role از سایر لاگین‌ها)
    if (!isset($user['aid']) && (!isset($user['role']) || $user['role'] !== 'admin')) {
        error_log("Delete Order - User is not admin. User data: " . json_encode($user, JSON_UNESCAPED_UNICODE));
        http_response_code(403);
        echo json_encode([
            'status' => false,
            'message' => 'دسترسی غیرمجاز. فقط ادمین می‌تواند سفارش را حذف کند'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    error_log("Delete Order - Auth exception: " . $e->getMessage());
    error_log("Delete Order - Auth exception trace: " . $e->getTraceAsString());
    http_response_code(401);
    echo json_encode([
        'status' => false,
        'message' => 'احراز هویت ناموفق: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get JSON data from request body
$json = file_get_contents('php://input');
$data = json_decode($json, true); // Use associative array instead of object

// Also check for form data (in case it's sent as form-data)
if (empty($data) && isset($_POST['order_id'])) {
    $data = ['order_id' => $_POST['order_id']];
}
if (empty($data) && isset($_POST['id'])) {
    $data = ['order_id' => $_POST['id']];
}

// پشتیبانی از هر دو کلید order_id و id
$order_id = $data['order_id'] ?? $data['id'] ?? null;

if (!$order_id || !is_numeric($order_id)) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'message' => 'آیدی فاکتور معتبر نیست.',
        'received_id' => $order_id
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Convert to integer
$order_id = (int)$order_id;

try {
    // بررسی وجود سفارش
    $stmt = $conn->prepare("SELECT id, status FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'status' => false,
            'message' => 'فاکتور مورد نظر یافت نشد.',
            'order_id' => $order_id
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // شروع تراکنش برای اطمینان از یکپارچگی داده‌ها
    $conn->beginTransaction();

    // 1. حذف order_items (به دلیل foreign key constraint باید اول حذف شود)
    $stmt = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $deletedItems = $stmt->rowCount();

    // 2. حذف payments مرتبط (اگر وجود دارد)
    $stmt = $conn->prepare("DELETE FROM payments WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $deletedPayments = $stmt->rowCount();

    // 3. حذف سفارش
    $stmt = $conn->prepare("DELETE FROM orders WHERE id = ?");
    $deleted = $stmt->execute([$order_id]);

    if ($deleted && $stmt->rowCount() > 0) {
        // تایید تراکنش
        $conn->commit();
        
        // لاگ کردن عملیات
        $adminId = $user['aid'] ?? $user['id'] ?? 'unknown';
        error_log("Order #$order_id deleted by admin user ID: " . $adminId);
        
        http_response_code(200);
        echo json_encode([
            'status' => true,
            'message' => 'فاکتور با موفقیت حذف شد.',
            'order_id' => $order_id,
            'deleted_items' => $deletedItems,
            'deleted_payments' => $deletedPayments
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'حذف فاکتور با مشکل مواجه شد. ممکن است فاکتور قبلاً حذف شده باشد.',
            'order_id' => $order_id
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    // در صورت خطا، تراکنش را rollback می‌کنیم
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
        'order_id' => $order_id ?? null
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای عمومی: ' . $e->getMessage(),
        'order_id' => $order_id ?? null
    ], JSON_UNESCAPED_UNICODE);
}
