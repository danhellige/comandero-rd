<?php
date_default_timezone_set('America/Mexico_City');
setlocale(LC_MONETARY, 'es_MX.UTF-8');

// Cargar .env
$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue; // Ignorar comentarios
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

define('LUGAR', $_ENV['LUGAR'] ?? 'DEV');

$connect = mysqli_connect(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? ''
) or die("Error de conexión");

// Cargar funciones
require_once __DIR__ . '/functions_rd.php';
?>