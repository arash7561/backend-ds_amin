<?php
/**
 * اصلاح محصولاتی که status خالی دارند
 * یک بار اجرا کنید: GET یا POST به این فایل
 */
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

require_once '../../db_connection.php';
$conn = getPDO();

try {
    // محصولاتی که status خالی یا null دارند را به 'active' تغییر بده
    $stmt = $conn->prepare("UPDATE products SET status = 'active' WHERE status IS NULL OR status = '' OR TRIM(status) = ''");
    $stmt->execute();
    $count = $stmt->rowCount();

    echo json_encode([
        'status' => true,
        'message' => "تعداد {$count} محصول با وضعیت خالی به 'فعال' تغییر یافت.",
        'updated_count' => $count
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode([
        'status' => false,
        'message' => 'خطا: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
