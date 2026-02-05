<?php
/**
 * Funciones del sistema Comandero RD
 * v5.2
 */

// Función para actualizar IDs y marcar nuevos (solo GENERAL la usa)
function actualizarNuevos($connect) {
    $result = mysqli_query($connect, "
        SELECT 
            COALESCE(MAX(CASE WHEN estacion IN ('1', '9') THEN id END), 0) as id_barra,
            COALESCE(MAX(CASE WHEN estacion IN ('2', '9') THEN id END), 0) as id_alimentos,
            COALESCE(MAX(CASE WHEN estacion = '3' THEN id END), 0) as id_bebidas,
            COALESCE(MAX(id), 0) as id_general
        FROM ordenes_rd 
        WHERE DATE(fecha_orden) = CURDATE()
    ");
    $ids = mysqli_fetch_assoc($result);
    
    $result2 = mysqli_query($connect, "SELECT * FROM rd_status WHERE id = 1");
    $anterior = mysqli_fetch_assoc($result2);
    
    $nuevo_barra = ($ids['id_barra'] > $anterior['ultimo_id_barra']) ? 1 : $anterior['nuevo_barra'];
    $nuevo_alimentos = ($ids['id_alimentos'] > $anterior['ultimo_id_alimentos']) ? 1 : $anterior['nuevo_alimentos'];
    $nuevo_bebidas = ($ids['id_bebidas'] > $anterior['ultimo_id_bebidas']) ? 1 : $anterior['nuevo_bebidas'];
    
    $query = "UPDATE rd_status SET 
        ultimo_id_barra = {$ids['id_barra']},
        ultimo_id_alimentos = {$ids['id_alimentos']},
        ultimo_id_bebidas = {$ids['id_bebidas']},
        ultimo_id_general = {$ids['id_general']},
        nuevo_barra = $nuevo_barra,
        nuevo_alimentos = $nuevo_alimentos,
        nuevo_bebidas = $nuevo_bebidas
        WHERE id = 1";
    mysqli_query($connect, $query);
}

// Función para marcar cambios a una estación
function marcarCambiosEstacion($connect, $estacion) {
    if ($estacion == '1') {
        mysqli_query($connect, "UPDATE rd_status SET cambios_barra = 1 WHERE id = 1");
    } elseif ($estacion == '2') {
        mysqli_query($connect, "UPDATE rd_status SET cambios_alimentos = 1 WHERE id = 1");
    } elseif ($estacion == '3') {
        mysqli_query($connect, "UPDATE rd_status SET cambios_bebidas = 1 WHERE id = 1");
    } elseif ($estacion == '9') {
        // Multi-estación: avisar a BARRA y COCINA
        mysqli_query($connect, "UPDATE rd_status SET cambios_barra = 1, cambios_alimentos = 1 WHERE id = 1");
    }
}

// Función para logging de errores
function logError($message, $details = '') {
    $timestamp = date('Y-m-d H:i:s');
    $semana = date('oW');
    $dia = date('Y-m-d');
    
    $logDir = __DIR__ . "/logs/$semana";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = "[$timestamp] ERROR: $message";
    if ($details) {
        $logEntry .= " - $details";
    }
    error_log($logEntry . PHP_EOL, 3, "$logDir/errors_$dia.log");
}

// Función para logging de auditoría
function logAudit($message) {
    $timestamp = date('Y-m-d H:i:s');
    $semana = date('oW');
    $dia = date('Y-m-d');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $logDir = __DIR__ . "/logs/$semana";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = "[$timestamp] $message | IP: $ip";
    error_log($logEntry . PHP_EOL, 3, "$logDir/audit_$dia.log");
}
?>