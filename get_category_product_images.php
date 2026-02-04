<?php
/**
 * API Endpoint: Get product images from a specific category
 * Returns images from products in the selected category
 */

require_once __DIR__ . '/db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $category_id = $_GET['category_id'] ?? null;
    
    if (!$category_id || !is_numeric($category_id)) {
        echo json_encode([
            'error' => true,
            'message' => 'شناسه دسته‌بندی معتبر نیست',
            'images' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Get all product images from this category
    // Include products from subcategories as well
    $sql = "
        SELECT DISTINCT 
            products.id,
            products.title,
            products.image,
            products.slug
        FROM products
        WHERE products.cat_id = :category_id
          AND products.status = 1
          AND products.image IS NOT NULL
          AND products.image != ''
          AND TRIM(products.image) != ''
        ORDER BY products.id DESC
        LIMIT 50
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':category_id' => $category_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Extract unique images
    $images = [];
    $seenImages = [];
    
    foreach ($products as $product) {
        $image = trim($product['image']);
        if (!empty($image) && !in_array($image, $seenImages)) {
            $seenImages[] = $image;
            $images[] = [
                'url' => $image,
                'product_id' => $product['id'],
                'product_title' => $product['title'],
                'product_slug' => $product['slug']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'category_id' => $category_id,
        'count' => count($images),
        'images' => $images
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'خطا در دریافت تصاویر: ' . $e->getMessage(),
        'images' => []
    ], JSON_UNESCAPED_UNICODE);
}
