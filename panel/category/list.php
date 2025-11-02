<?php
require_once '../../db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // گرفتن همه دسته‌ها (فعال و غیرفعال) برای پنل ادمین
    $stmt = $conn->query("SELECT id, name, slug, image, description, parent_id, status FROM categories ORDER BY name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ساخت درخت دسته‌بندی
    function buildCategoryTree(array $categories, $parentId = null) {
        $branch = [];
        foreach ($categories as $category) {
            $catParentId = $category['parent_id'];
            // تبدیل null و 0 و '0' به null برای مقایسه
            if (($catParentId === null || $catParentId === 0 || $catParentId === '0') && $parentId === null) {
                $children = buildCategoryTree($categories, $category['id']);
                if (!empty($children)) {
                    $category['children'] = $children;
                }
                $branch[] = $category;
            } elseif ($catParentId == $parentId) {
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
    echo json_encode($tree, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'error' => true,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => 'خطای عمومی: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
