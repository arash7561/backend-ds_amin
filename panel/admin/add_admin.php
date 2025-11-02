<?php
require_once __DIR__ . '/../../db_connection.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$role_id  = (int)($data['role_id'] ?? 0);
$mobile   = trim($data['mobile'] ?? '');

if (empty($username) || empty($password) || $role_id <= 0 || empty($mobile)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'تمام فیلدها الزامی است']);
    exit;
}

// اعتبارسنجی پسورد
if (strlen($password) < 8 || !preg_match('/\d/', $password)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'رمز عبور باید حداقل 8 کاراکتر و شامل عدد باشد']);
    exit;
}

// اعتبارسنجی موبایل (11 رقمی و شروع با 09)
if (!preg_match('/^09\d{9}$/', $mobile)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'شماره موبایل نامعتبر است']);
    exit;
}

try {
    $conn = getPDO();

    // بررسی نقش
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

    // بررسی وجود موبایل
    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE mobile = ?");
    $stmt->execute([$mobile]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => false, 'message' => 'این شماره موبایل قبلا ثبت شده است']);
        exit;
    }

    // هش پسورد
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // درج ادمین جدید
    $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, role_id, mobile) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $passwordHash, $role_id, $mobile]);

    echo json_encode(['status' => true, 'message' => 'ادمین با موفقیت اضافه شد']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()]);
}
