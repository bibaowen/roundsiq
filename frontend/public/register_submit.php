<?php
session_start();
ini_set('display_errors', '1');
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("db.php");

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare(query: "
    INSERT INTO doctors (name, email, password_hash, specialty_slug, created_at)
    VALUES (:name, :email, :password_hash, :specialty_slug, NOW())
");

$stmt->execute([
    ':name'           => $_POST['name'] ?? '',
    ':email'          => $_POST['email'] ?? '',
    ':password_hash'  => password_hash($_POST['password'] ?? '', PASSWORD_BCRYPT),
    ':specialty_slug' => $_POST['specialty_slug'] ?? '',
]);

// Get the last inserted ID (MySQL way)
$id = $pdo->lastInsertId();
echo 'Inserted doctor id: ' . $id;
