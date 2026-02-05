<?php
/**
 * Comandero RD - Remote Display con Lectura de Tickets
 * Versión 5.1 - BARRA simplificada + Orden cronológico corregido
 */

// Incluir conexión DB con manejo de errores
try {
    include_once 'connection.php';
    if (!$connect) {
        throw new Exception('Error de conexión: ' . (mysqli_connect_error() ?? 'Desconocido'));
    }
} catch (Exception $e) {
    $error_message = "Error de conexión a la base de datos. Contacte al administrador.";
    exit($error_message);
}

// Determinar tipo de estación
$estacion_tipo = strtoupper($_GET['tipo'] ?? 'GENERAL');
$estaciones_validas = ['GENERAL', 'BARRA', 'ALIMENTOS', 'BEBIDAS'];

if (!in_array($estacion_tipo, $estaciones_validas)) {
    $estacion_tipo = 'GENERAL';
}

// Función para escapar output HTML
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Función para formatear tiempo transcurrido
function timeElapsed($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $total_seconds = $now->getTimestamp() - $ago->getTimestamp();
    $total_minutes = floor($total_seconds / 60);
    
    if ($total_minutes > 0) {
        return $total_minutes . ' min';
    } else {
        return 'Recién';
    }
}

// Función para auto-completar órdenes donde todas las estaciones terminaron
function autoCompletarOrdenesListas($connect) {
    // Buscar órdenes donde todos los productos están ENTREGO_ESTACION
    $query = "
        SELECT ticketid, MAX(station_completed) as ultima_entrega
        FROM ordenes_rd 
        WHERE DATE(fecha_orden) = CURDATE()
        AND completetime IS NULL
        AND estacion != '0'
        GROUP BY ticketid
        HAVING COUNT(*) = SUM(CASE WHEN station_status = 'ENTREGO_ESTACION' THEN 1 ELSE 0 END)
    ";
    
    $result = mysqli_query($connect, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Completar orden con la fecha de la última entrega
            $update = "UPDATE ordenes_rd 
                       SET completetime = ? 
                       WHERE ticketid = ? AND completetime IS NULL";
            $stmt = mysqli_prepare($connect, $update);
            mysqli_stmt_bind_param($stmt, "si", $row['ultima_entrega'], $row['ticketid']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            
            // Log de auditoría
            error_log("[AUTO-COMPLETE] Ticket {$row['ticketid']} completado automáticamente con fecha {$row['ultima_entrega']}");
        }
    }
}

