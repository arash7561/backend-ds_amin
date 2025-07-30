<?php

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ds_amin";

try{
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $conn;
}
catch(PDOException $e)
{
    echo "error : " . $e->getMessage();
    return false;
}

?>