<?php
require_once '../../db_connection.php';

// CORS headers
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
} else {
    header('Access-Control-Allow-Origin: *');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    exit;
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

try {
    $conn = getPDO();

    $stmt = $conn->query("
        SELECT id, title, slug, excerpt, content, image, tag, category, author,
               read_time, date, status, views, meta_description, created_at, updated_at
        FROM blog_posts
        ORDER BY id DESC
    ");

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $normalized = array_map(function ($post) {
        return [
            'id' => isset($post['id']) ? (int)$post['id'] : null,
            'title' => $post['title'] ?? '',
            'slug' => $post['slug'] ?? '',
            'excerpt' => $post['excerpt'] ?? '',
            'content' => $post['content'] ?? '',
            'image' => $post['image'] ?? null,
            'tag' => $post['tag'] ?? null,
            'category' => $post['category'] ?? null,
            'author' => $post['author'] ?? null,
            'read_time' => $post['read_time'] ?? null,
            'date' => $post['date'] ?? null,
            'status' => isset($post['status']) ? (int)$post['status'] : 0,
            'views' => isset($post['views']) ? (int)$post['views'] : 0,
            'meta_description' => $post['meta_description'] ?? null,
            'created_at' => $post['created_at'] ?? null,
            'updated_at' => $post['updated_at'] ?? null,
        ];
    }, $posts ?: []);

    echo json_encode([
        'status' => true,
        'data' => $normalized,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

