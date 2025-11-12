<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

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


// SQL ترکیبی INSERT + UPDATE
$sql = "INSERT INTO addresses (user_id, email, address, province, city, postal_code)
        VALUES (:user_id, :email, :address, :province, :city, :postal_code)
        ON DUPLICATE KEY UPDATE
            email = VALUES(email),
            address = VALUES(address),
            province = VALUES(province),
            city = VALUES(city),
            postal_code = VALUES(postal_code)";

$stmt = $conn->prepare($sql);
$success = $stmt->execute([
    ':user_id' => $user_id,
    ':email' => $email,
    ':address' => $address,
    ':province' => $province,
    ':city' => $city,
    ':postal_code' => $postal_code,
]);

if ($success) {
    echo json_encode(["status" => "success", "message" => "Address added or updated successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Database error"]);
}
?>
