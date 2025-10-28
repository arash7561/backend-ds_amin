<?php
/**
 * اسکریپت آپدیت role کاربران از جدول admin_users
 * این فایل را یک بار اجرا کنید تا role کاربران آپدیت شود
 */

require_once __DIR__ . '/db_connection.php';

header('Content-Type: text/html; charset=UTF-8');

try {
    $conn = getPDO();
    
    echo "<h2>آپدیت role کاربران</h2>";
    
    // پیدا کردن کاربرانی که شماره موبایل آن‌ها در admin_users وجود دارد
    $stmt = $conn->prepare("
        UPDATE users u
        INNER JOIN admin_users au ON u.mobile = au.mobile
        SET u.role = 'admin'
        WHERE u.role IS NULL OR u.role = 'user'
    ");
    
    $stmt->execute();
    $affectedRows = $stmt->rowCount();
    
    echo "<p style='color: green; font-size: 18px;'>✅ تعداد $affectedRows کاربر به عنوان ادمین مشخص شد</p>";
    
    // نمایش کاربران ادمین
    echo "<h3>کاربران ادمین:</h3>";
    $stmt = $conn->prepare("SELECT id, name, mobile, role FROM users WHERE role = 'admin'");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($admins) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>نام</th><th>موبایل</th><th>Role</th></tr>";
        foreach ($admins as $admin) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($admin['id']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['name']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['mobile']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['role']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>هیچ کاربر ادمینی پیدا نشد</p>";
    }
    
    echo "<hr>";
    echo "<h3>🎉 عملیات کامل شد!</h3>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ خطا</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body {
    font-family: Tahoma, Arial, sans-serif;
    max-width: 800px;
    margin: 50px auto;
    padding: 20px;
    background-color: #f5f5f5;
}
h2 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}
table {
    width: 100%;
    margin: 20px 0;
    background: white;
    border-collapse: collapse;
}
th, td {
    padding: 10px;
    text-align: right;
    border: 1px solid #ddd;
}
th {
    background-color: #3498db;
    color: white;
}
tr:nth-child(even) {
    background-color: #f9f9f9;
}
</style>
