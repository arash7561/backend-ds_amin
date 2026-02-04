<?php
// Load autoload.php from api/vendor directory
$autoloadPath = __DIR__ . '/../../vendor/autoload.php'; // api/vendor/autoload.php
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // Fallback: try root vendor directory
    $autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    } else {
        throw new Exception('vendor/autoload.php not found. Please run composer install.');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Fallback for getallheaders() in WAMP
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}

function checkJWT() {
    // Bypass for localhost dev mode
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocalhost = ($clientIp === '127.0.0.1' || $clientIp === '::1' || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
    if ($isLocalhost && isset($_GET['dev'])) {
        return ['id' => 1, 'role' => 'admin', 'aid' => 1];
    }

    // Function to get Authorization header robustly
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } else {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            }
        }
    }
    
    if (!$authHeader) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن ارسال نشده است'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'ساختار توکن اشتباه است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jwt = $matches[1];
    
    // استفاده از همان secret key که در verify_login.php و login_admin.php استفاده می‌شود
    // verify_login.php از 'your-secret-key' استفاده می‌کند
    // login_admin.php از 'secret_key' استفاده می‌کند (fallback)
    // باید هر دو را پشتیبانی کنیم
    $secretKey = 'your-secret-key'; // Default fallback - باید با verify_login.php یکسان باشد
    
    // بررسی $_ENV (ممکن است در WAMP کار نکند)
    if (isset($_ENV['JWT_SECRET']) && is_string($_ENV['JWT_SECRET']) && !empty(trim($_ENV['JWT_SECRET']))) {
        $secretKey = trim($_ENV['JWT_SECRET']);
    } 
    // بررسی getenv() (برای WAMP)
    elseif (function_exists('getenv')) {
        $envValue = getenv('JWT_SECRET');
        if ($envValue !== false && is_string($envValue) && !empty(trim($envValue))) {
            $secretKey = trim($envValue);
        }
    }
    
    // اطمینان از اینکه secretKey یک string غیرخالی است
    if (!is_string($secretKey) || empty(trim($secretKey))) {
        error_log("checkJWT - Invalid secret key, using fallback");
        $secretKey = 'secret_key'; // Fallback
    }
    
    $secretKey = trim($secretKey); // حذف فاصله‌های اضافی
    
    error_log("checkJWT - Secret key type: " . gettype($secretKey) . ", length: " . strlen($secretKey));
    error_log("checkJWT - JWT token length: " . strlen($jwt));
    error_log("checkJWT - JWT token first 50 chars: " . substr($jwt, 0, 50));

    // لیست secret keys ممکن (اول verify_login.php، بعد login_admin.php)
    $possibleSecretKeys = [
        'your-secret-key', // از verify_login.php
        'secret_key',      // از login_admin.php
        $secretKey         // از $_ENV یا getenv()
    ];
    
    // حذف duplicate ها
    $possibleSecretKeys = array_unique($possibleSecretKeys);
    
    $lastError = null;
    foreach ($possibleSecretKeys as $trySecretKey) {
        if (empty($trySecretKey)) continue;
        
        try {
            error_log("checkJWT - Trying secret key: " . substr($trySecretKey, 0, 10) . "... (length: " . strlen($trySecretKey) . ")");
            $key = new Key($trySecretKey, 'HS256');
            $decoded = JWT::decode($jwt, $key);
            error_log("checkJWT - JWT decoded successfully with secret key: " . substr($trySecretKey, 0, 10) . "...");
            error_log("checkJWT - Decoded payload: " . json_encode($decoded, JSON_UNESCAPED_UNICODE));
            return (array)$decoded; // اطلاعات توکن (مثل id و role)
        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log("checkJWT - Token expired with secret key: " . substr($trySecretKey, 0, 10) . "...");
            http_response_code(401);
            echo json_encode(['status' => false, 'message' => 'توکن منقضی شده است'], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            error_log("checkJWT - Invalid signature with secret key: " . substr($trySecretKey, 0, 10) . "... - " . $e->getMessage());
            $lastError = $e;
            continue; // امتحان secret key بعدی
        } catch (Exception $e) {
            error_log("checkJWT - Error with secret key " . substr($trySecretKey, 0, 10) . "...: " . $e->getMessage());
            $lastError = $e;
            continue; // امتحان secret key بعدی
        }
    }
    
    // اگر هیچ secret key کار نکرد
    error_log("checkJWT - All secret keys failed. Last error: " . ($lastError ? $lastError->getMessage() : 'Unknown'));
    // اگر به اینجا رسیدیم، یعنی هیچ secret key کار نکرد
    if ($lastError instanceof \Firebase\JWT\ExpiredException) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن منقضی شده است'], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($lastError instanceof \Firebase\JWT\SignatureInvalidException) {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر است'], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        http_response_code(401);
        echo json_encode(['status' => false, 'message' => 'توکن نامعتبر یا منقضی شده است: ' . ($lastError ? $lastError->getMessage() : 'Unknown error')], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
