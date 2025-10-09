<?php
/**
 * فایل ایجاد جداول مورد نیاز برای قابلیت‌های جدید
 * این فایل را یک بار اجرا کنید تا جداول ایجاد شوند
 */

require_once __DIR__ . '/../db_connection.php';

header('Content-Type: text/html; charset=UTF-8');

try {
    $conn = getPDO();
    
    echo "<h2>ایجاد جداول مورد نیاز</h2>";
    
    // SQL commands
    $sqlCommands = [
        // جدول قوانین قیمت‌گذاری حجمی
        "CREATE TABLE IF NOT EXISTS bulk_pricing_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            min_quantity INT NOT NULL,
            max_quantity INT NULL,
            discount_percent DECIMAL(5,2) NULL,
            discount_amount DECIMAL(10,2) NULL,
            price_per_unit DECIMAL(10,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_product_quantity (product_id, min_quantity)
        )",
        
        // جدول کدهای تخفیف
        "CREATE TABLE IF NOT EXISTS coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            description TEXT,
            discount_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
            discount_value DECIMAL(10,2) NOT NULL,
            min_order_amount DECIMAL(10,2) NULL,
            max_discount_amount DECIMAL(10,2) NULL,
            usage_limit INT NULL,
            usage_count INT DEFAULT 0,
            valid_from DATETIME NULL,
            valid_until DATETIME NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            applicable_products ENUM('all', 'specific', 'category') DEFAULT 'all',
            product_ids TEXT NULL,
            category_ids TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_code (code),
            INDEX idx_active_valid (is_active, valid_until),
            INDEX idx_usage (usage_count, usage_limit)
        )",
        
        // جدول استفاده از کدهای تخفیف
        "CREATE TABLE IF NOT EXISTS coupon_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coupon_id INT NOT NULL,
            user_id INT NULL,
            order_id INT NULL,
            discount_amount DECIMAL(10,2) NOT NULL,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
            INDEX idx_coupon_usage (coupon_id, used_at),
            INDEX idx_user_usage (user_id, used_at)
        )"
    ];
    
    foreach ($sqlCommands as $index => $sql) {
        echo "<h3>اجرای دستور " . ($index + 1) . "</h3>";
        echo "<pre>" . htmlspecialchars($sql) . "</pre>";
        
        try {
            $conn->exec($sql);
            echo "<p style='color: green;'>✅ موفقیت‌آمیز</p>";
        } catch (PDOException $e) {
            echo "<p style='color: red;'>❌ خطا: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    // بررسی جداول ایجاد شده
    echo "<h3>بررسی جداول ایجاد شده</h3>";
    
    $tables = ['bulk_pricing_rules', 'coupons', 'coupon_usage'];
    foreach ($tables as $table) {
        $stmt = $conn->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->fetch()) {
            echo "<p>✅ جدول $table موجود است</p>";
            
            // نمایش ساختار جدول
            $stmt = $conn->prepare("DESCRIBE $table");
            $stmt->execute();
            $columns = $stmt->fetchAll();
            
            echo "<details>";
            echo "<summary>ساختار جدول $table</summary>";
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr><th>فیلد</th><th>نوع</th><th>Null</th><th>کلید</th><th>پیش‌فرض</th></tr>";
            foreach ($columns as $column) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
                echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
                echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
                echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
                echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</details>";
        } else {
            echo "<p>❌ جدول $table موجود نیست</p>";
        }
    }
    
    // ایجاد نمونه داده‌های تست
    echo "<h3>ایجاد نمونه داده‌های تست</h3>";
    
    $testCoupons = [
        [
            'code' => 'WELCOME10',
            'description' => 'تخفیف خوش آمدگویی',
            'discount_type' => 'percentage',
            'discount_value' => 10.00,
            'valid_until' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'is_active' => 1
        ],
        [
            'code' => 'SAVE50K',
            'description' => 'تخفیف 50 هزار تومانی',
            'discount_type' => 'fixed',
            'discount_value' => 50000.00,
            'min_order_amount' => 200000.00,
            'valid_until' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'is_active' => 1
        ]
    ];
    
    foreach ($testCoupons as $coupon) {
        try {
            // بررسی وجود کد
            $stmt = $conn->prepare("SELECT id FROM coupons WHERE code = ?");
            $stmt->execute([$coupon['code']]);
            
            if (!$stmt->fetch()) {
                $stmt = $conn->prepare("
                    INSERT INTO coupons 
                    (code, description, discount_type, discount_value, min_order_amount, valid_until, is_active, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $coupon['code'],
                    $coupon['description'],
                    $coupon['discount_type'],
                    $coupon['discount_value'],
                    $coupon['min_order_amount'] ?? null,
                    $coupon['valid_until'],
                    $coupon['is_active']
                ]);
                
                echo "<p>✅ کد تخفیف " . $coupon['code'] . " ایجاد شد</p>";
            } else {
                echo "<p>⚠️ کد تخفیف " . $coupon['code'] . " قبلاً موجود است</p>";
            }
        } catch (PDOException $e) {
            echo "<p>❌ خطا در ایجاد کد " . $coupon['code'] . ": " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    echo "<h3>🎉 عملیات کامل شد!</h3>";
    echo "<p>حالا می‌توانید از قابلیت‌های جدید استفاده کنید.</p>";
    echo "<p><a href='test_features.php'>تست عملکرد قابلیت‌ها</a></p>";
    
} catch (Exception $e) {
    echo "<h3>❌ خطا در عملیات</h3>";
    echo "<p>خطا: " . htmlspecialchars($e->getMessage()) . "</p>";
} catch (PDOException $e) {
    echo "<h3>❌ خطا در پایگاه داده</h3>";
    echo "<p>خطا: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
    background-color: #f5f5f5;
}
h2 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}
h3 {
    color: #34495e;
    margin-top: 30px;
}
pre {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    overflow-x: auto;
}
table {
    width: 100%;
    margin: 10px 0;
}
th, td {
    padding: 8px;
    text-align: right;
}
details {
    margin: 10px 0;
}
summary {
    cursor: pointer;
    font-weight: bold;
}
</style>
