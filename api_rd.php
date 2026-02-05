<?php
include 'connection.php';

$accion = $_GET['accion'] ?? '';
$estacion = strtolower($_GET['estacion'] ?? '');

header('Content-Type: application/json');

switch ($accion) {
    case 'check':
        // Verificar si hay nuevos o cambios
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
            echo json_encode(['nuevo' => 0, 'cambios' => 0]);
            exit;
        }
        
        $result = mysqli_query($connect, "SELECT $campo_nuevo as nuevo, $campo_cambios as cambios FROM rd_status WHERE id = 1");
        $row = mysqli_fetch_assoc($result);
        
        echo json_encode([
            'nuevo' => intval($row['nuevo']),
            'cambios' => intval($row['cambios'])
        ]);
        break;
        
    case 'enterado_nuevo':
        // Marcar que la estación ya se enteró de los nuevos
        if ($estacion == 'barra') {
            mysqli_query($connect, "UPDATE rd_status SET nuevo_barra = 0 WHERE id = 1");
        } elseif ($estacion == 'alimentos') {
            mysqli_query($connect, "UPDATE rd_status SET nuevo_alimentos = 0 WHERE id = 1");
        } elseif ($estacion == 'bebidas') {
            mysqli_query($connect, "UPDATE rd_status SET nuevo_bebidas = 0 WHERE id = 1");
        }
        
        echo json_encode(['ok' => true]);
        break;
        
    case 'enterado_cambios':
        // Marcar que la estación ya se enteró de los cambios
        if ($estacion == 'barra') {
            mysqli_query($connect, "UPDATE rd_status SET cambios_barra = 0 WHERE id = 1");
        } elseif ($estacion == 'alimentos') {
            mysqli_query($connect, "UPDATE rd_status SET cambios_alimentos = 0 WHERE id = 1");
        } elseif ($estacion == 'bebidas') {
            mysqli_query($connect, "UPDATE rd_status SET cambios_bebidas = 0 WHERE id = 1");
        }
        
        echo json_encode(['ok' => true]);
        break;

    case 'status':
        $result = mysqli_query($connect, "SELECT ultimo_ticket, ultima_ejecucion FROM rd_status WHERE id = 1");
        $row = mysqli_fetch_assoc($result);
        
        echo json_encode([
            'ultimo_ticket' => $row['ultimo_ticket'] ?? 0,
            'ultima_ejecucion' => $row['ultima_ejecucion'] ? date('H:i:s', strtotime($row['ultima_ejecucion'])) : '--:--:--'
        ]);
        break;
        
    default:
        echo json_encode(['error' => 'Acción no válida']);
}
?>