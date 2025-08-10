<?php
require_once 'db_connection.php';

// CORS headers
header('Access-Control-Allow-Origin: *'); // یا آدرس دقیق فرانت‌اند خود را قرار دهید
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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

?>