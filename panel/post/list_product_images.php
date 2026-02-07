<?php
/**
 * لیست تصاویر موجود محصولات در سایت
 * برای استفاده در وارد کردن از اکسل - بدون نیاز به آپلود مجدد
 */
require_once '../../db_connection.php';
$conn = getPDO();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $stmt = $conn->query("
        SELECT DISTINCT image, id, title 
        FROM products 
        WHERE image IS NOT NULL AND image != '' AND TRIM(image) != ''
        ORDER BY id DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $images = [];
    $seen = [];
    foreach ($rows as $r) {
        $path = trim($r['image']);
        if (empty($path) || isset($seen[$path])) continue;
        $seen[$path] = true;
        $images[] = [
            'path' => $path,
            'filename' => basename($path),
            'product_id' => $r['id'],
            'product_title' => $r['title'] ?? '',
        ];
    }

    echo json_encode([
        'status' => true,
        'images' => $images,
        'count' => count($images),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'status' => false,
        'message' => $e->getMessage(),
        'images' => [],
    ], JSON_UNESCAPED_UNICODE);
}
