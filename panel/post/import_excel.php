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

    // افزودن تصاویر موجود در سایت (بدون نیاز به آپلود مجدد)
    $stmtImg = $conn->query("SELECT DISTINCT image FROM products WHERE image IS NOT NULL AND image != '' AND TRIM(image) != ''");
    while ($row = $stmtImg->fetch(PDO::FETCH_ASSOC)) {
        $path = trim($row['image']);
        if (!empty($path)) {
            $uploadedImages[basename($path)] = $path;
            $uploadedImages[$path] = $path;
        }
    }
    // شروع تراکنش
    $conn->beginTransaction();

    $sql = "INSERT INTO products 
                (title, description, slug, cat_id, status, image, stock, price, discount_price, discount_percent,
                 size, type, brand, line_count, grade, half_finished, width, color, material, slot_count,
                 general_description, weight, dimensions) 
            VALUES 
                (:title, :description, :slug, :cat_id, :status, :image, :stock, :price, :discount_price, :discount_percent,
                 :size, :type, :brand, :line_count, :grade, :half_finished, :width, :color, :material, :slot_count,
                 :general_description, :weight, :dimensions)";
    $stmt = $conn->prepare($sql);

    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    // نگاشت ستون‌های اکسل (شروع از 0):
    // 0:عنوان 1:توضیحات 2:اسلاگ 3:دسته‌بندی 4:وضعیت 5:تصویر 6:موجودی 7:قیمت 8:قیمت تخفیف 9:درصد تخفیف
    // 10:سایز 11:نوع 12:برند 13:تعداد خط 14:درجه 15:نیمه‌تمام 16:عرض 17:رنگ 18:جنس 19:تعداد شیار
    // 20:مشخصات عمومی 21:وزن 22:ابعاد

    foreach ($data as $rowIndex => $row) {
        try {
            $title = htmlspecialchars(trim($row[0] ?? ''), ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars(trim($row[1] ?? ''), ENT_QUOTES, 'UTF-8');
            $cat_id_excel = isset($row[3]) && is_numeric($row[3]) && (int)$row[3] > 0 ? (int)$row[3] : null;
            $cat_id = $cat_id_excel ?? ($defaultCategory ?? 0);
            $statusVal = isset($row[4]) && is_numeric($row[4]) ? (int)$row[4] : 0;
            $status = ($statusVal === 1) ? 'active' : 'inactive';

            $imageName = htmlspecialchars(trim($row[5] ?? ''), ENT_QUOTES, 'UTF-8');
            $image = '';
            if (!empty($imageName)) {
                $image = $uploadedImages[$imageName] ?? $imageName;
            }

            $stock = isset($row[6]) && $row[6] !== '' && is_numeric($row[6]) ? (int)$row[6] : 0;
            $price = isset($row[7]) && is_numeric($row[7]) ? (float)$row[7] : 0;
            $discount_price = isset($row[8]) && $row[8] !== '' && is_numeric($row[8]) ? (float)$row[8] : null;
            $discount_percent = isset($row[9]) && $row[9] !== '' && is_numeric($row[9]) ? (float)$row[9] : 0;
            $size = !empty($row[10]) ? htmlspecialchars(trim($row[10]), ENT_QUOTES, 'UTF-8') : null;
            $type = !empty($row[11]) ? htmlspecialchars(trim($row[11]), ENT_QUOTES, 'UTF-8') : null;
            $brand = !empty($row[12]) ? htmlspecialchars(trim($row[12]), ENT_QUOTES, 'UTF-8') : null;
            $line_count = isset($row[13]) && $row[13] !== '' && is_numeric($row[13]) ? (int)$row[13] : null;
            $grade = isset($row[14]) && $row[14] !== '' && is_numeric($row[14]) ? (float)$row[14] : null;
            $half_finished = !empty($row[15]) ? htmlspecialchars(trim($row[15]), ENT_QUOTES, 'UTF-8') : null;
            $width = isset($row[16]) && $row[16] !== '' && is_numeric($row[16]) ? (int)$row[16] : null;
            $color = !empty($row[17]) ? htmlspecialchars(trim($row[17]), ENT_QUOTES, 'UTF-8') : null;
            $material = !empty($row[18]) ? htmlspecialchars(trim($row[18]), ENT_QUOTES, 'UTF-8') : null;
            $slot_count = isset($row[19]) && $row[19] !== '' && is_numeric($row[19]) ? (int)$row[19] : null;
            $general_description = !empty($row[20]) ? htmlspecialchars(trim($row[20]), ENT_QUOTES, 'UTF-8') : null;
            $weight = isset($row[21]) && $row[21] !== '' && is_numeric($row[21]) ? (float)$row[21] : null;
            $dimensions = isset($row[22]) ? parseDimensions($row[22]) : null;

            if (empty($title)) {
                $errors[] = "ردیف " . ($rowIndex + 2) . ": عنوان خالی است";
                $errorCount++;
                continue;
            }

            $tempSlug = 'temp-' . uniqid() . '-' . time() . '-' . $rowIndex;

            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':slug' => $tempSlug,
                ':cat_id' => $cat_id,
                ':status' => $status,
                ':image' => $image,
                ':stock' => $stock,
                ':price' => $price,
                ':discount_price' => $discount_price,
                ':discount_percent' => $discount_percent,
                ':size' => $size,
                ':type' => $type,
                ':brand' => $brand,
                ':line_count' => $line_count,
                ':grade' => $grade,
                ':half_finished' => $half_finished,
                ':width' => $width,
                ':color' => $color,
                ':material' => $material,
                ':slot_count' => $slot_count,
                ':general_description' => $general_description,
                ':weight' => $weight,
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

    // ساخت و به‌روزرسانی slug برای محصولاتی که slug موقت دارند (بعد از commit)
    $updatedSlugCount = 0;
    try {
        // پیدا کردن محصولاتی که slug موقت دارند (شروع با temp-)
        // این محصولات همان محصولاتی هستند که از اکسل وارد شدند و slug برایشان ساخته نشده
        $stmt = $conn->prepare("SELECT id, title FROM products WHERE slug LIKE 'temp-%'");
        $stmt->execute();
        $productsWithTempSlug = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($productsWithTempSlug)) {
            $updateStmt = $conn->prepare("UPDATE products SET slug = :slug WHERE id = :id");
            
            foreach ($productsWithTempSlug as $product) {
                // دریافت عنوان کالا از فیلد title (عنوان کالا)
                $productTitle = trim($product['title']);
                
                // ساخت slug از عنوان کالا (فیلد title) + اعداد رندم
                $slug = makeSlugUnique($productTitle);
                
                // بررسی اینکه slug شامل عنوان کالا (از فیلد title) باشد
                $titleSlugCheck = mb_strtolower($productTitle, 'UTF-8');
                $titleSlugCheck = preg_replace('/[\s\-_\.\(\)\[\]{}،؛:]+/u', '-', $titleSlugCheck);
                $titleSlugCheck = preg_replace('/-+/', '-', $titleSlugCheck);
                $titleSlugCheck = trim($titleSlugCheck, '-');
                
                if (!empty($titleSlugCheck) && mb_strlen($titleSlugCheck, 'UTF-8') >= 3) {
                    if (mb_strpos($slug, $titleSlugCheck, 0, 'UTF-8') === false) {
                        // اگر عنوان کالا در slug نیست، دوباره بساز با اعداد رندم
                        $slug = $titleSlugCheck . '-' . generateRandomNumbers(5);
                    }
                }
                
                // تضمین یونیک بودن
                $slug = getUniqueSlug($conn, $slug);
                
                // به‌روزرسانی slug
                $updateStmt->execute([
                    ':slug' => $slug,
                    ':id' => $product['id']
                ]);
                $updatedSlugCount++;
            }
        }
    } catch (Exception $e) {
        error_log("import_excel.php - Error updating slugs: " . $e->getMessage());
        // خطا را نادیده می‌گیریم چون محصولات قبلاً وارد شده‌اند
    }

    // پاسخ موفقیت
    http_response_code(200);
    $message = "وارد کردن کالاها با موفقیت انجام شد.";
    if ($updatedSlugCount > 0) {
        $message .= " برای {$updatedSlugCount} محصول اسلاگ ساخته و به‌روزرسانی شد.";
    }
    echo json_encode([
        'status' => true,
        'message' => $message,
        'total_rows' => count($data),
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'updated_slug_count' => $updatedSlugCount,
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
