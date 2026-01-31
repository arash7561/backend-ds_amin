<?php
// CORS headers - Allow from any localhost origin for development
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins) || (strpos($origin, 'http://localhost') !== false || strpos($origin, 'http://127.0.0.1') !== false)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../db_connection.php'; 

// علامت‌گذاری که auth_check با require فراخوانی شده
$_ENV['AUTH_CHECK_REQUIRED'] = true;
$auth = require_once __DIR__ . '/../../auth/auth_check.php';
unset($_ENV['AUTH_CHECK_REQUIRED']);

$userId = $auth['user_id'] ?? null;

error_log("Favorites - UserID: " . var_export($userId, true));

if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'status' => false,
        'message' => 'احراز هویت نامعتبر'
    ]);
    exit();
}

$conn = getPDO();

try {
    // Get user's favorites with product details
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.title,
            p.price,
            p.discount_price,
            p.image,
            p.brand,
            c.name as category,
            f.created_at as favorite_date
        FROM favorites f
        JOIN products p ON f.product_id = p.id
        LEFT JOIN categories c ON p.cat_id = c.id
        WHERE f.user_id = ? AND p.status = 'active'
        ORDER BY f.created_at DESC
    ");
    
    $stmt->execute([$userId]);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $formattedFavorites = [];
    foreach ($favorites as $favorite) {
        $formattedFavorites[] = [
            'id' => (int)$favorite['id'],
            'title' => $favorite['title'],
            'price' => (float)$favorite['price'],
            'discount_price' => $favorite['discount_price'] ? (float)$favorite['discount_price'] : null,
            'image' => $favorite['image'],
            'brand' => $favorite['brand'],
            'category' => $favorite['category'],
            'favorite_date' => $favorite['favorite_date']
        ];
    }
    
    echo json_encode([
        'status' => true,
        'favorites' => $formattedFavorites,
        'count' => count($formattedFavorites)
    ]);
    
} catch (PDOException $e) {
    error_log("Favorites error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در دریافت علاقه‌مندی‌ها'
    ]);
}
?>
