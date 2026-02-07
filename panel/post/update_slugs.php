<?php
/**
 * به‌روزرسانی اسلاگ محصولاتی که اسلاگ آن‌ها شامل عنوان محصول نیست
 * POST: بدون پارامتر (همه محصولات بررسی می‌شوند)
 */

// CORS headers - باید قبل از هر خروجی باشند
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-CSRF-Token, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => false,
        'message' => 'Method not allowed. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../db_connection.php';
require_once __DIR__ . '/../admin/middleware.php';

// بررسی احراز هویت ادمین
try {
    $user = checkJWT();
    if (!isset($user['role']) || $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode([
            'status' => false,
            'message' => 'دسترسی غیرمجاز. فقط ادمین می‌تواند اسلاگ‌ها را به‌روزرسانی کند.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در احراز هویت: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getPDO();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در اتصال به پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تولید اعداد رندم
function generateRandomNumbers($length = 5) {
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= rand(0, 9);
    }
    return $randomString;
}

// تبدیل فارسی به لاتین (تبدیل ساده)
function persianToLatin($text) {
    $map = [
        'ا' => 'a', 'آ' => 'a', 'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's',
        'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'z',
        'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
        'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f',
        'ق' => 'gh', 'ک' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        'و' => 'v', 'ه' => 'h', 'ی' => 'y', 'ي' => 'y', 'ئ' => 'y', 'ة' => 'h', 'ك' => 'k'
    ];
    
    return strtr($text, $map);
}

// تبدیل متن به slug
function makeSlug($string) {
    $slug = mb_strtolower(trim($string), 'UTF-8');
    $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

// ساخت slug از نام کالا + اعداد رندم
function makeSlugUnique($title) {
    // استفاده از نام اصلی کالا (قبل از htmlspecialchars)
    $titleOriginal = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $titleOriginal = trim($titleOriginal);
    
    // استفاده مستقیم از متن فارسی - تبدیل به حروف کوچک
    $baseSlug = mb_strtolower($titleOriginal, 'UTF-8');
    
    // تبدیل فاصله‌ها و کاراکترهای خاص به - (اما حفظ کاراکترهای فارسی)
    // فقط فاصله‌ها و کاراکترهای خاص را به - تبدیل می‌کنیم
    $baseSlug = preg_replace('/[\s\-_\.\(\)\[\]{}،؛:]+/u', '-', $baseSlug);
    $baseSlug = preg_replace('/-+/', '-', $baseSlug);
    $baseSlug = trim($baseSlug, '-');
    
    // اگر باز هم خالی بود، از hash نام استفاده کن
    if (empty($baseSlug)) {
        // استفاده از md5 برای ساخت یک شناسه منحصر به فرد از نام
        $hash = substr(md5($titleOriginal), 0, 8);
        $baseSlug = 'product-' . $hash;
    }
    
    // اضافه کردن اعداد رندم
    $randomNumbers = generateRandomNumbers(5);
    $slug = $baseSlug . '-' . $randomNumbers;
    
    return $slug;
}

// بررسی یونیک بودن slug در دیتابیس
function getUniqueSlug($conn, $slug) {
    $baseSlug = $slug;
    $i = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);
        $count = $stmt->fetchColumn();
        if ($count == 0) {
            return $slug;
        }
        $slug = $baseSlug . '-' . $i;
        $i++;
        // جلوگیری از حلقه بی‌نهایت
        if ($i > 10000) {
            return $baseSlug . '-' . time();
        }
    }
}

// بررسی اینکه آیا slug شامل نام کالا است یا نه
function slugContainsTitle($slug, $title) {
    if (empty($slug) || empty($title)) {
        return false;
    }
    
    // استفاده از همان منطق makeSlugUnique برای ساخت titleSlug
    $titleOriginal = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $titleOriginal = trim($titleOriginal);
    
    // استفاده مستقیم از متن فارسی - تبدیل به حروف کوچک
    $titleSlug = mb_strtolower($titleOriginal, 'UTF-8');
    
    // تبدیل فاصله‌ها و کاراکترهای خاص به - (اما حفظ کاراکترهای فارسی)
    $titleSlug = preg_replace('/[\s\-_\.\(\)\[\]{}،؛:]+/u', '-', $titleSlug);
    $titleSlug = preg_replace('/-+/', '-', $titleSlug);
    $titleSlug = trim($titleSlug, '-');
    
    if (empty($titleSlug)) {
        return false;
    }
    
    // بررسی اینکه آیا titleSlug در slug وجود دارد
    // باید حداقل 3 کاراکتر از titleSlug در slug باشد
    if (mb_strlen($titleSlug, 'UTF-8') < 3) {
        return false;
    }
    
    // استفاده از mb_strpos برای کاراکترهای چندبایتی
    return mb_strpos($slug, $titleSlug, 0, 'UTF-8') !== false;
}

try {
    // پیدا کردن همه محصولات
    $stmt = $conn->prepare("SELECT id, title, slug FROM products");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updatedCount = 0;
    $skippedCount = 0;
    $errors = [];
    
    $updateStmt = $conn->prepare("UPDATE products SET slug = :slug WHERE id = :id");
    
    foreach ($products as $product) {
        try {
            // دریافت عنوان کالا از فیلد title
            $productTitle = trim($product['title']);
            $currentSlug = trim($product['slug'] ?? '');
            
            // بررسی اینکه آیا slug شامل عنوان کالا (از فیلد title) است
            if (!empty($currentSlug) && slugContainsTitle($currentSlug, $productTitle)) {
                // اگر slug شامل عنوان کالا است، نیازی به به‌روزرسانی نیست
                $skippedCount++;
                continue;
            }
            
            // ساخت slug جدید از عنوان کالا (فیلد title) + اعداد رندم
            $newSlug = makeSlugUnique($productTitle);
            
            // تضمین یونیک بودن
            $newSlug = getUniqueSlug($conn, $newSlug);
            
            // به‌روزرسانی slug
            $updateStmt->execute([
                ':slug' => $newSlug,
                ':id' => $product['id']
            ]);
            
            $updatedCount++;
        } catch (PDOException $e) {
            $errors[] = "محصول ID {$product['id']}: " . $e->getMessage();
            error_log("update_slugs.php - Product ID {$product['id']} error: " . $e->getMessage());
        } catch (Exception $e) {
            $errors[] = "محصول ID {$product['id']}: " . $e->getMessage();
            error_log("update_slugs.php - Product ID {$product['id']} error: " . $e->getMessage());
        }
    }
    
    // پاسخ موفقیت
    http_response_code(200);
    $message = "به‌روزرسانی اسلاگ‌ها با موفقیت انجام شد.";
    if ($updatedCount > 0) {
        $message .= " {$updatedCount} محصول به‌روزرسانی شد.";
    }
    if ($skippedCount > 0) {
        $message .= " {$skippedCount} محصول بدون تغییر باقی ماند (اسلاگ آن‌ها شامل نام کالا است).";
    }
    
    echo json_encode([
        'status' => true,
        'message' => $message,
        'total_products' => count($products),
        'updated_count' => $updatedCount,
        'skipped_count' => $skippedCount,
        'errors' => !empty($errors) ? $errors : null
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    error_log("update_slugs.php - Database error: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای عمومی: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    error_log("update_slugs.php - General error: " . $e->getMessage());
}
?>
