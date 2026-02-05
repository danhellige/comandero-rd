<?php
include 'connection.php';

$estacion = strtolower($_GET['estacion'] ?? 'general');

if ($estacion == 'barra') {
    $campo_nuevo = 'nuevo_barra';
    $campo_cambios = 'cambios_barra';
} elseif ($estacion == 'alimentos') {
    $campo_nuevo = 'nuevo_alimentos';
    $campo_cambios = 'cambios_alimentos';
} elseif ($estacion == 'bebidas') {
    $campo_nuevo = 'nuevo_bebidas';
    $campo_cambios = 'cambios_bebidas';
} else {
    header('Content-Type: application/json');
    echo json_encode(['nuevo' => 0, 'cambios' => 0]);
    exit;
}

$result = mysqli_query($connect, "SELECT $campo_nuevo as nuevo, $campo_cambios as cambios FROM rd_status WHERE id = 1");
$row = mysqli_fetch_assoc($result);

header('Content-Type: application/json');
echo json_encode([
    'nuevo' => intval($row['nuevo']),
    'cambios' => intval($row['cambios'])
]);
?>