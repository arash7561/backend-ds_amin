<?php
require_once '../../db_connection.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => false,
        'message' => 'تنها درخواست POST مجاز است.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'عدم اتصال به پایگاه داده: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    $data = $_POST;
}

$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    echo json_encode([
        'status' => false,
        'message' => 'شناسه مقاله نامعتبر است.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare("SELECT image FROM blog_posts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    http_response_code(404);
    echo json_encode([
        'status' => false,
        'message' => 'مقاله یافت نشد.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $deleteStmt = $conn->prepare("DELETE FROM blog_posts WHERE id = :id");
    $deleteStmt->execute([':id' => $id]);

    if ($deleteStmt->rowCount() > 0 && !empty($existing['image']) && strpos($existing['image'], 'uploads/blog/') === 0) {
        $path = '../../' . $existing['image'];
        if (is_file($path)) {
            @unlink($path);
        }
    }

    echo json_encode([
        'status' => true,
        'message' => 'مقاله با موفقیت حذف شد.',
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

