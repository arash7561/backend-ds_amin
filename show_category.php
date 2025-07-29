<?php
require_once 'db_connection.php';

// خواندن JSON ورودی
$json = file_get_contents('php://input');
$data = json_decode($json);

// اگر id ارسال نشده بود
if (!isset($data->id)) {
    http_response_code(400);
    echo json_encode(['error' => 'شناسه (id) ارسال نشده است']);
    exit;
}

// گرفتن همه دسته‌ها
$stmt = $conn->query("SELECT id, name, slug, parent_id FROM categories WHERE status = 'active'");
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

$tree = buildCategoryTree($categories);
echo json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);