// Función para leer órdenes de ordenes_rd AGRUPADAS por ticket - ORDEN CRONOLÓGICO
function obtenerOrdenesRD($connect, $estacion_tipo) {
    // Auto-completar órdenes donde todas las estaciones terminaron
    autoCompletarOrdenesListas($connect);
    
    if ($estacion_tipo === 'GENERAL') {
        // GENERAL solo ve órdenes ACTIVAS (sin completar)
        $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND estacion != '0'";
        $order_clause = "ORDER BY fecha_orden ASC, ticketid ASC, grupo_numero ASC, id ASC";
    } elseif ($estacion_tipo === 'BARRA') {
        // BARRA ve solo SUS productos EN_PROCESO - MÁS VIEJA PRIMERO
        $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND estacion IN ('1', '9') AND station_status = 'EN_PROCESO'";
        $order_clause = "ORDER BY fecha_orden ASC, ticketid ASC, grupo_numero ASC, id ASC";
    } else {
        // ALIMENTOS/BEBIDAS - solo sus productos EN_PROCESO - MÁS VIEJA PRIMERO
        if ($estacion_tipo === 'ALIMENTOS') {
            $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND station_status = 'EN_PROCESO' AND estacion IN ('2', '9')";
        } elseif ($estacion_tipo === 'BEBIDAS') {
            $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND station_status = 'EN_PROCESO' AND estacion = '3'";
        } else {
            $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND station_status = 'EN_PROCESO' AND estacion = '1'";
        }
        $order_clause = "ORDER BY fecha_orden ASC, ticketid ASC, grupo_numero ASC, id ASC";
    }
    
    $query = "
    SELECT 
        *,
        TIMESTAMPDIFF(MINUTE, fecha_orden, NOW()) AS minutos_transcurridos
    FROM ordenes_rd 
    WHERE $where_clause
    $order_clause
    ";
    
    // Log de debug para verificar la consulta
    error_log("QUERY ORDENES RD ($estacion_tipo): " . $query);
    
    $result = mysqli_query($connect, $query);
    
    // Verificar si la consulta falló
    if (!$result) {
        error_log("ERROR EN CONSULTA: " . mysqli_error($connect));
        return [];
    }
    
    $tickets_agrupados = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $ticket_id = $row['ticketid'];
            
            // Decodificar auxiliares JSON
            $row['auxiliares_array'] = json_decode($row['auxiliares'], true) ?? [];
            
            // Asegurar tipo_servicio por compatibilidad
            if (!isset($row['tipo_servicio']) || empty($row['tipo_servicio'])) {
                $row['tipo_servicio'] = 'LOCAL';
            }
            
            // Asegurar grupo_numero por compatibilidad
            if (!isset($row['grupo_numero']) || empty($row['grupo_numero'])) {
                $row['grupo_numero'] = 1;
            }
            
            // Agrupar por ticket
            if (!isset($tickets_agrupados[$ticket_id])) {
                $tickets_agrupados[$ticket_id] = [
                    'ticketid' => $row['ticketid'],
                    'ticket_uuid' => $row['ticket_uuid'],
                    'cliente' => $row['cliente'],
                    'fecha_orden' => $row['fecha_orden'],
                    'minutos_transcurridos' => $row['minutos_transcurridos'],
                    'completetime' => $row['completetime'],
                    'productos' => [],
                    'grupos' => [] // Nueva agrupación por grupo_numero
                ];
            }
            
            // Agregar producto al ticket (mantener array original)
            $tickets_agrupados[$ticket_id]['productos'][] = $row;
            
            // Agrupar por grupo_numero
            $grupo_num = $row['grupo_numero'];
            if (!isset($tickets_agrupados[$ticket_id]['grupos'][$grupo_num])) {
                $tickets_agrupados[$ticket_id]['grupos'][$grupo_num] = [
                    'numero' => $grupo_num,
                    'tipo_servicio' => $row['tipo_servicio'],
                    'productos' => []
                ];
            }
            
            // Agregar producto al grupo
            $tickets_agrupados[$ticket_id]['grupos'][$grupo_num]['productos'][] = $row;
        }
    }
    
    return $tickets_agrupados;
}

// Solo GENERAL actualiza los IDs y marca nuevos
if ($estacion_tipo === 'GENERAL') {
    actualizarNuevos($connect);
}

// Obtener órdenes agrupadas por ticket
$tickets = obtenerOrdenesRD($connect, $estacion_tipo);

