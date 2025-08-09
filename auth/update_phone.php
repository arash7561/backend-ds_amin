<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once('../config/config.php');

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
    if (!$payload || !isset($payload['user_id'])) {
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است']);
        exit;
    }
    
    $userId = $payload['user_id'];
    
    // Check if phone number already exists for another user
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE mobile = ? AND id != ?");
    $checkStmt->execute([$phone, $userId]);
    
    if ($checkStmt->fetch()) {
        echo json_encode(['status' => false, 'message' => 'این شماره تلفن قبلاً ثبت شده است']);
        exit;
    }
    
    // Update phone number
    $updateStmt = $pdo->prepare("UPDATE users SET mobile = ? WHERE id = ?");
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