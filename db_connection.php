<?php
function getPDO() {
    static $conn = null;

    if ($conn === null) {
        $config = require __DIR__ . '/config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        try {
            $conn = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            
            // Set collation to utf8mb4_general_ci to avoid collation mismatch errors
            $conn->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_general_ci'");
            $conn->exec("SET CHARACTER SET utf8mb4");
            $conn->exec("SET CHARACTER_SET_CONNECTION=utf8mb4");
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw $e;
        }
    }

    return $conn;
}