// Configuración de colores por estación
if ($estacion_tipo === 'GENERAL') {
    $config_estacion = [
        'titulo' => 'GENERAL',
        'color_primario' => '#00ff88',
        'color_secundario' => '#44ff44',
        'emoji' => '👨‍🍳'
    ];
} elseif ($estacion_tipo === 'BARRA') {
    $config_estacion = [
        'titulo' => 'BARRA',
        'color_primario' => '#ff4444',
        'color_secundario' => '#44ff44',
        'emoji' => '🌮'
    ];
} elseif ($estacion_tipo === 'ALIMENTOS') {
    $config_estacion = [
        'titulo' => 'COCINA',
        'color_primario' => '#4488ff',
        'color_secundario' => '#44ff44',
        'emoji' => '🍽️'
    ];
} else {
    $config_estacion = [
        'titulo' => 'BEBIDAS',
        'color_primario' => '#ff8844',
        'color_secundario' => '#44ff44',
        'emoji' => '🥤'
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comandero <?php echo h($config_estacion['titulo']); ?> - RD</title>
    <style>
        :root {
            --color-primario: <?php echo $config_estacion['color_primario']; ?>;
            --color-secundario: <?php echo $config_estacion['color_secundario']; ?>;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #000;
            margin: 0;
            padding: 10px;
            font-family: 'Arial', sans-serif;
            overflow-x: hidden;
            color: #fff;
        }
        
        /* ===== HEADER ULTRA-COMPACTO ===== */
        .header-rd {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border-bottom: 3px solid var(--color-primario);
            padding: 3px 10px;
            z-index: 1000;
            text-align: center;
        }
        
        .titulo-rd {
            color: var(--color-primario);
            font-size: 1.4rem;
            font-weight: bold;
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.7);
        }
        
        .main-content {
            margin-top: 60px;
        }
        
        .ticket-card {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border: 2px solid #333;
            border-radius: 10px;
            margin-bottom: 20px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            transition: all 0.3s ease;
        }
        
        .ticket-card.orden-completada {
            opacity: 0.5;
            border-color: #44ff44;
            background: linear-gradient(135deg, #0a2a0a 0%, #1a3a1a 100%);
        }
        
        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #444;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .ticket-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .ticket-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .ticket-numero {
            color: var(--color-primario);
            font-size: 2rem;
            font-weight: bold;
        }
        
        .ticket-cliente {
            color: #44ff44;
            font-size: 1.5rem;
        }
        
        .ticket-fecha {
            color: #ffa500;
            font-size: 1.2rem;
        }
        
        .completada-badge {
            background: linear-gradient(135deg, rgba(68,255,68,0.2) 0%, rgba(100,255,100,0.3) 100%);
            color: #44ff44;
            border: 2px solid rgba(68,255,68,0.4);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ===== GRUPOS CON BADGE VERTICAL ===== */
        .grupo-contenedor {
            background: rgba(255,255,255,0.03);
            border: 2px solid #555;
            border-radius: 12px;
            margin: 15px 0;
            padding: 0;
            overflow: hidden;
            display: flex;
        }
        
        .grupo-badge-vertical {
            writing-mode: vertical-rl;
            text-orientation: mixed;
            padding: 15px 8px;
            font-size: 0.9rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 45px;
            border-right: 2px solid rgba(255,255,255,0.1);
        }
        
        .grupo-local-vertical {
            background: linear-gradient(to bottom, rgba(68,136,255,0.3) 0%, rgba(100,150,255,0.4) 100%);
            color: #4488ff;
            border-right-color: rgba(68,136,255,0.4);
        }
        
        .grupo-para-llevar-vertical {
            background: linear-gradient(to bottom, rgba(255,165,0,0.3) 0%, rgba(255,140,0,0.4) 100%);
            color: #ffa500;
            border-right-color: rgba(255,165,0,0.4);
        }
        
        .grupo-caminera-vertical {
            background: linear-gradient(to bottom, rgba(138,43,226,0.3) 0%, rgba(138,43,226,0.5) 100%);
            color: #8a2be2;
            border-right-color: rgba(138,43,226,0.4);
        }
        
        .grupo-productos {
            flex: 1;
            padding: 10px;
        }
        
        .producto-padre {
            background: rgba(255,255,255,0.05);
            border-left: 4px solid var(--color-primario);
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .producto-padre:hover {
            background: rgba(255,255,255,0.08);
            border-left-width: 6px;
            transform: translateX(2px);
        }
        
        /* Estilos para productos entregados y quitados */
        .producto-padre.producto-entregado {
            background: rgba(0,255,0,0.1);
            border-left-color: #44ff44;
            opacity: 0.7;
        }
        
        .producto-padre.producto-entregado .producto-cantidad-nombre {
            text-decoration: line-through;
            color: #88ff88 !important;
        }
        
        .producto-padre.producto-quitado {
            background: rgba(255,0,0,0.1);
            border-left-color: #ff4444;
            opacity: 0.6;
        }
        
        .producto-padre.producto-quitado .producto-cantidad-nombre {
            text-decoration: line-through;
            color: #ff8888 !important;
        }

        /* ===== ESTRUCTURA: DOS GRUPOS SEPARADOS ===== */
        .producto-linea-compacta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 40px;
            gap: 20px;
        }
        
        .grupo-izquierda {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }
        
        .grupo-derecha {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        
        .producto-cantidad-nombre {
            color: #ffff44;
            font-size: 1.4rem;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.7);
            white-space: nowrap;
        }
        
        .extras-inline {
            color: #88ff88;
            font-size: 1rem;
            font-weight: 500;
            background: rgba(136,255,136,0.1);
            padding: 4px 10px;
            border-radius: 15px;
            border: 1px solid rgba(136,255,136,0.2);
            white-space: nowrap;
        }
        
        .producto-estacion {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.9rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            white-space: nowrap;
        }
        
        .estacion-1 { 
            background: linear-gradient(135deg, rgba(255,68,68,0.2) 0%, rgba(255,100,100,0.3) 100%); 
            color: #ff4444; 
            border: 2px solid rgba(255,68,68,0.3);
        }
        .estacion-2 { 
            background: linear-gradient(135deg, rgba(68,136,255,0.2) 0%, rgba(100,150,255,0.3) 100%); 
            color: #4488ff; 
            border: 2px solid rgba(68,136,255,0.3);
        }
        .estacion-3 { 
            background: linear-gradient(135deg, rgba(255,136,68,0.2) 0%, rgba(255,160,100,0.3) 100%); 
            color: #ff8844; 
            border: 2px solid rgba(255,136,68,0.3);
        }
        
        .badge-entregado-inline {
            background: linear-gradient(135deg, rgba(68,255,68,0.2) 0%, rgba(100,255,100,0.3) 100%);
            color: #44ff44;
            border: 1px solid rgba(68,255,68,0.3);
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.9rem;
            font-weight: bold;
            white-space: nowrap;
        }

        /* ===== BOTÓN CAMBIAR TIPO CÍCLICO (SOLO GENERAL) ===== */
        .btn-cambiar-tipo {
            background: linear-gradient(135deg, rgba(138,43,226,0.2) 0%, rgba(138,43,226,0.4) 100%);
            color: #8a2be2;
            border: 2px solid rgba(138,43,226,0.3);
            padding: 6px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .btn-cambiar-tipo:hover {
            background: linear-gradient(135deg, rgba(138,43,226,0.3) 0%, rgba(138,43,226,0.5) 100%);
            color: #8a2be2;
            transform: translateY(-1px);
        }
        
        .sin-tickets {
            text-align: center;
            padding: 100px 20px;
            color: var(--color-primario);
            font-size: 3rem;
            font-weight: bold;
        }
        
        .status-bar {
            position: fixed;
            top: 0;
            right: 0;
            background: rgba(0,0,0,0.8);
            color: var(--color-secundario);
            padding: 5px 15px;
            border-radius: 0 0 0 10px;
            font-size: 0.9rem;
            z-index: 1001;
        }

        /* Estilos para botones de acción */
        .btn-action {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            border: 2px solid;
            cursor: pointer;
            text-align: center;
            min-width: 100px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.3);
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.4);
            text-decoration: none;
        }

        .btn-completar-orden {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-color: #28a745;
            padding: 12px 20px;
            min-width: 140px;
        }

        .btn-completar-orden:hover {
            background: linear-gradient(135deg, #218838 0%, #1ea080 100%);
            color: white;
            border-color: #1e7e34;
        }

        .btn-listo {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-color: #17a2b8;
        }

        .btn-listo:hover {
            background: linear-gradient(135deg, #138496 0%, #117a8b 100%);
            color: white;
            border-color: #117a8b;
        }

        /* Botones táctiles para airmouse */
        .btn-action, .btn-cambiar-tipo, .btn-completar-orden {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        button.btn-action, button.btn-cambiar-tipo, button.btn-completar-orden {
            font-family: 'Arial', sans-serif;
            outline: none;
        }

        .btn-procesadas {
            background: linear-gradient(135deg, rgba(128,128,128,0.3) 0%, rgba(100,100,100,0.5) 100%);
            color: #aaa;
            border: 2px solid rgba(128,128,128,0.4);
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
            cursor: pointer;
            margin-left: 15px;
            vertical-align: middle;
            -webkit-user-select: none;
            user-select: none;
        }

        .btn-procesadas:hover {
            background: linear-gradient(135deg, rgba(128,128,128,0.4) 0%, rgba(100,100,100,0.6) 100%);
            color: #ccc;
        }
    </style>
</head>
<body>
    <!-- Header Ultra-Compacto -->
    <div class="header-rd">
        <h1 class="titulo-rd">
            <?php echo h($config_estacion['emoji']); ?> 
            COMANDERO <?php echo h($config_estacion['titulo']); ?> (<?php echo LUGAR; ?>)
            <?php if ($estacion_tipo === 'GENERAL'): ?>
                <button type="button" onclick="window.open('procesadas_rd.php?tipo=GENERAL', '_blank')" class="btn-procesadas">
                    📋 PROCESADAS
                </button>
            <?php endif; ?>
        </h1>
    </div>

    <!-- Status Bar -->
    <div class="status-bar" id="statusBar">
        <span>🔄 Última: #<span id="ultimoTicket">--</span></span>
        <span>| Servicio: <span id="ultimaEjecucion">--:--:--</span></span>
        <span id="clockText"></span>
    </div>

    <div class="main-content">
        <?php if (empty($tickets)): ?>
            <div class="sin-tickets">
                📋 SIN ÓRDENES ACTIVAS 📋
            </div>
        <?php else: ?>
            <?php foreach ($tickets as $ticket): ?>
                <?php 
                // Determinar clase de estado (sin prioridades para BARRA)
                $clase_ticket = '';
                $badge_prioridad = '';
                
                if (!empty($ticket['completetime'])) {
                    $clase_ticket = ' orden-completada';
                    $badge_prioridad = '<span class="completada-badge">✓ COMPLETADA</span>';
                }
                ?>
                <div class="ticket-card<?php echo $clase_ticket; ?>">
                    <div class="ticket-header">
                        <div class="ticket-info">
                            <div>
                                <span class="ticket-numero">TICKET #<?php echo h($ticket['ticketid']); ?></span>
                                <span class="ticket-cliente">- <?php echo h($ticket['cliente']); ?></span>
                                <?php echo $badge_prioridad; ?>
                            </div>
                            <div class="ticket-fecha">
                                <?php echo h(date('d/m/Y H:i', strtotime($ticket['fecha_orden']))); ?>
                                <span style="margin-left: 10px; color: #44ff44;">
                                    ⏱️ <?php echo timeElapsed($ticket['fecha_orden']); ?>
                                </span>
                                <?php if (!empty($ticket['completetime'])): ?>
                                    <span style="margin-left: 10px; color: #44ff44;">
                                        | ✅ <?php echo date('H:i', strtotime($ticket['completetime'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Botones: GENERAL + BARRA -->
                        <div class="ticket-actions">
                            <?php if ($estacion_tipo === 'GENERAL' && empty($ticket['completetime'])): ?>
                                <button type="button" onclick="accionBtn('completar_orden_rd.php?ticketid=<?php echo $ticket['ticketid']; ?>&tipo=<?php echo $estacion_tipo; ?>')" class="btn-action btn-completar-orden">
                                    ✓ COMPLETAR ORDEN
                                </button>
                            <?php elseif ($estacion_tipo === 'BARRA' && empty($ticket['completetime'])): ?>
                                <button type="button" onclick="accionBtn('completar_orden_barra_rd.php?ticketid=<?php echo $ticket['ticketid']; ?>&tipo=<?php echo $estacion_tipo; ?>')" class="btn-action btn-completar-orden">
                                    ✓ COMPLETAR BARRA
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- GRUPOS CON BADGE VERTICAL -->
                    <?php 
                    // Ordenar grupos por número
                    ksort($ticket['grupos']);
                    
                    foreach ($ticket['grupos'] as $grupo): 
                        // Para BARRA: solo mostrar grupos que tengan productos de estación 1
                        if ($estacion_tipo === 'BARRA') {
                            $tiene_productos_barra = false;
                            foreach ($grupo['productos'] as $producto) {
                                if (in_array($producto['estacion'], ['1', '9'])) {
                                    $tiene_productos_barra = true;
                                    break;
                                }
                            }
                            if (!$tiene_productos_barra) {
                                continue; // Skip este grupo
                            }
                        }
                        
                        // Determinar si el grupo tiene productos visibles para esta estación
                        $productos_visibles = [];
                        foreach ($grupo['productos'] as $producto) {
                            $es_visible = false;
                            
                            if ($estacion_tipo === 'GENERAL') {
                                $es_visible = true;
                            } elseif ($estacion_tipo === 'BARRA') {
                                $es_visible = in_array($producto['estacion'], ['1', '9']); // BARRA ve estación 1 y 9
                            } elseif ($estacion_tipo === 'ALIMENTOS' && in_array($producto['estacion'], ['2', '9'])) {
                                $es_visible = true;
                            } elseif ($estacion_tipo === 'BEBIDAS' && $producto['estacion'] === '3') {
                                $es_visible = true;
                            }
                            
                            if ($es_visible) {
                                $productos_visibles[] = $producto;
                            }
                        }
                        
                        // Solo mostrar grupo si tiene productos visibles
                        if (empty($productos_visibles)) {
                            continue;
                        }
                    ?>
                        <!-- RECUADRO CON BADGE VERTICAL -->
                        <div class="grupo-contenedor">
                            <!-- Badge vertical a la izquierda -->
                            <div class="grupo-badge-vertical <?php 
                                if ($grupo['tipo_servicio'] === 'PARA_LLEVAR') {
                                    echo 'grupo-para-llevar-vertical';
                                } elseif ($grupo['tipo_servicio'] === 'CAMINERA') {
                                    echo 'grupo-caminera-vertical';
                                } else {
                                    echo 'grupo-local-vertical';
                                }
                            ?>">
                                <?php if ($grupo['tipo_servicio'] === 'PARA_LLEVAR'): ?>
                                    P/LLEVAR
                                <?php elseif ($grupo['tipo_servicio'] === 'CAMINERA'): ?>
                                    CAMINERA
                                <?php else: ?>
                                    LOCAL
                                <?php endif; ?>
                            </div>
                            
                            <!-- Productos del Grupo -->
                            <div class="grupo-productos">
                                <?php foreach ($productos_visibles as $producto): ?>
                                    <?php 
                                    // Determinar clases de estado y permisos
                                    $clase_estado = '';
                                    $puede_interactuar = false;
                                    
                                    if ($producto['station_status'] === 'ENTREGO_ESTACION') {
                                        $clase_estado = ' producto-entregado';
                                    } elseif ($producto['station_status'] === 'QUITADO') {
                                        $clase_estado = ' producto-quitado';
                                    }
                                    
                                    // Para todas las estaciones: puede interactuar si está EN_PROCESO
                                    $puede_interactuar = ($producto['station_status'] === 'EN_PROCESO');
                                    ?>
                                    <div class="producto-padre<?php echo $clase_estado; ?>">
                                        <div class="producto-linea-compacta">
                                            <!-- GRUPO IZQUIERDA: Producto + Extras -->
                                            <div class="grupo-izquierda">
                                                <span class="producto-cantidad-nombre">
                                                    <?php echo h($producto['cantidad']); ?>x <?php echo h($producto['producto_padre']); ?>
                                                </span>
                                                
                                                <?php if (!empty($producto['auxiliares_array'])): ?>
                                                    <span class="extras-inline">
                                                        <?php echo implode(' | ', array_map('h', $producto['auxiliares_array'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- GRUPO DERECHA: Estación + Estado + Botones -->
                                            <div class="grupo-derecha">
                                                <!-- Badge estación -->
                                                <span class="producto-estacion estacion-<?php echo h($producto['estacion']); ?>">
                                                    <?php 
                                                    if ($producto['estacion'] === '1') {
                                                        echo 'BARRA';
                                                    } elseif ($producto['estacion'] === '2') {
                                                        echo 'COCINA';
                                                    } elseif ($producto['estacion'] === '3') {
                                                        echo 'BEBIDAS';
                                                    } else {
                                                        echo 'GENERAL';
                                                    }
                                                    ?>
                                                </span>
                                                
                                                <!-- Badge de estado ENTREGADO -->
                                                <?php if ($producto['station_status'] === 'ENTREGO_ESTACION'): ?>
                                                    <span class="badge-entregado-inline">
                                                        ✓ ENTREGADO
                                                    </span>
                                                <?php elseif ($producto['station_status'] === 'QUITADO'): ?>
                                                    <span class="badge-entregado-inline" style="background: linear-gradient(135deg, rgba(255,68,68,0.2) 0%, rgba(255,100,100,0.3) 100%); color: #ff4444; border-color: rgba(255,68,68,0.3);">
                                                        ✗ QUITADO
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <!-- Botón cambiar tipo CÍCLICO (solo GENERAL) -->
                                                <?php if ($estacion_tipo === 'GENERAL' && $producto['station_status'] === 'EN_PROCESO'): ?>
                                                    <button type="button" onclick="accionBtn('cambiar_tipo_rd.php?ticketid=<?php echo $ticket['ticketid']; ?>&grupo=<?php echo $grupo['numero']; ?>&tipo=<?php echo $estacion_tipo; ?>')" class="btn-cambiar-tipo">
                                                        🔄 → <?php 
                                                        if ($grupo['tipo_servicio'] === 'LOCAL') {
                                                            echo 'P/LLEVAR';
                                                        } elseif ($grupo['tipo_servicio'] === 'PARA_LLEVAR') {
                                                            echo 'CAMINERA';
                                                        } else {
                                                            echo 'LOCAL';
                                                        }
                                                        ?>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <!-- Botón de acción -->
                                                <?php if ($puede_interactuar): ?>
                                                    <button type="button" onclick="accionBtn('borrarlinea_rd.php?id=<?php echo $producto['id']; ?>&tipo=<?php echo $estacion_tipo; ?>')" class="btn-action btn-listo">
                                                        ✓ LISTO
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        const estacionActual = '<?php echo $estacion_tipo; ?>';
        
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('es-ES', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            document.getElementById('clockText').textContent = ' | ' + timeString;
        }
        
        function getNextRefreshTime() {
            const now = new Date();
            const segundoActual = now.getSeconds();
            let offset = 0;
            
            if (estacionActual === 'GENERAL') {
                offset = 0;  // 00, 10, 20, 30, 40, 50
            } else if (estacionActual === 'BARRA') {
                offset = 2;  // 02, 12, 22, 32, 42, 52
            } else if (estacionActual === 'ALIMENTOS') {
                offset = 4;  // 04, 14, 24, 34, 44, 54
            } else if (estacionActual === 'BEBIDAS') {
                offset = 6;  // 06, 16, 26, 36, 46, 56
            }
            
            // Encontrar el próximo segundo objetivo
            let proximoSegundo = offset;
            while (proximoSegundo <= segundoActual) {
                proximoSegundo += 10;
            }
            
            // Calcular milisegundos hasta el próximo refresh
            let espera = (proximoSegundo - segundoActual) * 1000 - now.getMilliseconds();
            if (espera <= 0) {
                espera += 10000;
            }
            
            return espera;
        }
        
        // Estaciones: verificar si hay nuevos o cambios
        function checkNuevosYCambios() {
            fetch(`api_rd.php?accion=check&estacion=${estacionActual}`)
                .then(r => r.json())
                .then(data => {
                    if (data.nuevo == 1) {
                        fetch(`api_rd.php?accion=enterado_nuevo&estacion=${estacionActual}`);
                        window.location.reload();
                    } else if (data.cambios == 1) {
                        fetch(`api_rd.php?accion=enterado_cambios&estacion=${estacionActual}`);
                        window.location.reload();
                    } else {
                        scheduleRefresh();
                    }
                })
                .catch(err => {
                    console.log('Error:', err);
                    scheduleRefresh();
                });
        }
        
        function scheduleRefresh() {
            const espera = getNextRefreshTime();
            setTimeout(() => {
                if (estacionActual === 'GENERAL') {
                    window.location.reload();
                } else {
                    checkNuevosYCambios();
                }
            }, espera);
        }
        
        // Pausar y navegar
        function accionBtn(url) {
            window.location.href = url;
        }

        function updateStatus() {
            fetch('api_rd.php?accion=status')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('ultimoTicket').textContent = data.ultimo_ticket;
                    document.getElementById('ultimaEjecucion').textContent = data.ultima_ejecucion;
                })
                .catch(err => console.log('Error status:', err));
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Limpiar URL de mensajes
            if (window.location.search.includes('msg=')) {
                const url = new URL(window.location);
                url.searchParams.delete('msg');
                url.searchParams.delete('type');
                window.history.replaceState({}, '', url);
            }
            
            updateClock();
            setInterval(updateClock, 1000);
            
            updateStatus();
            setInterval(updateStatus, 10000); // Actualizar cada 10 segundos
            
            scheduleRefresh();
        });
    </script>

</body>
</html>