<?php
/**
 * Funciones de procesamiento de tickets
 */

function procesarYGuardarTickets($connect) {
    $query_tickets = "
    SELECT 
        t.id as ticket_uuid,
        t.ticketid,
        COALESCE(c.name, 'Cliente') as cliente,
        r.datenew as fecha_orden
    FROM tickets t
    JOIN receipts r ON t.id = r.id  
    LEFT JOIN customers c ON t.customer = c.id
    WHERE DATE(r.datenew) = CURDATE()
    AND NOT EXISTS (
        SELECT 1 FROM ordenes_rd ord 
        WHERE ord.ticket_uuid = t.id
    )
    ORDER BY r.datenew DESC
    ";
    
    $result_tickets = mysqli_query($connect, $query_tickets);
    if (!$result_tickets) {
        logError('Error obteniendo tickets', mysqli_error($connect));
        return false;
    }
    
    $procesados = 0;
    while ($ticket = mysqli_fetch_assoc($result_tickets)) {
        procesarTicketIndividual($connect, $ticket);
        $procesados++;
    }
    
    if ($procesados > 0) {
        logAudit("PROCESADOS: $procesados tickets nuevos");
    }
    
    return true;
}

function procesarTicketIndividual($connect, $ticket) {
    $query_lineas = "
    SELECT 
        tl.line,
        p.name as producto,
        p.id as producto_id,
        p.iscom as es_auxiliar,
        p.printto as estacion,
        tl.units as cantidad
    FROM ticketlines tl  
    JOIN products p ON tl.product = p.id
    WHERE tl.ticket = ?
    ORDER BY tl.line ASC
    ";
    
    $stmt = mysqli_prepare($connect, $query_lineas);
    mysqli_stmt_bind_param($stmt, "s", $ticket['ticket_uuid']);
    mysqli_stmt_execute($stmt);
    $result_lineas = mysqli_stmt_get_result($stmt);
    
    $current_producto_padre = null;
    $auxiliares_actuales = [];
    $tipo_servicio_actual = 'LOCAL';
    $grupo_numero = 1;
    
    while ($linea = mysqli_fetch_assoc($result_lineas)) {
        $nombre_producto = $linea['producto'];
        
        if ($nombre_producto === '---P/LLEVAR---') {
            if ($current_producto_padre !== null) {
                guardarProductoEnRD($connect, $ticket, $current_producto_padre, $auxiliares_actuales, $tipo_servicio_actual, $grupo_numero);
                $current_producto_padre = null;
                $auxiliares_actuales = [];
                $grupo_numero++; 
            }
            $tipo_servicio_actual = 'PARA_LLEVAR';
            continue;
        } elseif ($nombre_producto === '---LOCAL---') {
            if ($current_producto_padre !== null) {
                guardarProductoEnRD($connect, $ticket, $current_producto_padre, $auxiliares_actuales, $tipo_servicio_actual, $grupo_numero);
                $current_producto_padre = null;
                $auxiliares_actuales = [];
                $grupo_numero++;
            }
            $tipo_servicio_actual = 'LOCAL';
            continue;
        } elseif ($nombre_producto === 'CAMINERA') {
            if ($current_producto_padre !== null) {
                guardarProductoEnRD($connect, $ticket, $current_producto_padre, $auxiliares_actuales, $tipo_servicio_actual, $grupo_numero);
                $current_producto_padre = null;
                $auxiliares_actuales = [];
                $grupo_numero++;
            }
            $tipo_servicio_actual = 'CAMINERA';
            continue;
        }
        
        if (strpos($nombre_producto, '---') === 0) {
            continue;
        }
        
        if ($linea['es_auxiliar'] == 0) {
            if ($current_producto_padre !== null) {
                guardarProductoEnRD($connect, $ticket, $current_producto_padre, $auxiliares_actuales, $tipo_servicio_actual, $grupo_numero);
            }
            $current_producto_padre = $linea;
            $auxiliares_actuales = [];
        } else {
            $auxiliares_actuales[] = $linea;
        }
    }
    
    if ($current_producto_padre !== null) {
        guardarProductoEnRD($connect, $ticket, $current_producto_padre, $auxiliares_actuales, $tipo_servicio_actual, $grupo_numero);
    }
    
    mysqli_stmt_close($stmt);
}

function guardarProductoEnRD($connect, $ticket, $producto_padre, $auxiliares, $tipo_servicio, $grupo_numero) {
    $auxiliares_nombres = array_map(function($aux) {
        return $aux['producto'];
    }, $auxiliares);
    $auxiliares_json = json_encode($auxiliares_nombres);
    
    $insert_query = "
    INSERT INTO ordenes_rd 
    (ticket_uuid, ticketid, cliente, fecha_orden, producto_padre, producto_padre_id, 
     auxiliares, estacion, cantidad, station_status, station_completed, completetime, tipo_servicio, grupo_numero)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'EN_PROCESO', NULL, NULL, ?, ?)
    ";
    
    $stmt = mysqli_prepare($connect, $insert_query);
    
    mysqli_stmt_bind_param($stmt, "sisssssissi", 
        $ticket['ticket_uuid'],
        $ticket['ticketid'],
        $ticket['cliente'],
        $ticket['fecha_orden'],
        $producto_padre['producto'],
        $producto_padre['producto_id'],
        $auxiliares_json,
        $producto_padre['estacion'],
        $producto_padre['cantidad'],
        $tipo_servicio,
        $grupo_numero
    );
    
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Ejecutar procesamiento
procesarYGuardarTickets($connect);
?>