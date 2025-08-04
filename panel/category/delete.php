<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json);

$category_id = $data->id ?? null;

if (!$category_id || !is_numeric($category_id)) {
    echo json_encode(['status' => false, 'message' => 'آیدی دسته بندی معتبر نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود محصول
    $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch();

    if (!$category) {
        echo json_encode(['status' => false, 'message' => 'دسته بندی مورد نظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف محصول
    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
    $deleted = $stmt->execute([$category_id]);

    if ($deleted) {
        echo json_encode(['status' => true, 'message' => 'دسته بندی با موفقیت حذف شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'حذف دسته بندی با مشکل مواجه شد.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
