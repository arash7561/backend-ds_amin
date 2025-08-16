<?php

require_once '../db_connection.php';
$conn = getPDO();
header("Content-Type: application/json; charset=utf-8");

$json = file_get_contents('php://input');
$data = json_decode($json);

// خواندن مقادیر
$id = $data->id ?? null;
$address = $data->address ?? '';
$recipients_name = $data->recipients_name ?? '';
$province = $data->province ?? '';
$city = $data->city ?? '';
$postal_code = $data->postal_code ?? '';
$recipients_phone = $data->recipients_phone ?? '';

// اعتبارسنجی
if (empty($id)) {
    echo json_encode(['status' => false, 'message' => 'شناسه کاربر (id) ارسال نشده است']);
    exit;
}
if (empty($address)) {
    echo json_encode(['status' => false, 'message' => 'آدرس خالی است']);
    exit;
}
if (empty($recipients_name)) {
    echo json_encode(['status' => false, 'message' => 'نام و نام خانوادگی گیرنده خالی است']);
    exit;
}
if (empty($province)) {
    echo json_encode(['status' => false, 'message' => 'استان خالی است']);
    exit;
}
if (empty($city)) {
    echo json_encode(['status' => false, 'message' => 'شهر خالی است']);
    exit;
}
if (empty($postal_code)) {
    echo json_encode(['status' => false, 'message' => 'کد پستی خالی است']);
    exit;
}
if (empty($recipients_phone)) {
    echo json_encode(['status' => false, 'message' => 'شماره موبایل گیرنده خالی است']);
    exit;
}

// اجرای UPDATE
$query = "UPDATE users SET address = ?, recipients_name = ?, province = ?, city = ?, postal_code = ?, recipients_phone = ? WHERE id = ?";
$stmt = $conn->prepare($query);
$result = $stmt->execute([$address, $recipients_name, $province, $city, $postal_code, $recipients_phone, $id]);

if ($result) {
    echo json_encode(['status' => true, 'message' => 'اطلاعات با موفقیت ثبت شد']);
} else {
    echo json_encode(['status' => false, 'message' => 'خطا در ذخیره‌سازی اطلاعات']);
}
