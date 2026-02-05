<?php
/**
 * VISTA COMANDERO RD - SOLO LECTURA
 * Versión 1.0 - Vista de auditoría sin funciones de modificación
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

// Función para leer órdenes de ordenes_rd AGRUPADAS por ticket - ORDEN CRONOLÓGICO
function obtenerOrdenesRD($connect, $estacion_tipo) {
    if ($estacion_tipo === 'GENERAL') {
        // GENERAL solo ve órdenes ACTIVAS
        $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND estacion != '0'";
        $order_clause = "ORDER BY fecha_orden ASC, ticketid ASC, grupo_numero ASC, id ASC";
    } elseif ($estacion_tipo === 'BARRA') {
        // BARRA ve solo SUS productos EN_PROCESO - MÁS VIEJA PRIMERO
        $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND estacion = '1' AND station_status = 'EN_PROCESO'";
        $order_clause = "ORDER BY fecha_orden ASC, ticketid ASC, grupo_numero ASC, id ASC";
    } else {
        // ALIMENTOS/BEBIDAS - solo sus productos EN_PROCESO - MÁS VIEJA PRIMERO
        if ($estacion_tipo === 'ALIMENTOS') {
            $estacion_num = '2';
        } elseif ($estacion_tipo === 'BEBIDAS') {
            $estacion_num = '3';
        } else {
            $estacion_num = '1';
        }
        $where_clause = "DATE(fecha_orden) = CURDATE() AND completetime IS NULL AND station_status = 'EN_PROCESO' AND estacion = '$estacion_num'";
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
    
    $result = mysqli_query($connect, $query);
    
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
                    'grupos' => []
                ];
            }
            
            // Agregar producto al ticket
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

// Obtener órdenes agrupadas por ticket
$tickets = obtenerOrdenesRD($connect, $estacion_tipo);

// Configuración de colores por estación
if ($estacion_tipo === 'GENERAL') {
    $config_estacion = [
        'titulo' => 'GENERAL',
        'color_primario' => '#00ff88',
        'color_secundario' => '#44ff44',
        'emoji' => '👁️'
    ];
} elseif ($estacion_tipo === 'BARRA') {
    $config_estacion = [
        'titulo' => 'BARRA',
        'color_primario' => '#ff4444',
        'color_secundario' => '#44ff44',
        'emoji' => '👁️'
    ];
} elseif ($estacion_tipo === 'ALIMENTOS') {
    $config_estacion = [
        'titulo' => 'COCINA',
        'color_primario' => '#4488ff',
        'color_secundario' => '#44ff44',
        'emoji' => '👁️'
    ];
} else {
    $config_estacion = [
        'titulo' => 'BEBIDAS',
        'color_primario' => '#ff8844',
        'color_secundario' => '#44ff44',
        'emoji' => '👁️'
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VISTA <?php echo h($config_estacion['titulo']); ?> - Solo Lectura</title>
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
        
        /* ===== HEADER CON INDICADOR DE SOLO LECTURA ===== */
        .header-rd {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border-bottom: 3px solid var(--color-primario);
            padding: 8px 10px;
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
        
        .badge-readonly {
            display: inline-block;
            background: linear-gradient(135deg, rgba(255,165,0,0.3) 0%, rgba(255,140,0,0.5) 100%);
            color: #ffa500;
            border: 2px solid rgba(255,165,0,0.5);
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.9rem;
            font-weight: bold;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .main-content {
            margin-top: 80px;
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
        }
        
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
    <!-- Header con indicador de solo lectura -->
    <div class="header-rd">
        <h1 class="titulo-rd">
            <?php echo h($config_estacion['emoji']); ?> 
            VISTA <?php echo h($config_estacion['titulo']); ?>
        </h1>
        <div class="badge-readonly">🔒 SOLO LECTURA</div>
        <button type="button" onclick="window.open('procesadas_rd.php?tipo=GENERAL', '_blank')" class="btn-procesadas">
            📋 PROCESADAS
        </button>
    </div>

    <!-- Status Bar -->
    <div class="status-bar" id="statusBar">
        <span>👁️ Última: #<span id="ultimoTicket">--</span></span>
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
                    </div>
                    
                    <!-- GRUPOS CON BADGE VERTICAL -->
                    <?php 
                    ksort($ticket['grupos']);
                    
                    foreach ($ticket['grupos'] as $grupo): 
                        if ($estacion_tipo === 'BARRA') {
                            $tiene_productos_barra = false;
                            foreach ($grupo['productos'] as $producto) {
                                if ($producto['estacion'] === '1') {
                                    $tiene_productos_barra = true;
                                    break;
                                }
                            }
                            if (!$tiene_productos_barra) {
                                continue;
                            }
                        }
                        
                        $productos_visibles = [];
                        foreach ($grupo['productos'] as $producto) {
                            $es_visible = false;
                            
                            if ($estacion_tipo === 'GENERAL') {
                                $es_visible = true;
                            } elseif ($estacion_tipo === 'BARRA') {
                                $es_visible = ($producto['estacion'] === '1');
                            } elseif ($estacion_tipo === 'ALIMENTOS' && $producto['estacion'] === '2') {
                                $es_visible = true;
                            } elseif ($estacion_tipo === 'BEBIDAS' && $producto['estacion'] === '3') {
                                $es_visible = true;
                            }
                            
                            if ($es_visible) {
                                $productos_visibles[] = $producto;
                            }
                        }
                        
                        if (empty($productos_visibles)) {
                            continue;
                        }
                    ?>
                        <div class="grupo-contenedor">
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
                            
                            <div class="grupo-productos">
                                <?php foreach ($productos_visibles as $producto): ?>
                                    <?php 
                                    $clase_estado = '';
                                    
                                    if ($producto['station_status'] === 'ENTREGO_ESTACION') {
                                        $clase_estado = ' producto-entregado';
                                    } elseif ($producto['station_status'] === 'QUITADO') {
                                        $clase_estado = ' producto-quitado';
                                    }
                                    ?>
                                    <div class="producto-padre<?php echo $clase_estado; ?>">
                                        <div class="producto-linea-compacta">
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
                                            
                                            <div class="grupo-derecha">
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
                                                
                                                <?php if ($producto['station_status'] === 'ENTREGO_ESTACION'): ?>
                                                    <span class="badge-entregado-inline">
                                                        ✓ ENTREGADO
                                                    </span>
                                                <?php elseif ($producto['station_status'] === 'QUITADO'): ?>
                                                    <span class="badge-entregado-inline" style="background: linear-gradient(135deg, rgba(255,68,68,0.2) 0%, rgba(255,100,100,0.3) 100%); color: #ff4444; border-color: rgba(255,68,68,0.3);">
                                                        ✗ QUITADO
                                                    </span>
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
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('es-ES', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            document.getElementById('clockText').textContent = ' | ' + timeString;
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
        
        function autoRefresh() {
            setTimeout(() => {
                window.location.reload();
            }, 10000);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            updateClock();
            setInterval(updateClock, 1000);
            
            updateStatus();
            setInterval(updateStatus, 10000);
            
            autoRefresh();
        });
    </script>
</body>
</html>