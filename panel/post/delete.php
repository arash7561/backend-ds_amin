<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json);

$product_id = $data->id ?? null;

if (!$product_id || !is_numeric($product_id)) {
    echo json_encode(['status' => false, 'message' => 'آیدی محصول معتبر نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود محصول
    $stmt = $conn->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode(['status' => false, 'message' => 'محصول مورد نظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف محصول
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $deleted = $stmt->execute([$product_id]);

    if ($deleted) {
        echo json_encode(['status' => true, 'message' => 'محصول با موفقیت حذف شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'حذف محصول با مشکل مواجه شد.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
