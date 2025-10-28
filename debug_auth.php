<?php
/**
 * اسکریپت دیباگ برای بررسی وضعیت احراز هویت و نقش کاربر
 */

require_once __DIR__ . '/db_connection.php';

header('Content-Type: text/html; charset=UTF-8');

try {
    $conn = getPDO();
    
    echo "<h2>دیباگ سیستم احراز هویت</h2>";
    
    // 1. بررسی ساختار جدول users
    echo "<h3>1. ساختار جدول users:</h3>";
    $stmt = $conn->prepare("DESCRIBE users");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>فیلد</th><th>نوع</th><th>Null</th><th>کلید</th><th>پیش‌فرض</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. بررسی کاربران و نقش آن‌ها
    echo "<h3>2. کاربران موجود:</h3>";
    $stmt = $conn->prepare("SELECT id, name, mobile, role FROM users LIMIT 10");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>نام</th><th>موبایل</th><th>Role</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['name']) . "</td>";
        echo "<td>" . htmlspecialchars($user['mobile']) . "</td>";
        echo "<td style='color: " . ($user['role'] == 'admin' ? 'green' : 'gray') . "; font-weight: bold;'>" . htmlspecialchars($user['role'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 3. بررسی کاربران ادمین از جدول admin_users
    echo "<h3>3. کاربران ادمین از جدول admin_users:</h3>";
    $stmt = $conn->prepare("SELECT id, username, mobile FROM admin_users");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Username</th><th>موبایل</th></tr>";
    foreach ($admins as $admin) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($admin['id']) . "</td>";
        echo "<td>" . htmlspecialchars($admin['username']) . "</td>";
        echo "<td>" . htmlspecialchars($admin['mobile']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 4. چک کردن تطابق بین users و admin_users
    echo "<h3>4. تطابق users و admin_users:</h3>";
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.mobile, u.role, au.id as admin_id, au.username
        FROM users u
        LEFT JOIN admin_users au ON u.mobile = au.mobile
    ");
    $stmt->execute();
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>User ID</th><th>نام</th><th>موبایل</th><th>Role</th><th>Admin ID</th><th>وضعیت</th></tr>";
    foreach ($matches as $match) {
        $status = $match['admin_id'] ? '<span style="color: green;">✓ ادمین است</span>' : '<span style="color: gray;">کاربر عادی</span>';
        echo "<tr>";
        echo "<td>" . htmlspecialchars($match['id']) . "</td>";
        echo "<td>" . htmlspecialchars($match['name']) . "</td>";
        echo "<td>" . htmlspecialchars($match['mobile']) . "</td>";
        echo "<td>" . htmlspecialchars($match['role'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($match['admin_id'] ?? 'NULL') . "</td>";
        echo "<td>" . $status . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 5. لینک برای آپدیت role
    echo "<h3>5. اقدامات:</h3>";
    echo "<p><a href='update_users_role.php' style='background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>🔄 آپدیت role همه کاربران</a></p>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ خطا</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body {
    font-family: Tahoma, Arial, sans-serif;
    max-width: 1200px;
    margin: 50px auto;
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
a:hover {
    background: #2980b9 !important;
}
</style>
