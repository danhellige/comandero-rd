<?php
/**
 * cambiar_tipo_rd.php - Cambiar tipo de servicio por GRUPO
 * Versión RD 3.0 - Compatible con botón cíclico por grupo
 */

// Iniciar sesión para debugging
session_start();

// Incluir conexión DB
require_once('connection.php');

// Función para redirección segura
function redirectToIndex($message = '', $type = 'info', $estacion_tipo = '') {
    $url = 'index_rd.php';
    $params = [];
    
    if (!empty($estacion_tipo)) {
        $params['tipo'] = $estacion_tipo;
    }
    
    if ($message) {
        $params['msg'] = $message;
        $params['type'] = $type;
    }
    
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
if (!isset($_GET["ticketid"]) || !isset($_GET["grupo"])) {
    logError('Parámetros faltantes', 'ticketid o grupo no proporcionado');
    redirectToIndex('Parámetros incompletos', 'error');
}

// Obtener tipo de estación
$estacion_tipo = isset($_GET['tipo']) ? strtoupper(trim($_GET['tipo'])) : '';

// SOLO GENERAL puede cambiar tipos de servicio
if ($estacion_tipo !== 'GENERAL') {
    logError('Estación sin permisos para cambiar tipo', "Estación: $estacion_tipo");
    redirectToIndex('Solo GENERAL puede cambiar tipos de servicio', 'error', $estacion_tipo);
}

// Sanitizar y validar inputs
$ticketid = trim($_GET["ticketid"]);
$grupo_numero = trim($_GET["grupo"]);

// Validar ticketid
if (!ctype_digit($ticketid) || $ticketid <= 0) {
    logError('Ticketid inválido', $ticketid);
    redirectToIndex('ID de ticket inválido', 'error', $estacion_tipo);
}

// Validar grupo_numero
if (!ctype_digit($grupo_numero) || $grupo_numero <= 0) {
    logError('Grupo número inválido', $grupo_numero);
    redirectToIndex('Número de grupo inválido', 'error', $estacion_tipo);
}

// Verificar conexión a DB
if (!$connect) {
    logError('Error de conexión DB', mysqli_connect_error());
    redirectToIndex('Error de conexión a base de datos', 'error', $estacion_tipo);
}

try {
    // Verificar que el grupo existe y obtener tipo actual
    $check_stmt = mysqli_prepare($connect, "
        SELECT 
            ticketid,
            cliente,
            grupo_numero,
            tipo_servicio,
            COUNT(*) as total_productos,
            SUM(CASE WHEN station_status = 'EN_PROCESO' THEN 1 ELSE 0 END) as pendientes,
            SUM(CASE WHEN completetime IS NOT NULL THEN 1 ELSE 0 END) as completados
        FROM ordenes_rd 
        WHERE ticketid = ? AND grupo_numero = ?
        GROUP BY ticketid, cliente, grupo_numero, tipo_servicio
    ");
    
    if (!$check_stmt) {
        throw new Exception('Error preparando consulta de verificación: ' . mysqli_error($connect));
    }
    
    mysqli_stmt_bind_param($check_stmt, "ii", $ticketid, $grupo_numero);
    
    if (!mysqli_stmt_execute($check_stmt)) {
        throw new Exception('Error ejecutando verificación: ' . mysqli_stmt_error($check_stmt));
    }
    
    $result = mysqli_stmt_get_result($check_stmt);
    $grupo_data = mysqli_fetch_assoc($result);
    
    if (!$grupo_data) {
        mysqli_stmt_close($check_stmt);
        logError('Grupo no encontrado', "Ticket: $ticketid, Grupo: $grupo_numero");
        redirectToIndex('Grupo no encontrado', 'warning', $estacion_tipo);
    }
    
    mysqli_stmt_close($check_stmt);
    
    // Verificar si el grupo está completamente terminado
    if ($grupo_data['pendientes'] == 0 && $grupo_data['completados'] == $grupo_data['total_productos']) {
        logError('Intento cambiar tipo en grupo completado', "Ticket: $ticketid, Grupo: $grupo_numero");
        redirectToIndex('No se puede cambiar tipo de servicio en grupo completado', 'warning', $estacion_tipo);
    }
    
    $tipo_actual = $grupo_data['tipo_servicio'] ?? 'LOCAL';
    $cliente = $grupo_data['cliente'];
    $total_productos = $grupo_data['total_productos'];
    $pendientes = $grupo_data['pendientes'];
    
    // Determinar nuevo tipo de servicio (CICLO DE 3 TIPOS)
    // LÓGICA CÍCLICA: LOCAL → PARA_LLEVAR → CAMINERA → LOCAL
    if ($tipo_actual === 'LOCAL') {
        $nuevo_tipo = 'PARA_LLEVAR';
    } elseif ($tipo_actual === 'PARA_LLEVAR') {
        $nuevo_tipo = 'CAMINERA';
    } else {
        $nuevo_tipo = 'LOCAL';
    }
    
    // Verificar si realmente hay cambio
    if ($tipo_actual === $nuevo_tipo) {
        logAudit("CAMBIO TIPO SIN EFECTO - Ticket: $ticketid, Grupo: $grupo_numero ya era $nuevo_tipo");
        redirectToIndex("El grupo ya era de tipo $nuevo_tipo", 'info', $estacion_tipo);
    }
    
    // Log del estado antes de cambiar
    logAudit("CAMBIO TIPO GRUPO INICIADO - Ticket: $ticketid | Grupo: $grupo_numero | Cliente: $cliente | Productos: $total_productos | Pendientes: $pendientes | $tipo_actual → $nuevo_tipo | Estación: $estacion_tipo");
    
    // Actualizar tipo de servicio en TODO EL GRUPO
    $update_stmt = mysqli_prepare($connect, "
        UPDATE ordenes_rd 
        SET tipo_servicio = ?
        WHERE ticketid = ? AND grupo_numero = ?
    ");
    
    if (!$update_stmt) {
        throw new Exception('Error preparando consulta de actualización: ' . mysqli_error($connect));
    }
    
    mysqli_stmt_bind_param($update_stmt, "sii", $nuevo_tipo, $ticketid, $grupo_numero);
    
    if (!mysqli_stmt_execute($update_stmt)) {
        throw new Exception('Error ejecutando actualización: ' . mysqli_stmt_error($update_stmt));
    }
    
    $affected_rows = mysqli_stmt_affected_rows($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    if ($affected_rows > 0) {
        // Éxito - tipo cambiado en todo el grupo
        $emoji_tipo = '';
        switch ($nuevo_tipo) {
            case 'LOCAL':
                $emoji_tipo = '📍 LOCAL';
                break;
            case 'PARA_LLEVAR':
                $emoji_tipo = '📦 P/LLEVAR';
                break;
            case 'CAMINERA':
                $emoji_tipo = '🚚 CAMINERA';
                break;
        }
        
        $success_msg = "🔄 GRUPO CAMBIADO: Ticket #$ticketid - Grupo $grupo_numero ($cliente) → $emoji_tipo ($affected_rows productos actualizados)";
        
        // Log de auditoría exitoso
        logAudit("CAMBIO TIPO GRUPO EXITOSO - Ticket: $ticketid | Grupo: $grupo_numero | $tipo_actual → $nuevo_tipo | Productos actualizados: $affected_rows | Estación: $estacion_tipo");
        
        // Obtener qué estaciones tienen productos en este grupo y avisarles
        $estaciones_stmt = mysqli_prepare($connect, "
            SELECT DISTINCT estacion FROM ordenes_rd 
            WHERE ticketid = ? AND grupo_numero = ?
        ");
        mysqli_stmt_bind_param($estaciones_stmt, "ii", $ticketid, $grupo_numero);
        mysqli_stmt_execute($estaciones_stmt);
        $estaciones_result = mysqli_stmt_get_result($estaciones_stmt);

        while ($est = mysqli_fetch_assoc($estaciones_result)) {
            marcarCambiosEstacion($connect, $est['estacion']);
        }
        mysqli_stmt_close($estaciones_stmt);

        redirectToIndex($success_msg, 'success', $estacion_tipo);
        
    } elseif ($affected_rows === 0) {
        logError('Grupo no se pudo actualizar tipo', "Ticket: $ticketid, Grupo: $grupo_numero, Tipo: $nuevo_tipo");
        redirectToIndex('El grupo no se pudo actualizar', 'warning', $estacion_tipo);
        
    } else {
        throw new Exception('Resultado inesperado: affected_rows = ' . $affected_rows);
    }
    
} catch (Exception $e) {
    logError('Excepción en cambiar tipo servicio por grupo', $e->getMessage());
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

// Fallback
redirectToIndex('Error inesperado', 'error', $estacion_tipo);
?>