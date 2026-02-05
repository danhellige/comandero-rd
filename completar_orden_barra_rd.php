<?php
/**
 * completar_orden_barra_rd.php - Completar Solo Productos de BARRA
 * Versión RD 1.0 - Para estación BARRA únicamente
 */

// Iniciar sesión para debugging
session_start();

// Incluir conexión DB
require_once('connection.php');

// Función para redirección segura con parámetro de estación
function redirectToIndex($message = '', $type = 'info', $estacion_tipo = '') {
    $url = 'index_rd.php';
    $params = [];
    
    // Mantener parámetro de estación
    if (!empty($estacion_tipo)) {
        $params['tipo'] = $estacion_tipo;
    }
    
    // Agregar mensaje si existe
    if ($message) {
        $params['msg'] = $message;
        $params['type'] = $type;
    }
    
    // Construir URL con parámetros
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    header("Location: $url");
    exit();
}

// Validar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    logError('Método HTTP incorrecto', $_SERVER['REQUEST_METHOD']);
    redirectToIndex('Método no permitido', 'error');
}

// Validar parámetros requeridos
if (!isset($_GET["ticketid"])) {
    logError('Parámetros faltantes', 'ticketid no proporcionado');
    redirectToIndex('Parámetros incompletos', 'error');
}

// Obtener tipo de estación
$estacion_tipo = isset($_GET['tipo']) ? strtoupper(trim($_GET['tipo'])) : '';
$estaciones_validas = ['GENERAL', 'BARRA', 'ALIMENTOS', 'BEBIDAS'];

// Validar estación
if (!empty($estacion_tipo) && !in_array($estacion_tipo, $estaciones_validas)) {
    $estacion_tipo = 'BARRA';
}

// Sanitizar y validar inputs
$ticketid = trim($_GET["ticketid"]);

// Validar ticketid (debe ser numérico y positivo)
if (!ctype_digit($ticketid) || $ticketid <= 0) {
    logError('Ticketid inválido', $ticketid);
    redirectToIndex('ID de ticket inválido', 'error', $estacion_tipo);
}

// Verificar conexión a DB
if (!$connect) {
    logError('Error de conexión DB', mysqli_connect_error());
    redirectToIndex('Error de conexión a base de datos', 'error', $estacion_tipo);
}

