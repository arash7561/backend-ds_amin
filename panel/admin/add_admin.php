<?php
require_once '../../db_connection.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$role_id = (int)($data['role_id'] ?? 0);

if (empty($username) || empty($password) || $role_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'تمام فیلدها الزامی است']);
    exit;
}

// پسورد حداقل 8 کاراکتر با حداقل یک عدد
if (strlen($password) < 8 || !preg_match('/\d/', $password)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'رمز عبور باید حداقل 8 کاراکتر و شامل عدد باشد']);
    exit;
}

try {
    $conn = getPDO();

    // بررسی وجود نقش و گرفتن access_level (می‌تونی براش استفاده کنی)
    $stmt = $conn->prepare("SELECT access_level FROM admin_roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch();

    if (!$role) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'نقش انتخاب شده معتبر نیست']);
        exit;
    }

    // بررسی وجود username
    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => false, 'message' => 'نام کاربری قبلا ثبت شده است']);
        exit;
    }

    // هش پسورد
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // درج ادمین جدید
    $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, role_id) VALUES (?, ?, ?)");
    $stmt->execute([$username, $passwordHash, $role_id]);

    echo json_encode(['status' => true, 'message' => 'ادمین با موفقیت اضافه شد']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()]);
}
