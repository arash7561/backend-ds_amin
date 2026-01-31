<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With");

require_once __DIR__ . '/../../db_connection.php';
$conn = getPDO();

// دریافت داده‌ها
$data = json_decode(file_get_contents("php://input"), true);

// بررسی فیلدهای ضروری
$required = ['user_id', 'address', 'province', 'city', 'postal_code'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        echo json_encode(["status" => "error", "message" => "Missing field: $field"]);
        exit;
    }
}

// مقداردهی
$user_id = (int)$data['user_id'];
$email = $data['email'] ?? null;
$address = trim($data['address']);
$province = trim($data['province']);
$city = trim($data['city']);
$postal_code = trim($data['postal_code']);


// Check if an address already exists for this user_id
$checkSql = "SELECT id FROM addresses WHERE user_id = :user_id LIMIT 1";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->execute([':user_id' => $user_id]);
$existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    // Update existing address row for this user
    $sql = "UPDATE addresses SET
                email = :email,
                address = :address,
                province = :province,
                city = :city,
                postal_code = :postal_code
            WHERE user_id = :user_id";
    $stmt = $conn->prepare($sql);
    $success = $stmt->execute([
        ':email' => $email,
        ':address' => $address,
        ':province' => $province,
        ':city' => $city,
        ':postal_code' => $postal_code,
        ':user_id' => $user_id,
    ]);
} else {
    // Insert new address row
    $sql = "INSERT INTO addresses (user_id, email, address, province, city, postal_code)
            VALUES (:user_id, :email, :address, :province, :city, :postal_code)";
    $stmt = $conn->prepare($sql);
    $success = $stmt->execute([
        ':user_id' => $user_id,
        ':email' => $email,
        ':address' => $address,
        ':province' => $province,
        ':city' => $city,
        ':postal_code' => $postal_code,
    ]);
}

if ($success) {
    echo json_encode(["status" => "success", "message" => "آدرس با موفقیت بروزرسانی شد"] , JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(["status" => "error", "message" => "خطای دیتابیس"] , JSON_UNESCAPED_UNICODE);
}
?>
