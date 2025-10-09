<?php
require_once __DIR__ . '/../../db_connection.php';

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
    
    // بررسی وجود جدول bulk_pricing_rules
    $stmt = $conn->prepare("SHOW TABLES LIKE 'bulk_pricing_rules'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        // اگر جدول وجود ندارد، آن را ایجاد کنیم
        $createTableSql = "CREATE TABLE IF NOT EXISTS bulk_pricing_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            min_quantity INT NOT NULL,
            max_quantity INT NULL,
            discount_percent DECIMAL(5,2) NULL,
            discount_amount DECIMAL(10,2) NULL,
            price_per_unit DECIMAL(10,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        $conn->exec($createTableSql);
    }
    
    switch ($action) {
        case 'get':
            $productId = $_GET['product_id'] ?? null;
            if (!$productId) {
                throw new Exception('شناسه محصول الزامی است');
            }
            
            $stmt = $conn->prepare("
                SELECT * FROM bulk_pricing_rules 
                WHERE product_id = ? 
                ORDER BY min_quantity ASC
            ");
            $stmt->execute([$productId]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'rules' => $rules
            ]);
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
            
            // Validate discount values
            if (!$discountPercent && !$discountAmount) {
                throw new Exception('حداقل یکی از درصد تخفیف یا مبلغ تخفیف باید مشخص شود');
            }
            
            $stmt = $conn->prepare("
                INSERT INTO bulk_pricing_rules 
                (product_id, min_quantity, max_quantity, discount_percent, discount_amount, price_per_unit, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $productId,
                $minQuantity,
                $maxQuantity ?: null,
                $discountPercent ?: null,
                $discountAmount ?: null,
                $pricePerUnit ?: null
            ]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'قانون قیمت‌گذاری حجمی با موفقیت ایجاد شد'
                ]);
            } else {
                throw new Exception('خطا در ایجاد قانون');
            }
            break;
            
        case 'update':
            $ruleId = $_POST['rule_id'] ?? null;
            $minQuantity = $_POST['min_quantity'] ?? null;
            $maxQuantity = $_POST['max_quantity'] ?? null;
            $discountPercent = $_POST['discount_percent'] ?? null;
            $discountAmount = $_POST['discount_amount'] ?? null;
            $pricePerUnit = $_POST['price_per_unit'] ?? null;
            
            if (!$ruleId || !$minQuantity) {
                throw new Exception('شناسه قانون و تعداد حداقل الزامی است');
            }
            
            $stmt = $conn->prepare("
                UPDATE bulk_pricing_rules 
                SET min_quantity = ?, max_quantity = ?, discount_percent = ?, 
                    discount_amount = ?, price_per_unit = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $minQuantity,
                $maxQuantity ?: null,
                $discountPercent ?: null,
                $discountAmount ?: null,
                $pricePerUnit ?: null,
                $ruleId
            ]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'قانون قیمت‌گذاری حجمی با موفقیت به‌روزرسانی شد'
                ]);
            } else {
                throw new Exception('خطا در به‌روزرسانی قانون');
            }
            break;
            
        case 'delete':
            $ruleId = $_POST['rule_id'] ?? null;
            if (!$ruleId) {
                throw new Exception('شناسه قانون الزامی است');
            }
            
            $stmt = $conn->prepare("DELETE FROM bulk_pricing_rules WHERE id = ?");
            $result = $stmt->execute([$ruleId]);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'قانون با موفقیت حذف شد'
                ]);
            } else {
                throw new Exception('خطا در حذف قانون');
            }
            break;
            
        default:
            throw new Exception('عملیات نامعتبر');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    error_log("Bulk pricing error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطا در ارتباط با پایگاه داده'
    ]);
}
?>