try {
    // Verificar que existen productos de BARRA pendientes en este ticket
    $check_stmt = mysqli_prepare($connect, "
        SELECT 
            ticketid,
            cliente,
            COUNT(*) as total_barra,
            SUM(CASE WHEN station_status = 'EN_PROCESO' THEN 1 ELSE 0 END) as barra_pendientes,
            SUM(CASE WHEN station_status = 'ENTREGO_ESTACION' THEN 1 ELSE 0 END) as barra_entregados,
            MAX(completetime) as ya_completado
        FROM ordenes_rd 
        WHERE ticketid = ? AND estacion = '1'
        GROUP BY ticketid, cliente
    ");
    
    if (!$check_stmt) {
        throw new Exception('Error preparando consulta de verificación BARRA: ' . mysqli_error($connect));
    }
    
    mysqli_stmt_bind_param($check_stmt, "i", $ticketid);
    
    if (!mysqli_stmt_execute($check_stmt)) {
        throw new Exception('Error ejecutando verificación BARRA: ' . mysqli_stmt_error($check_stmt));
    }
    
    $result = mysqli_stmt_get_result($check_stmt);
    $barra_data = mysqli_fetch_assoc($result);
    
    if (!$barra_data) {
        mysqli_stmt_close($check_stmt);
        logError('No hay productos de BARRA en ticket', $ticketid);
        redirectToIndex('No hay productos de BARRA en este ticket', 'warning', $estacion_tipo);
    }
    
    mysqli_stmt_close($check_stmt);
    
    // Verificar si ya están completados los productos de BARRA
    if (!empty($barra_data['ya_completado'])) {
        logAudit("INTENTO COMPLETAR BARRA YA COMPLETADA - Ticket: $ticketid por estación: $estacion_tipo");
        redirectToIndex('Los productos de BARRA ya estaban completados', 'info', $estacion_tipo);
    }
    
    // Verificar si hay productos pendientes de BARRA
    if ($barra_data['barra_pendientes'] == 0) {
        logAudit("INTENTO COMPLETAR BARRA SIN PENDIENTES - Ticket: $ticketid | Pendientes: 0");
        redirectToIndex('No hay productos de BARRA pendientes', 'info', $estacion_tipo);
    }
    
    // Preparar información del ticket para logging
    $cliente = $barra_data['cliente'];
    $total_barra = $barra_data['total_barra'];
    $barra_pendientes = $barra_data['barra_pendientes'];
    $barra_entregados = $barra_data['barra_entregados'];
    
    // Log del estado antes de completar BARRA
    logAudit("COMPLETAR BARRA - Ticket: $ticketid | Cliente: $cliente | Total BARRA: $total_barra | Pendientes BARRA: $barra_pendientes | Entregados BARRA: $barra_entregados | Estación: $estacion_tipo");
    
    // Completar solo productos de BARRA: marcar como ENTREGO_ESTACION
    $update_stmt = mysqli_prepare($connect, "
        UPDATE ordenes_rd 
        SET station_status = 'ENTREGO_ESTACION', 
            station_completed = CURRENT_TIMESTAMP()
        WHERE ticketid = ? 
        AND estacion = '1' 
        AND station_status = 'EN_PROCESO'
    ");
    
    if (!$update_stmt) {
        throw new Exception('Error preparando consulta de completar BARRA: ' . mysqli_error($connect));
    }
    
    // Bind parámetros
    mysqli_stmt_bind_param($update_stmt, "i", $ticketid);
    
    // Ejecutar consulta
    if (!mysqli_stmt_execute($update_stmt)) {
        throw new Exception('Error ejecutando completar BARRA: ' . mysqli_stmt_error($update_stmt));
    }
    
    // Verificar si se actualizaron filas
    $affected_rows = mysqli_stmt_affected_rows($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    if ($affected_rows > 0) {
        // Éxito - productos de BARRA completados
        $success_msg = "🌮 BARRA COMPLETADA: Ticket #$ticketid ($cliente) - $affected_rows productos de BARRA entregados";
        
        // Log de auditoría exitoso
        logAudit("BARRA COMPLETADA EXITOSAMENTE - Ticket: $ticketid | Productos BARRA procesados: $affected_rows | Por estación: $estacion_tipo");
        
        // Verificar si ahora se puede auto-completar toda la orden
        $auto_complete_stmt = mysqli_prepare($connect, "
            SELECT COUNT(*) as productos_pendientes
            FROM ordenes_rd 
            WHERE ticketid = ? 
            AND station_status = 'EN_PROCESO'
            AND completetime IS NULL
        ");
        
        if ($auto_complete_stmt) {
            mysqli_stmt_bind_param($auto_complete_stmt, "i", $ticketid);
            mysqli_stmt_execute($auto_complete_stmt);
            $auto_result = mysqli_stmt_get_result($auto_complete_stmt);
            $auto_data = mysqli_fetch_assoc($auto_result);
            mysqli_stmt_close($auto_complete_stmt);
            
            // Si no hay productos pendientes, auto-completar orden
            if ($auto_data['productos_pendientes'] == 0) {
                $final_complete_stmt = mysqli_prepare($connect, "
                    UPDATE ordenes_rd 
                    SET completetime = CURRENT_TIMESTAMP()
                    WHERE ticketid = ? AND completetime IS NULL
                ");
                
                if ($final_complete_stmt) {
                    mysqli_stmt_bind_param($final_complete_stmt, "i", $ticketid);
                    mysqli_stmt_execute($final_complete_stmt);
                    $final_affected = mysqli_stmt_affected_rows($final_complete_stmt);
                    mysqli_stmt_close($final_complete_stmt);
                    
                    if ($final_affected > 0) {
                        logAudit("AUTO-COMPLETAR ORDEN DESPUÉS DE BARRA - Ticket: $ticketid | Productos finales: $final_affected");
                        $success_msg .= " ✅ ORDEN COMPLETA AUTO-FINALIZADA";
                    }
                }
            }
        }
        
        // BARRA completó productos, avisar a GENERAL para que lo vea
        mysqli_query($connect, "UPDATE rd_status SET cambios_barra = 1 WHERE id = 1");
        redirectToIndex($success_msg, 'success', $estacion_tipo);
        
    } elseif ($affected_rows === 0) {
        // No se actualizó - posible condición de carrera
        logAudit("COMPLETAR BARRA SIN CAMBIOS - Ticket: $ticketid ya estaba completado");
        redirectToIndex('Los productos de BARRA ya estaban entregados', 'info', $estacion_tipo);
        
    } else {
        // Error inesperado
        throw new Exception('Resultado inesperado en BARRA: affected_rows = ' . $affected_rows);
    }
    
} catch (Exception $e) {
    // Manejo de errores
    logError('Excepción en completar BARRA', $e->getMessage());
    redirectToIndex('Error interno del servidor', 'error', $estacion_tipo);
    
} finally {
    // Limpiar recursos
    if (isset($check_stmt) && $check_stmt) {
        mysqli_stmt_close($check_stmt);
    }
    if (isset($update_stmt) && $update_stmt) {
        mysqli_stmt_close($update_stmt);
    }
    if (isset($auto_complete_stmt) && $auto_complete_stmt) {
        mysqli_stmt_close($auto_complete_stmt);
    }
    if (isset($final_complete_stmt) && $final_complete_stmt) {
        mysqli_stmt_close($final_complete_stmt);
    }
    if ($connect) {
        mysqli_close($connect);
    }
}

// Fallback - no debería llegar aquí
redirectToIndex('Error inesperado en BARRA', 'error', $estacion_tipo);
?>