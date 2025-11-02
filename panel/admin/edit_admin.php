<?php
require_once __DIR__ . '/../../db_connection.php';
require_once 'middleware.php';

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

$userData = checkJWT(); // برمیگردونه اطلاعات توکن
$conn = getPDO();

$stmt = $conn->query("SELECT id, username, role_id FROM admin_users");
$admins = $stmt->fetchAll();

echo json_encode([
    'status' => true,
    'data' => $admins,
    'user' => $userData // برای تست نشون میدیم
]);

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);

$admin_id = (int)($data['admin_id'] ?? 0);
$username = trim($data['username'] ?? '');
$role_id = (int)($data['role_id'] ?? 0);

$current_password = $data['current_password'] ?? ''; // رمز عبور قبلی برای تغییر رمز
$new_password = $data['new_password'] ?? ''; // رمز عبور جدید

if ($admin_id <= 0 || empty($username) || $role_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'فیلدهای admin_id، username و role_id الزامی است']);
    exit;
}

// اگر قرار است رمز تغییر کند، باید رمز قبلی ارسال و اعتبارسنجی شود
if ($new_password !== '') {
    if (strlen($new_password) < 8 || !preg_match('/\d/', $new_password)) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'رمز عبور جدید باید حداقل 8 کاراکتر و شامل عدد باشد']);
        exit;
    }
    if (empty($current_password)) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'برای تغییر رمز باید رمز عبور فعلی وارد شود']);
        exit;
    }
}

try {
    $conn = getPDO();

    // چک نقش
    $stmt = $conn->prepare("SELECT access_level FROM admin_roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch();
    if (!$role) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'نقش انتخاب شده معتبر نیست']);
        exit;
    }

    // چک وجود ادمین با این id
    $stmt = $conn->prepare("SELECT * FROM admin_users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
    if (!$admin) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'ادمین مورد نظر یافت نشد']);
        exit;
    }

    // چک اینکه username جدید قبلا توسط ادمین دیگه‌ای استفاده نشده
    $stmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ? AND id != ?");
    $stmt->execute([$username, $admin_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['status' => false, 'message' => 'نام کاربری قبلا توسط شخص دیگری استفاده شده']);
        exit;
    }

    // اگر رمز باید تغییر کند، بررسی رمز فعلی
    if ($new_password !== '') {
        if (!password_verify($current_password, $admin['password_hash'])) {
            http_response_code(401);
            echo json_encode(['status' => false, 'message' => 'رمز عبور فعلی اشتباه است']);
            exit;
        }

        $newPasswordHash = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE admin_users SET username = ?, password_hash = ?, role_id = ? WHERE id = ?");
        $stmt->execute([$username, $newPasswordHash, $role_id, $admin_id]);
    } else {
        // اگر رمز تغییر نکند
        $stmt = $conn->prepare("UPDATE admin_users SET username = ?, role_id = ? WHERE id = ?");
        $stmt->execute([$username, $role_id, $admin_id]);
    }

    echo json_encode(['status' => true, 'message' => 'ویرایش ادمین با موفقیت انجام شد']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'خطای سرور: ' . $e->getMessage()]);
}
