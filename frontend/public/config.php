<?php
// public/config.php
if (session_status() === PHP_SESSION_NONE) session_start();

// 1) load private overrides if present
$APP_VARS = [];
$priv = __DIR__ . '/../private_html/config.local.php';
if (file_exists($priv)) require $priv;

// 2) read from env first, else from $APP_VARS
function v($k, $def = null){
    global $APP_VARS;
    $e = getenv($k);
    if ($e !== false && $e !== '') return $e;
    return $APP_VARS[$k] ?? $def;
}

// --- DB ---
$DB_HOST = v('DB_HOST', '127.0.0.1');
$DB_PORT = (int) v('DB_PORT', '3306');
$DB_NAME = v('DB_NAME', 'medical_ai');
$DB_USER = v('DB_USER', 'root');
$DB_PASS = v('DB_PASS', '');

// PDO
$dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
$pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// API base for your Render service
$API_BASE_URL = rtrim(v('API_BASE_URL', 'http://localhost:5000'), '/');
