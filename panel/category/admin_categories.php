<?php
require_once __DIR__ . '/../../db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // گرفتن همه دسته‌ها (فعال و غیرفعال) برای پنل ادمین
    $stmt = $conn->query("SELECT id, name, slug, image, parent_id, status FROM categories");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ساخت درخت دسته‌بندی
    function buildCategoryTree(array $categories, $parentId = null) {
        $branch = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = buildCategoryTree($categories, $category['id']);
                if (!empty($children)) {
                    $category['children'] = $children;
                }
                $branch[] = $category;
            }
        }
        return $branch;
    }

    // ساخت درخت و ارسال خروجی
    $tree = buildCategoryTree($categories);
    echo json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

?>