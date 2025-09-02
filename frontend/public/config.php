<?php
/**
 * Global configuration file
 * 
 * Reads environment variables from Cloudways Application → Variables.
 * Include this at the top of any PHP script that needs DB or API access.
 */

// --- Database ---
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_NAME = getenv('DB_NAME') ?: 'medical_ai';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';
$DB_PORT = getenv('DB_PORT') ?: 3306;

// PDO connection (preferred over mysqli for error handling)
try {
    $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- API Base URL (your Render Flask API) ---
$API_BASE_URL = getenv('API_BASE_URL') ?: 'http://localhost:5000';

// --- Security helpers (optional) ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Simple CSRF token generator/checker
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_token() {
    return $_SESSION['csrf_token'];
}
function verify_csrf($token) {
    return hash_equals($_SESSION['csrf_token'], $token);
}
?>
