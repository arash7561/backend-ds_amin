<?php
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $conn = getPDO();
    
    // بررسی وجود جدول bulk_pricing_rules و ایجاد در صورت نیاز
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE 'bulk_pricing_rules'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            // اگر جدول وجود ندارد، آن را ایجاد کنیم
            $createTableSql = "CREATE TABLE IF NOT EXISTS bulk_pricing_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                min_quantity INT NOT NULL DEFAULT 1,
                max_quantity INT NULL,
                discount_percent DECIMAL(5,2) NULL,
                discount_amount DECIMAL(10,2) NULL,
                price_per_unit DECIMAL(10,2) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_product_id (product_id),
                INDEX idx_quantity (min_quantity, max_quantity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $conn->exec($createTableSql);
        }
    } catch (PDOException $e) {
        // جدول ممکن است وجود داشته باشد، ادامه می‌دهیم
        error_log("Bulk pricing table check: " . $e->getMessage());
    }
    
    switch ($action) {
        case 'get':
            $productId = isset($_GET['product_id']) ? $_GET['product_id'] : null;
            
            if (!$productId) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'شناسه محصول الزامی است'
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
            
            $stmt = $conn->prepare("
                SELECT * FROM bulk_pricing_rules 
                WHERE product_id = ? 
                ORDER BY min_quantity ASC
            ");
            $stmt->execute([$productId]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert numeric strings to proper types
            if (is_array($rules)) {
                foreach ($rules as &$rule) {
                    if (is_array($rule)) {
                        $rule['id'] = isset($rule['id']) ? (int)$rule['id'] : 0;
                        $rule['product_id'] = isset($rule['product_id']) ? (int)$rule['product_id'] : 0;
                        $rule['min_quantity'] = isset($rule['min_quantity']) ? (int)$rule['min_quantity'] : 0;
                        $rule['max_quantity'] = isset($rule['max_quantity']) && $rule['max_quantity'] !== null && $rule['max_quantity'] !== '' ? (int)$rule['max_quantity'] : null;
                        $rule['discount_percent'] = isset($rule['discount_percent']) && $rule['discount_percent'] !== null && $rule['discount_percent'] !== '' ? (float)$rule['discount_percent'] : null;
                        $rule['discount_amount'] = isset($rule['discount_amount']) && $rule['discount_amount'] !== null && $rule['discount_amount'] !== '' ? (float)$rule['discount_amount'] : null;
                        $rule['price_per_unit'] = isset($rule['price_per_unit']) && $rule['price_per_unit'] !== null && $rule['price_per_unit'] !== '' ? (float)$rule['price_per_unit'] : null;
                    }
                }
            } else {
                $rules = [];
            }
            
            // Return array directly (frontend expects array)
            echo json_encode($rules, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'create':
            $productId = $_POST['product_id'] ?? null;
            $minQuantity = $_POST['min_quantity'] ?? null;
            $maxQuantity = $_POST['max_quantity'] ?? null;
            $discountPercent = $_POST['discount_percent'] ?? null;
            $discountAmount = $_POST['discount_amount'] ?? null;
            $pricePerUnit = $_POST['price_per_unit'] ?? null;
            
            if (!$productId || !$minQuantity) {
                throw new Exception('شناسه محصول و تعداد حداقل الزامی است');
            }
            
            // Validate discount values - at least one discount method must be provided
            if (!$discountPercent && !$discountAmount && !$pricePerUnit) {
                throw new Exception('حداقل یکی از فیلدهای درصد تخفیف، مبلغ تخفیف یا قیمت هر واحد باید مشخص شود');
            }
            
            // Convert empty strings to null
            $maxQuantity = $maxQuantity === '' ? null : $maxQuantity;
            $discountPercent = $discountPercent === '' ? null : $discountPercent;
            $discountAmount = $discountAmount === '' ? null : $discountAmount;
            $pricePerUnit = $pricePerUnit === '' ? null : $pricePerUnit;
            
            $stmt = $conn->prepare("
                INSERT INTO bulk_pricing_rules 
                (product_id, min_quantity, max_quantity, discount_percent, discount_amount, price_per_unit, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $productId,
                $minQuantity,
                $maxQuantity,
                $discountPercent,
                $discountAmount,
                $pricePerUnit
            ]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'قانون قیمت‌گذاری حجمی با موفقیت ایجاد شد'
                ], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('خطا در ایجاد قانون');
            }
            break;
            
        case 'update':
            $ruleId = $_POST['rule_id'] ?? null;
            $productId = $_POST['product_id'] ?? null;
            $minQuantity = $_POST['min_quantity'] ?? null;
            $maxQuantity = $_POST['max_quantity'] ?? null;
            $discountPercent = $_POST['discount_percent'] ?? null;
            $discountAmount = $_POST['discount_amount'] ?? null;
            $pricePerUnit = $_POST['price_per_unit'] ?? null;
            
            if (!$ruleId || !$productId || !$minQuantity) {
                throw new Exception('شناسه قانون، شناسه محصول و تعداد حداقل الزامی است');
            }
            
            // Validate discount values - at least one discount method must be provided
            if (!$discountPercent && !$discountAmount && !$pricePerUnit) {
                throw new Exception('حداقل یکی از فیلدهای درصد تخفیف، مبلغ تخفیف یا قیمت هر واحد باید مشخص شود');
            }
            
            // Convert empty strings to null
            $maxQuantity = $maxQuantity === '' ? null : $maxQuantity;
            $discountPercent = $discountPercent === '' ? null : $discountPercent;
            $discountAmount = $discountAmount === '' ? null : $discountAmount;
            $pricePerUnit = $pricePerUnit === '' ? null : $pricePerUnit;
            
            $stmt = $conn->prepare("
                UPDATE bulk_pricing_rules 
                SET product_id = ?, min_quantity = ?, max_quantity = ?, discount_percent = ?, 
                    discount_amount = ?, price_per_unit = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $productId,
                $minQuantity,
                $maxQuantity,
                $discountPercent,
                $discountAmount,
                $pricePerUnit,
                $ruleId
            ]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'قانون قیمت‌گذاری حجمی با موفقیت به‌روزرسانی شد'
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'قانون یافت نشد'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'delete':
            $ruleId = $_POST['rule_id'] ?? null;
            if (!$ruleId) {
                throw new Exception('شناسه قانون الزامی است');
            }
            
            $stmt = $conn->prepare("DELETE FROM bulk_pricing_rules WHERE id = ?");
            $result = $stmt->execute([$ruleId]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'قانون با موفقیت حذف شد'
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'قانون یافت نشد'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        default:
            throw new Exception('عملیات نامعتبر');
    }
    
} catch (PDOException $e) {
    error_log("Bulk pricing PDO error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطا در ارتباط با پایگاه داده: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("Bulk pricing error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
