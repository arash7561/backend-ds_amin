<?php
/**
 * API Endpoint: Get ALL product slugs (including inactive)
 * 
 * This endpoint is specifically for Next.js generateStaticParams()
 * Returns all slugs without any filtering
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
    // Get ALL slugs from products table (no status filter)
    // This ensures we get every single slug for static generation
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
    
    // Remove duplicates and re-index
    $slugs = array_values(array_unique($slugs));
    
    // Log for debugging
    error_log("get_all_slugs.php: Found " . count($slugs) . " unique slugs");
    
    // Check for specific problematic slug
    $problematicSlug = "مته-الماسه-7160";
    $hasProblematicSlug = in_array($problematicSlug, $slugs);
    
    if (!$hasProblematicSlug) {
        error_log("get_all_slugs.php: WARNING - Missing slug: " . $problematicSlug);
        error_log("get_all_slugs.php: First 10 slugs: " . implode(", ", array_slice($slugs, 0, 10)));
        
        // Try to find similar slugs
        foreach ($slugs as $slug) {
            if (strpos($slug, 'مته') !== false || strpos($slug, 'الماسه') !== false) {
                error_log("get_all_slugs.php: Found similar slug: " . $slug);
            }
        }
    } else {
        error_log("get_all_slugs.php: ✅ Found problematic slug: " . $problematicSlug);
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
