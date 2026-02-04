<?php
/**
 * Script to generate slugs for products that don't have slugs
 * Run this once to generate slugs for all products without slugs
 */

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

/**
 * Generate slug from title (same as category slug generation)
 */
function generateSlug($title) {
    if (empty($title)) return '';
    
    // Convert Persian/Arabic numbers to English
    $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabicNumbers = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    
    $slug = $title;
    
    // Replace Persian numbers
    foreach ($persianNumbers as $index => $persian) {
        $slug = str_replace($persian, $englishNumbers[$index], $slug);
    }
    
    // Replace Arabic numbers
    foreach ($arabicNumbers as $index => $arabic) {
        $slug = str_replace($arabic, $englishNumbers[$index], $slug);
    }
    
    // Remove special characters, keep only Persian/Arabic/English letters, numbers, spaces, and hyphens
    $slug = preg_replace('/[^\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}a-zA-Z0-9\s-]/u', ' ', $slug);
    
    // Replace multiple spaces with single space
    $slug = preg_replace('/\s+/', ' ', $slug);
    
    // Replace spaces with hyphens
    $slug = str_replace(' ', '-', $slug);
    
    // Remove multiple consecutive hyphens
    $slug = preg_replace('/-+/', '-', $slug);
    
    // Remove leading and trailing hyphens
    $slug = trim($slug, '-');
    
    return $slug;
}

try {
    // Get all products - we'll check each one
    $stmt = $conn->prepare("
        SELECT id, title, slug 
        FROM products 
        ORDER BY id ASC
    ");
    $stmt->execute();
    $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter products that need slugs
    $products = [];
    foreach ($allProducts as $product) {
        $slug = $product['slug'] ?? '';
        $slug = trim($slug);
        
        // Check if slug is empty or just numbers (like "238")
        // Also check if slug is the same as ID (which means it's not a real slug)
        if (empty($slug) || preg_match('/^[0-9]+$/', $slug) || $slug == $product['id']) {
            $products[] = $product;
        }
    }
    
    $results = [
        'total' => count($products),
        'updated' => 0,
        'failed' => 0,
        'details' => []
    ];
    
    foreach ($products as $product) {
        $productId = $product['id'];
        $title = $product['title'];
        
        if (empty($title)) {
            $results['failed']++;
            $results['details'][] = [
                'id' => $productId,
                'status' => 'failed',
                'reason' => 'Title is empty'
            ];
            continue;
        }
        
        // Generate base slug from title
        $baseSlug = generateSlug($title);
        
        if (empty($baseSlug)) {
            $results['failed']++;
            $results['details'][] = [
                'id' => $productId,
                'status' => 'failed',
                'reason' => 'Generated slug is empty'
            ];
            continue;
        }
        
        // Add random 4-digit number to ensure uniqueness
        $randomSuffix = rand(1000, 9999);
        $slug = $baseSlug . '-' . $randomSuffix;
        
        // Check if slug already exists
        $checkStmt = $conn->prepare("SELECT id FROM products WHERE slug = :slug AND id != :id");
        $checkStmt->execute([':slug' => $slug, ':id' => $productId]);
        
        // If exists, try with different random suffix
        $attempts = 0;
        while ($checkStmt->rowCount() > 0 && $attempts < 10) {
            $randomSuffix = rand(1000, 9999);
            $slug = $baseSlug . '-' . $randomSuffix;
            $checkStmt->execute([':slug' => $slug, ':id' => $productId]);
            $attempts++;
        }
        
        if ($checkStmt->rowCount() > 0) {
            $results['failed']++;
            $results['details'][] = [
                'id' => $productId,
                'status' => 'failed',
                'reason' => 'Could not generate unique slug after 10 attempts'
            ];
            continue;
        }
        
        // Update product with new slug
        $updateStmt = $conn->prepare("UPDATE products SET slug = :slug WHERE id = :id");
        $updateStmt->execute([':slug' => $slug, ':id' => $productId]);
        
        $results['updated']++;
        $results['details'][] = [
            'id' => $productId,
            'title' => $title,
            'slug' => $slug,
            'status' => 'success'
        ];
    }
    
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'خطا در تولید slug: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
