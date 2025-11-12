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

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$data = [];

if (stripos($contentType, 'multipart/form-data') !== false) {
    $data = $_POST;
} else {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
}

$title = trim($data['title'] ?? '');
$excerpt = trim($data['excerpt'] ?? '');
$content = $data['content'] ?? '';
$slug = trim($data['slug'] ?? '');
$tag = trim($data['tag'] ?? '');
$category = trim($data['category'] ?? '');
$author = trim($data['author'] ?? '');
$readTime = trim($data['read_time'] ?? '');
$date = trim($data['date'] ?? '');
$status = isset($data['status']) ? (int)$data['status'] : 1;
$metaDescription = trim($data['meta_description'] ?? '');
$imagePath = trim($data['image'] ?? '');

$errors = [];

if ($title === '') {
    $errors[] = 'عنوان مقاله الزامی است.';
}
if ($excerpt === '') {
    $errors[] = 'خلاصه مقاله الزامی است.';
}
if ($content === '') {
    $errors[] = 'محتوای مقاله الزامی است.';
}

if (!empty($date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = 'فرمت تاریخ معتبر نیست. (YYYY-MM-DD)';
}

if (!in_array($status, [0, 1], true)) {
    $errors[] = 'وضعیت مقاله نامعتبر است.';
}

if (!empty($errors)) {
    echo json_encode([
        'status' => false,
        'message' => implode(' | ', $errors),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($slug === '') {
    $slug = preg_replace('/\s+/u', '-', $title);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = preg_replace('/[^A-Za-z0-9\-\p{Arabic}]/u', '', $slug);
    $slug = trim($slug, '-');
}

if ($slug === '') {
    $slug = uniqid('post-');
}

// Ensure slug uniqueness
$baseSlug = $slug;
$counter = 1;
$slugCheck = $conn->prepare("SELECT COUNT(*) FROM blog_posts WHERE slug = ?");
while (true) {
    $slugCheck->execute([$slug]);
    if ((int)$slugCheck->fetchColumn() === 0) {
        break;
    }
    $counter++;
    $slug = $baseSlug . '-' . $counter;
}

// Handle image upload
if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../../uploads/blog/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $tmpName = $_FILES['image']['tmp_name'];
    $originalName = basename($_FILES['image']['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        echo json_encode([
            'status' => false,
            'message' => 'فرمت تصویر پشتیبانی نمی‌شود.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newFileName = uniqid('blog_', true) . '.' . $extension;
    $destination = $uploadDir . $newFileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        if (!copy($tmpName, $destination)) {
            echo json_encode([
                'status' => false,
                'message' => 'آپلود تصویر با خطا مواجه شد.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $imagePath = 'uploads/blog/' . $newFileName;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO blog_posts
            (title, slug, excerpt, content, image, tag, category, author, read_time, date, status, meta_description)
        VALUES
            (:title, :slug, :excerpt, :content, :image, :tag, :category, :author, :read_time, :date, :status, :meta_description)
    ");

    $stmt->execute([
        ':title' => $title,
        ':slug' => $slug,
        ':excerpt' => $excerpt,
        ':content' => $content,
        ':image' => $imagePath !== '' ? $imagePath : null,
        ':tag' => $tag !== '' ? $tag : null,
        ':category' => $category !== '' ? $category : null,
        ':author' => $author !== '' ? $author : null,
        ':read_time' => $readTime !== '' ? $readTime : null,
        ':date' => $date !== '' ? $date : null,
        ':status' => $status,
        ':meta_description' => $metaDescription !== '' ? $metaDescription : null,
    ]);

    $postId = (int)$conn->lastInsertId();

    echo json_encode([
        'status' => true,
        'message' => 'مقاله با موفقیت ثبت شد.',
        'data' => [
            'id' => $postId,
            'slug' => $slug,
            'image' => $imagePath !== '' ? $imagePath : null,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

