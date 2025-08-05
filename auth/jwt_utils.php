<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function verify_jwt_token($token) {
    $secret_key = 'your-secret-key';  // کلید مخفی JWT
    
    try {
        // اعتبارسنجی و دکد توکن
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        $decoded_array = (array) $decoded;
        
        return [
            'uid' => $decoded_array['uid'] ?? null,
            'mobile' => $decoded_array['mobile'] ?? null,
            'valid' => true
        ];
    } catch (Exception $e) {
        return ['valid' => false, 'error' => $e->getMessage()];
    }
} 