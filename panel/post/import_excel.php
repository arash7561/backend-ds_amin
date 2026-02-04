<?php
/**
 * وارد کردن محصولات از فایل اکسل
 * POST: multipart/form-data با فیلد excelFile
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
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// بررسی احراز هویت ادمین
try {
    $user = checkJWT();
    if (!isset($user['role']) || $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode([
            'status' => false,
            'message' => 'دسترسی غیرمجاز. فقط ادمین می‌تواند محصولات را وارد کند.'
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

// تبدیل متن به slug
function makeSlug($string) {
    $slug = mb_strtolower(trim($string), 'UTF-8');
    $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

// اگر عنوان خالی بود یک slug تصادفی بسازه
function makeSlugUnique($string) {
    $slug = makeSlug($string);
    if (empty($slug)) {
        $slug = uniqid("item-");
    }
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

// تبدیل رشته اکسل به JSON ابعاد
// مثال ورودی اکسل: 100x10,120x12,150x15
function parseDimensions($string) {
    if (empty($string)) {
        return null;
    }
    $dimensions = [];
    $pairs = explode(',', $string);
    foreach ($pairs as $pair) {
        $parts = explode('x', trim($pair));
        if (count($parts) == 2) {
            $length = (float) trim($parts[0]);
            $diameter = (float) trim($parts[1]);
            if ($length > 0 && $diameter > 0) {
                $dimensions[] = [
                    "length" => $length,
                    "diameter" => $diameter
                ];
            }
        }
    }
    return !empty($dimensions) ? json_encode($dimensions, JSON_UNESCAPED_UNICODE) : null;
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

// بررسی وجود فایل
if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorMessage = 'خطا در آپلود فایل.';
    if (isset($_FILES['excelFile']['error'])) {
        switch ($_FILES['excelFile']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMessage = 'حجم فایل بیش از حد مجاز است.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMessage = 'فایل به صورت ناقص آپلود شده است.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMessage = 'هیچ فایلی آپلود نشده است.';
                break;
        }
    }
    echo json_encode([
        'status' => false,
        'message' => $errorMessage
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// بررسی نوع فایل
$tmpFilePath = $_FILES['excelFile']['tmp_name'];
$fileName = $_FILES['excelFile']['name'];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($fileExt, ['xls', 'xlsx'])) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'message' => 'فرمت فایل نامعتبر است. فقط فایل‌های .xls و .xlsx مجاز هستند.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // بارگذاری فایل اکسل
    $spreadsheet = IOFactory::load($tmpFilePath);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray();

    // بررسی وجود داده
    if (count($data) < 2) {
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => 'فایل اکسل خالی است یا فقط هدر دارد.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // حذف هدر اکسل
    unset($data[0]);
    $data = array_values($data); // بازنشانی ایندکس‌ها

    // دریافت دسته‌بندی پیش‌فرض
    $defaultCategory = isset($_POST['defaultCategory']) && !empty($_POST['defaultCategory']) 
        ? (int)$_POST['defaultCategory'] 
        : null;

    // پردازش تصاویر آپلود شده
    $uploadedImages = [];
    $upload_dir = '../../uploads/products/';
    
    // ایجاد پوشه اگر وجود ندارد
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // پردازش تصاویر آپلود شده
    if (isset($_FILES['images'])) {
        $imageFiles = $_FILES['images'];
        
        // بررسی اینکه آیا array است یا single file
        if (is_array($imageFiles['name'])) {
            // Multiple files
            $imageCount = count($imageFiles['name']);
            for ($i = 0; $i < $imageCount; $i++) {
                if ($imageFiles['error'][$i] === UPLOAD_ERR_OK) {
                    $fileName = $imageFiles['name'][$i];
                    $fileTmpPath = $imageFiles['tmp_name'][$i];
                    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array($fileExt, $allowedExt)) {
                        $newFileName = uniqid('prod_') . '_' . basename($fileName);
                        $destPath = $upload_dir . $newFileName;
                        
                        if (move_uploaded_file($fileTmpPath, $destPath)) {
                            // ذخیره نام اصلی فایل و مسیر جدید
                            $uploadedImages[basename($fileName)] = 'uploads/products/' . $newFileName;
                        }
                    }
                }
            }
        } else {
            // Single file
            if ($imageFiles['error'] === UPLOAD_ERR_OK) {
                $fileName = $imageFiles['name'];
                $fileTmpPath = $imageFiles['tmp_name'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($fileExt, $allowedExt)) {
                    $newFileName = uniqid('prod_') . '_' . basename($fileName);
                    $destPath = $upload_dir . $newFileName;
                    
                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        $uploadedImages[basename($fileName)] = 'uploads/products/' . $newFileName;
                    }
                }
            }
        }
    }

    // شروع تراکنش
    $conn->beginTransaction();

    $sql = "INSERT INTO products 
                (title, description, slug, cat_id, status, image, stock, price, discount_price,
                 type, brand, line_count, grade, half_finished, dimensions) 
            VALUES 
                (:title, :description, :slug, :cat_id, :status, :image, :stock, :price, :discount_price,
                 :type, :brand, :line_count, :grade, :half_finished, :dimensions)";
    $stmt = $conn->prepare($sql);

    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    foreach ($data as $rowIndex => $row) {
        try {
            // دریافت و پاکسازی داده‌ها
            $title = htmlspecialchars(trim($row[0] ?? ''), ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars(trim($row[1] ?? ''), ENT_QUOTES, 'UTF-8');
            
            // استفاده از دسته‌بندی پیش‌فرض اگر در اکسل خالی باشد
            $cat_id_excel = isset($row[3]) && is_numeric($row[3]) && (int)$row[3] > 0 ? (int)$row[3] : null;
            $cat_id = $cat_id_excel ?? ($defaultCategory ?? 0);
            
            $status = isset($row[4]) && is_numeric($row[4]) ? (int)$row[4] : 0;
            
            // پردازش تصویر: اگر نام فایل در اکسل است، با تصاویر آپلود شده match کن
            $imageName = htmlspecialchars(trim($row[5] ?? ''), ENT_QUOTES, 'UTF-8');
            $image = '';
            
            if (!empty($imageName)) {
                // اگر نام فایل در لیست تصاویر آپلود شده است
                if (isset($uploadedImages[$imageName])) {
                    $image = $uploadedImages[$imageName];
                } else {
                    // اگر مسیر کامل است، استفاده کن
                    $image = $imageName;
                }
            }
            $stock = isset($row[6]) && is_numeric($row[6]) ? (int)$row[6] : 0;
            $price = isset($row[7]) && is_numeric($row[7]) ? (float)$row[7] : 0;
            $discount_price = isset($row[8]) && is_numeric($row[8]) ? (float)$row[8] : 0;
            $type = !empty($row[9]) ? htmlspecialchars(trim($row[9]), ENT_QUOTES, 'UTF-8') : null;
            $brand = !empty($row[10]) ? htmlspecialchars(trim($row[10]), ENT_QUOTES, 'UTF-8') : null;
            $line_count = isset($row[11]) && is_numeric($row[11]) ? (int)$row[11] : null;
            $grade = isset($row[12]) && is_numeric($row[12]) ? (float)$row[12] : null;
            $half_finished = !empty($row[13]) ? htmlspecialchars(trim($row[13]), ENT_QUOTES, 'UTF-8') : null;

            // اعتبارسنجی داده‌های ضروری
            if (empty($title)) {
                $errors[] = "ردیف " . ($rowIndex + 2) . ": عنوان خالی است";
                $errorCount++;
                continue;
            }

            // ساختن slug و تضمین یونیک بودنش
            $slug = makeSlugUnique($title);
            $slug = getUniqueSlug($conn, $slug);

            // گرفتن ابعاد (ستون 14 اکسل)
            $dimensions = isset($row[14]) ? parseDimensions($row[14]) : null;

            // اجرای درج
            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':slug' => $slug,
                ':cat_id' => $cat_id,
                ':status' => $status,
                ':image' => $image,
                ':stock' => $stock,
                ':price' => $price,
                ':discount_price' => $discount_price,
                ':type' => $type,
                ':brand' => $brand,
                ':line_count' => $line_count,
                ':grade' => $grade,
                ':half_finished' => $half_finished,
                ':dimensions' => $dimensions
            ]);

            $successCount++;
        } catch (PDOException $e) {
            $errorCount++;
            $errors[] = "ردیف " . ($rowIndex + 2) . ": " . $e->getMessage();
            error_log("import_excel.php - Row " . ($rowIndex + 2) . " error: " . $e->getMessage());
        } catch (Exception $e) {
            $errorCount++;
            $errors[] = "ردیف " . ($rowIndex + 2) . ": " . $e->getMessage();
            error_log("import_excel.php - Row " . ($rowIndex + 2) . " error: " . $e->getMessage());
        }
    }

    // اگر هیچ محصولی با موفقیت وارد نشد، تراکنش را rollback کن
    if ($successCount === 0) {
        $conn->rollBack();
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => 'هیچ محصولی با موفقیت وارد نشد.',
            'errors' => $errors,
            'total_rows' => count($data),
            'success_count' => $successCount,
            'error_count' => $errorCount
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // commit تراکنش
    $conn->commit();

    // پاسخ موفقیت
    http_response_code(200);
    echo json_encode([
        'status' => true,
        'message' => "وارد کردن کالاها با موفقیت انجام شد.",
        'total_rows' => count($data),
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'errors' => !empty($errors) ? $errors : null
    ], JSON_UNESCAPED_UNICODE);

} catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'message' => 'خطا در بارگذاری فایل اکسل: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    error_log("import_excel.php - Database error: " . $e->getMessage());
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'خطای عمومی: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    error_log("import_excel.php - General error: " . $e->getMessage());
}
?>
