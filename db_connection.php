<?php

// Load database configuration
$config = require_once 'config/database.php';

try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $conn = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    return $conn;
} catch(PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    return false;
}

?>