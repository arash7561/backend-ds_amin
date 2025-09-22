<?php
require_once '../../db_connection.php';
$conn = getPDO();
header('Content-Type: application/json');

try {
    // دریافت محصولات با اطلاعات دسته‌بندی
    $stmt = $conn->query("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.cat_id = c.id 
        ORDER BY p.id DESC
    ");
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // پردازش داده‌ها برای ارسال
    $processedProducts = [];
    foreach ($products as $product) {
        // تبدیل custom_specifications از JSON
        $customSpecs = [];
        if (!empty($product['custom_specifications'])) {
            $decoded = json_decode($product['custom_specifications'], true);
            if (is_array($decoded)) {
                $customSpecs = $decoded;
            }
        }
        
        // تبدیل dimensions از JSON
        $dimensions = [];
        if (!empty($product['dimensions'])) {
            $decoded = json_decode($product['dimensions'], true);
            if (is_array($decoded)) {
                $dimensions = $decoded;
            }
        }
        
        $processedProducts[] = [
            'id' => (int)$product['id'],
            'title' => $product['title'],
            'description' => $product['description'],
            'slug' => $product['slug'],
            'cat_id' => (int)$product['cat_id'],
            'category_name' => $product['category_name'],
            'status' => $product['status'],
            'image' => $product['image'],
            'stock' => (int)$product['stock'],
            'price' => (int)$product['price'],
            'discount_price' => $product['discount_price'] ? (int)$product['discount_price'] : null,
            'discount_percent' => $product['discount_percent'] ? (float)$product['discount_percent'] : 0,
            'views' => (int)$product['views'],
            'size' => $product['size'],
            'type' => $product['type'],
            'brand' => $product['brand'],
            'line_count' => $product['line_count'] ? (int)$product['line_count'] : null,
            'grade' => $product['grade'],
            'half_finished' => (int)$product['half_finished'],
            'custom_specifications' => $customSpecs,
            'dimensions' => $dimensions,
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at']
        ];
    }
    
    echo json_encode($processedProducts, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
