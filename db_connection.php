<?php
function getPDO() {
    static $conn = null;

    if ($conn === null) {
        $config = require 'config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        try {
            $conn = new PDO($dsn, $config['username'], $config['password'], $config['options']);
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw $e;
        }
    }

    return $conn;
}
