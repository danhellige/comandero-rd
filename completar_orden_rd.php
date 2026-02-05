<?php
/**
 * completar_orden_rd.php - Completar Órdenes Manualmente
 * Versión RD 1.0 - Para GENERAL y casos excepcionales
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
    $estacion_tipo = 'GENERAL';
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
    // Primero verificar que el ticket existe y no está completado
    $check_stmt = mysqli_prepare($connect, "
        SELECT 
            ticketid,
            cliente,
            COUNT(*) as total_productos,
            SUM(CASE WHEN station_status = 'EN_PROCESO' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN station_status = 'ENTREGO_ESTACION' THEN 1 ELSE 0 END) as entregados,
            SUM(CASE WHEN station_status = 'QUITADO' THEN 1 ELSE 0 END) as quitados,
            MAX(completetime) as ya_completado
        FROM ordenes_rd 
        WHERE ticketid = ?
        GROUP BY ticketid, cliente
    ");
    
    if (!$check_stmt) {
        throw new Exception('Error preparando consulta de verificación: ' . mysqli_error($connect));
    }
    
    mysqli_stmt_bind_param($check_stmt, "i", $ticketid);
    
    if (!mysqli_stmt_execute($check_stmt)) {
        throw new Exception('Error ejecutando verificación: ' . mysqli_stmt_error($check_stmt));
    }
    
    $result = mysqli_stmt_get_result($check_stmt);
    $ticket_data = mysqli_fetch_assoc($result);
    
    if (!$ticket_data) {
        mysqli_stmt_close($check_stmt);
        logError('Ticket no encontrado', $ticketid);
        redirectToIndex('Ticket no encontrado', 'warning', $estacion_tipo);
    }
    
    mysqli_stmt_close($check_stmt);
    
    // Verificar permisos: Solo GENERAL puede completar órdenes manualmente
    if ($estacion_tipo !== 'GENERAL') {
        logError('Estación sin permisos para completar órdenes', "Estación: $estacion_tipo, Ticket: $ticketid");
        redirectToIndex('Solo GENERAL puede completar órdenes manualmente', 'warning', $estacion_tipo);
    }
    
    // Verificar si ya está completado
    if (!empty($ticket_data['ya_completado'])) {
        logAudit("INTENTO COMPLETAR ORDEN YA COMPLETADA - Ticket: $ticketid por estación: $estacion_tipo");
        redirectToIndex('La orden ya estaba completada anteriormente', 'info', $estacion_tipo);
    }
    
    // Preparar información del ticket para logging
    $cliente = $ticket_data['cliente'];
    $total_productos = $ticket_data['total_productos'];
    $pendientes = $ticket_data['pendientes'];
    $entregados = $ticket_data['entregados'];
    $quitados = $ticket_data['quitados'];
    
    // Log del estado antes de completar
    logAudit("COMPLETAR ORDEN MANUAL - Ticket: $ticketid | Cliente: $cliente | Total productos: $total_productos | Pendientes: $pendientes | Entregados: $entregados | Quitados: $quitados | Estación: $estacion_tipo");
    
    // Completar la orden: marcar completetime en todos los productos del ticket
    $update_stmt = mysqli_prepare($connect, "
        UPDATE ordenes_rd 
        SET completetime = CURRENT_TIMESTAMP()
        WHERE ticketid = ? AND completetime IS NULL
    ");
    
    if (!$update_stmt) {
        throw new Exception('Error preparando consulta de completar: ' . mysqli_error($connect));
    }
    
    // Bind parámetros
    mysqli_stmt_bind_param($update_stmt, "i", $ticketid);
    
    // Ejecutar consulta
    if (!mysqli_stmt_execute($update_stmt)) {
        throw new Exception('Error ejecutando completar orden: ' . mysqli_stmt_error($update_stmt));
    }
    
    // Verificar si se actualizaron filas
    $affected_rows = mysqli_stmt_affected_rows($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    if ($affected_rows > 0) {
        // Éxito - orden completada
        $success_msg = "🎉 ORDEN COMPLETADA: Ticket #$ticketid ($cliente) - $affected_rows productos procesados";
        
        // Log de auditoría exitoso
        logAudit("ORDEN COMPLETADA EXITOSAMENTE - Ticket: $ticketid | Productos procesados: $affected_rows | Por estación: $estacion_tipo");
        
        // Avisar a todas las estaciones que GENERAL completó una orden
        mysqli_query($connect, "UPDATE rd_status SET cambios_barra = 1, cambios_alimentos = 1, cambios_bebidas = 1 WHERE id = 1");
        redirectToIndex($success_msg, 'success', $estacion_tipo);
        
    } elseif ($affected_rows === 0) {
        // No se actualizó - posible condición de carrera (ya completado)
        logAudit("COMPLETAR ORDEN SIN CAMBIOS - Ticket: $ticketid ya estaba completado");
        redirectToIndex('La orden ya estaba completada', 'info', $estacion_tipo);
        
    } else {
        // Error inesperado
        throw new Exception('Resultado inesperado: affected_rows = ' . $affected_rows);
    }
    
} catch (Exception $e) {
    // Manejo de errores
    logError('Excepción en completar orden', $e->getMessage());
    redirectToIndex('Error interno del servidor', 'error', $estacion_tipo);
    
} finally {
    // Limpiar recursos
    if (isset($check_stmt) && $check_stmt) {
        mysqli_stmt_close($check_stmt);
    }
    if (isset($update_stmt) && $update_stmt) {
        mysqli_stmt_close($update_stmt);
    }
    if ($connect) {
        mysqli_close($connect);
    }
}

// Fallback - no debería llegar aquí
redirectToIndex('Error inesperado', 'error', $estacion_tipo);
?>