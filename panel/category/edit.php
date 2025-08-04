<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json);

$name = trim($data->name ?? '');
$description = trim($data->description ?? '');
$slug = trim($data->slug ?? '');
$parent_id = $data->parent_id ?? null;
$status = $data->status ?? null;
$image = trim($data->image ?? '');

$errors = [];

if ($name === '') $errors[] = 'عنوان خالی است';
if ($description === '') $errors[] = 'توضیحات خالی است';
if ($slug === '') $errors[] = 'اسلاگ خالی است';
if (!isset($parent_id)) $errors[] = 'دسته بندی خالی است';
if (!in_array($status, [0, 1], true)) $errors[] = 'وضعیت نامعتبر است';
if ($image === '') $errors[] = 'تصویر خالی است';


if (!empty($errors)) {
    echo json_encode(['status' => false, 'message' => implode(' | ', $errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بررسی وجود محصول با slug داده شده
    $stmt = $conn->prepare("SELECT id FROM categories WHERE slug = ?");
    $stmt->execute([$slug]);
    $category = $stmt->fetch();

    if (!$category) {
        echo json_encode(['status' => false, 'message' => 'دسته بندی با این اسلاگ پیدا نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // آپدیت محصول
    $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ?, parent_id = ?, status = ?, image = ? WHERE slug = ?");
    $result = $stmt->execute([$name, $description, $parent_id, $status, $image, $slug]);

    if ($result) {
        echo json_encode(['status' => true, 'message' => 'دسته بندی با موفقیت ویرایش شد.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در ویرایش دسته بندی.'], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
