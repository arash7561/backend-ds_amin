<?php
require_once __DIR__ . '/db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// نمایش خطاها (فقط برای توسعه)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    // بررسی آیا ID محصول خاصی درخواست شده
    $product_id = $_GET['id'] ?? null;

// گرفتن محصولات همراه با نام دسته‌بندی
$sql = "
SELECT 
    products.id,
    products.title,
    products.description,
    products.slug,
    products.image,
    products.price,
    products.discount_price,
    products.stock,
    products.cat_id,
    products.views,
    products.type,
    products.brand,
    products.line_count,
    products.grade,
    products.half_finished,
    products.dimensions,
    products.status,
    categories.name AS category_name
FROM 
    products
LEFT JOIN 
    categories ON products.cat_id = categories.id
";

// اگر ID محصول خاصی درخواست شده، فیلتر اضافه کن
if ($product_id && is_numeric($product_id)) {
    $sql .= " WHERE products.id = :product_id";
}

$stmt = $conn->prepare($sql);
if ($product_id && is_numeric($product_id)) {
    $stmt->execute([':product_id' => $product_id]);
} else {
    $stmt->execute();
}
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// افزایش تعداد بازدید برای هر محصول
foreach ($products as &$product) {
    $updateStmt = $conn->prepare("UPDATE products SET views = views + 1 WHERE id = ?");
    $updateStmt->execute([$product['id']]);
    // بروزرسانی مقدار بازدید در آرایه خروجی
    $product['views'] += 1;
}

// ارسال خروجی به صورت JSON
header("Content-Type: application/json; charset=utf-8");

if ($products && count($products) > 0) {
    // اگر یک محصول خاص درخواست شده، فقط آن محصول را برگردان
    if ($product_id && is_numeric($product_id)) {
        echo json_encode($products[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} else {
        echo json_encode(["debug" => "هیچ محصولی یافت نشد."], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'خطا در دریافت محصولات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
