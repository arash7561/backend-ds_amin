<?php

require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
header("Content-Type: application/json; charset=utf-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

// خواندن مقادیر
$id = $data->id ?? null;
$name = $data->name ?? '';
$mobile = $data->mobile ?? '';

// اعتبارسنجی
if (empty($id)) {
    echo json_encode(['status' => false, 'message' => 'شناسه کاربر (id) ارسال نشده است']);
    exit;
}

// اجرای UPDATE فقط برای فیلدهای ضروری
$query = "UPDATE users SET name = ?, mobile = ? WHERE id = ?";
$stmt = $conn->prepare($query);
$result = $stmt->execute([$name, $mobile, $id]);

if ($result) {
    echo json_encode(['status' => true, 'message' => 'اطلاعات با موفقیت بروزرسانی شد']);
} else {
    echo json_encode(['status' => false, 'message' => 'خطا در بروزرسانی اطلاعات']);
}
