<?php
/**
 * API Endpoint: Get all product slugs
 * 
 * This endpoint is optimized for Next.js generateStaticParams()
 * It returns only slugs (no full product data) for faster build times
 * 
 * Usage: GET /api/get_product_slugs.php
 * Returns: JSON array of slugs: ["slug1", "slug2", ...]
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
    // Query to get ALL slugs from products
    // For static export, we need ALL slugs, not just active ones
    // This is much faster than fetching all product data
    $sql = "
        SELECT slug 
        FROM products 
        WHERE slug IS NOT NULL 
        AND slug != '' 
        AND TRIM(slug) != ''
        ORDER BY id ASC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Extract slugs into a simple array
    $slugs = [];
    foreach ($results as $row) {
        if (isset($row['slug'])) {
            $trimmedSlug = trim($row['slug']);
            if ($trimmedSlug !== '') {
                $slugs[] = $trimmedSlug;
            }
        }
    }
    
    // Remove duplicates (in case there are any)
    $slugs = array_unique($slugs);
    $slugs = array_values($slugs); // Re-index array
    
    // Log for debugging
    error_log("get_product_slugs.php: Found " . count($slugs) . " unique slugs");
    
    // Verify problematic slug exists
    $problematicSlug = "مته-الماسه-7160";
    $hasProblematicSlug = in_array($problematicSlug, $slugs);
    if (!$hasProblematicSlug) {
        error_log("get_product_slugs.php: WARNING - Missing slug: " . $problematicSlug);
        error_log("get_product_slugs.php: First 10 slugs: " . implode(", ", array_slice($slugs, 0, 10)));
    }
    
    // Return as JSON array
    // CRITICAL: Return slugs as-is (decoded) from database
    // Next.js will handle URL encoding automatically
    echo json_encode($slugs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'خطا در دریافت slug ها: ' . $e->getMessage(),
        'slugs' => [] // Return empty array on error
    ], JSON_UNESCAPED_UNICODE);
}
