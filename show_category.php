<?php
require_once 'db_connection.php';

// نمایش خطاها (برای توسعه)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// گرفتن همه دسته‌ها
$stmt = $conn->query("SELECT id, name, slug, image, parent_id FROM categories WHERE status = 'active'");
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
header("Content-Type: application/json; charset=utf-8");
echo json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);






