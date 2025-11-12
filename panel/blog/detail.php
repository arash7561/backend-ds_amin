<?php
require_once '../../db_connection.php';

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'message' => 'شناسه مقاله نامعتبر است.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();

    $stmt = $conn->prepare("
        SELECT id, title, slug, excerpt, content, image, tag, category, author,
               read_time, date, status, views, meta_description, created_at, updated_at
        FROM blog_posts
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        http_response_code(404);
        echo json_encode([
            'status' => false,
            'message' => 'مقاله یافت نشد.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $post['id'] = (int)$post['id'];
    $post['status'] = isset($post['status']) ? (int)$post['status'] : 0;
    $post['views'] = isset($post['views']) ? (int)$post['views'] : 0;

    echo json_encode([
        'status' => true,
        'data' => $post,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

