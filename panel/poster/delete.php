<?php

require_once __DIR__ . '/../../db_connection.php';
$conn = getPDO();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json);

$poster_id = $data->id ?? null;

if (!$poster_id || !is_numeric($poster_id)) {
    echo json_encode(['status' => false, 'message' => 'آیدی پوستر معتبر نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود محصول
    $stmt = $conn->prepare("SELECT id FROM posters WHERE id = ?");
    $stmt->execute([$poster_id]);
    $poster = $stmt->fetch();

    if (!$poster) {
        echo json_encode(['status' => false, 'message' => 'پوستر مورد نظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف محصول
    $stmt = $conn->prepare("DELETE FROM posters WHERE id = ?");
    $deleted = $stmt->execute([$poster_id]);

    if ($deleted) {
        echo json_encode(['status' => true, 'message' => 'پوستر با موفقیت حذف شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'حذف پوستر با مشکل مواجه شد.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);        
}
