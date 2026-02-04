<?php
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

require_once '../../db_connection.php';

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

// Get JSON data from request body
$json = file_get_contents('php://input');
$data = json_decode($json, true); // Use associative array instead of object

// Also check for form data (in case it's sent as form-data)
if (empty($data) && isset($_POST['id'])) {
    $data = ['id' => $_POST['id']];
}

$product_id = $data['id'] ?? $data->id ?? null;

if (!$product_id || !is_numeric($product_id)) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'message' => 'آیدی محصول معتبر نیست.',
        'received_id' => $product_id
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Convert to integer
$product_id = (int)$product_id;

try {
    // بررسی وجود محصول
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode([
            'status' => false,
            'message' => 'محصول مورد نظر یافت نشد.',
            'product_id' => $product_id
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف محصول
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $deleted = $stmt->execute([$product_id]);

    if ($deleted && $stmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode([
            'status' => true,
            'message' => 'محصول با موفقیت حذف شد.',
            'product_id' => $product_id
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'حذف محصول با مشکل مواجه شد. ممکن است محصول قبلاً حذف شده باشد.',
            'product_id' => $product_id
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
        'product_id' => $product_id ?? null
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای عمومی: ' . $e->getMessage(),
        'product_id' => $product_id ?? null
    ], JSON_UNESCAPED_UNICODE);
}
