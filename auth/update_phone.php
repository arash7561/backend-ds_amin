<?php
// CORS headers - Allow from localhost and production domain
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
    'https://aminindpharm.ir',
    'http://aminindpharm.ir'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!$origin && isset($_SERVER['HTTP_REFERER'])) {
    $origin = preg_replace('#^([^/]+://[^/]+).*$#', '$1', $_SERVER['HTTP_REFERER']);
}

if (in_array($origin, $allowed_origins) || 
    (strpos($origin, 'http://localhost') !== false || 
     strpos($origin, 'http://127.0.0.1') !== false ||
     strpos($origin, 'https://aminindpharm.ir') !== false ||
     strpos($origin, 'http://aminindpharm.ir') !== false)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../db_connection.php';
$conn = getPDO();
try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['phone'])) {
        echo json_encode(['status' => false, 'message' => 'شماره تلفن ارسال نشده است']);
        exit;
    }
    
    $phone = trim($input['phone']);
    
    // Validate phone number
    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        echo json_encode(['status' => false, 'message' => 'شماره تلفن نامعتبر است']);
        exit;
    }
    
    // Get JWT token from Authorization header
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        echo json_encode(['status' => false, 'message' => 'توکن احراز هویت یافت نشد']);
        exit;
    }
    
    $token = $matches[1];
    
    // Decode JWT token
    $tokenParts = explode('.', $token);
    if (count($tokenParts) !== 3) {
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است']);
        exit;
    }
    
    $payload = json_decode(base64_decode($tokenParts[1]), true);
    if (!$payload || !isset($payload['uid'])) {
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است']);
        exit;
    }
    
    $userId = $payload['uid'];
    
    // Check if phone number already exists for another user
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE mobile = ? AND id != ?");
    $checkStmt->execute([$phone, $userId]);
    
    if ($checkStmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'این شماره تلفن قبلاً ثبت شده است']);
        exit;
    }
    
    // Update phone number
    $updateStmt = $conn->prepare("UPDATE users SET mobile = ? WHERE id = ?");
    $result = $updateStmt->execute([$phone, $userId]);
    
    if ($result) {
        echo json_encode(['status' => true, 'message' => 'شماره تلفن با موفقیت بروزرسانی شد']);
    } else {
        echo json_encode(['status' => false, 'message' => 'خطا در بروزرسانی شماره تلفن']);
    }
    
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => 'خطا در سرور: ' . $e->getMessage()]);
}
?>