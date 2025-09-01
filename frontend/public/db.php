<?php
/*$host = 'localhost';
$db   = 'medical_ai';
$user = 'root';
$pass = 'owen';
$charset = 'utf8mb4';*/
$host = '161.35.186.225';
$db   = 'tyxdfrtqhh';
$user = 'tyxdfrtqhh';
$pass = '9nxSaUPMFU';
//$charset = 'utf8mb4';

//$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$dsn = "mysql:host=$host;dbname=$db";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
