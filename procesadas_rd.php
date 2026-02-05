<?php
/**
 * PROCESADAS RD - Historial de órdenes completadas del día
 * Solo lectura - Sin botones de acción
 */

// Incluir conexión DB
try {
    include_once 'connection.php';
    if (!$connect) {
        throw new Exception('Error de conexión');
    }
} catch (Exception $e) {
    exit("Error de conexión a la base de datos.");
}

// Función para escapar output HTML
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Función para formatear tiempo transcurrido
function timeElapsed($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $total_minutes = floor(($now->getTimestamp() - $ago->getTimestamp()) / 60);
    return $total_minutes > 0 ? $total_minutes . ' min' : 'Recién';
}

// Obtener órdenes PROCESADAS (completadas) del día
function obtenerProcesadas($connect) {
    $query = "
        SELECT 
            *,
            TIMESTAMPDIFF(MINUTE, fecha_orden, completetime) AS minutos_proceso
        FROM ordenes_rd 
        WHERE DATE(fecha_orden) = CURDATE() 
        AND completetime IS NOT NULL
        AND estacion != '0'
        ORDER BY completetime DESC, ticketid DESC, grupo_numero ASC, id ASC
    ";
    
    $result = mysqli_query($connect, $query);
    if (!$result) {
        return [];
    }
    
    $tickets_agrupados = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $ticket_id = $row['ticketid'];
        $row['auxiliares_array'] = json_decode($row['auxiliares'], true) ?? [];
        
        if (!isset($row['tipo_servicio']) || empty($row['tipo_servicio'])) {
            $row['tipo_servicio'] = 'LOCAL';
        }
        if (!isset($row['grupo_numero']) || empty($row['grupo_numero'])) {
            $row['grupo_numero'] = 1;
        }
        
        if (!isset($tickets_agrupados[$ticket_id])) {
            $tickets_agrupados[$ticket_id] = [
                'ticketid' => $row['ticketid'],
                'cliente' => $row['cliente'],
                'fecha_orden' => $row['fecha_orden'],
                'completetime' => $row['completetime'],
                'minutos_proceso' => $row['minutos_proceso'],
                'productos' => [],
                'grupos' => []
            ];
        }
        
        $tickets_agrupados[$ticket_id]['productos'][] = $row;
        
        $grupo_num = $row['grupo_numero'];
        if (!isset($tickets_agrupados[$ticket_id]['grupos'][$grupo_num])) {
            $tickets_agrupados[$ticket_id]['grupos'][$grupo_num] = [
                'numero' => $grupo_num,
                'tipo_servicio' => $row['tipo_servicio'],
                'productos' => []
            ];
        }
        $tickets_agrupados[$ticket_id]['grupos'][$grupo_num]['productos'][] = $row;
    }
    
    return $tickets_agrupados;
}

$tickets = obtenerProcesadas($connect);
$total_ordenes = count($tickets);
$total_productos = 0;
foreach ($tickets as $t) {
    $total_productos += count($t['productos']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesadas - RD (<?php echo LUGAR; ?>)</title>
    <style>
        :root {
            --color-primario: #44ff44;
            --color-secundario: #00ff88;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background-color: #000;
            padding: 10px;
            font-family: 'Arial', sans-serif;
            color: #fff;
        }
        
        .header-rd {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            border-bottom: 3px solid var(--color-primario);
            padding: 8px 15px;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .titulo-rd {
            color: var(--color-primario);
            font-size: 1.3rem;
            font-weight: bold;
        }
        
        .stats-rd {
            color: #aaa;
            font-size: 0.9rem;
        }
        
        .stats-rd span {
            margin-left: 15px;
            padding: 4px 10px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }
        
        .main-content {
            margin-top: 60px;
        }
        
        .ticket-card {
            background: linear-gradient(135deg, #0a2a0a 0%, #1a3a1a 100%);
            border: 2px solid #44ff44;
            border-radius: 10px;
            margin-bottom: 15px;
            padding: 15px;
            opacity: 0.85;
        }
        
        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #333;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .ticket-numero {
            color: var(--color-primario);
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .ticket-cliente {
            color: #88ff88;
            font-size: 1.2rem;
        }
        
        .ticket-tiempos {
            color: #aaa;
            font-size: 0.9rem;
        }
        
        .ticket-tiempos span {
            margin-left: 10px;
        }
        
        .completada-badge {
            background: rgba(68,255,68,0.2);
            color: #44ff44;
            border: 1px solid rgba(68,255,68,0.4);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .grupo-contenedor {
            background: rgba(255,255,255,0.03);
            border: 1px solid #444;
            border-radius: 8px;
            margin: 10px 0;
            display: flex;
            overflow: hidden;
        }
        
        .grupo-badge-vertical {
            writing-mode: vertical-rl;
            text-orientation: mixed;
            padding: 10px 6px;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 35px;
        }
        
        .grupo-local-vertical {
            background: rgba(68,136,255,0.2);
            color: #4488ff;
        }
        .grupo-para-llevar-vertical {
            background: rgba(255,165,0,0.2);
            color: #ffa500;
        }
        .grupo-caminera-vertical {
            background: rgba(138,43,226,0.2);
            color: #8a2be2;
        }
        
        .grupo-productos {
            flex: 1;
            padding: 8px;
        }
        
        .producto-padre {
            background: rgba(0,255,0,0.05);
            border-left: 3px solid #44ff44;
            padding: 10px;
            margin-bottom: 6px;
            border-radius: 5px;
        }
        
        .producto-linea-compacta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }
        
        .producto-cantidad-nombre {
            color: #88ff88;
            font-size: 1.1rem;
            font-weight: bold;
            text-decoration: line-through;
        }
        
        .extras-inline {
            color: #66aa66;
            font-size: 0.85rem;
            background: rgba(100,170,100,0.1);
            padding: 3px 8px;
            border-radius: 10px;
        }
        
        .producto-estacion {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .estacion-1 { background: rgba(255,68,68,0.15); color: #ff6666; }
        .estacion-2 { background: rgba(68,136,255,0.15); color: #6699ff; }
        .estacion-3 { background: rgba(255,136,68,0.15); color: #ff9966; }
        
        .badge-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        .badge-entregado { background: rgba(68,255,68,0.15); color: #44ff44; }
        .badge-quitado { background: rgba(255,68,68,0.15); color: #ff4444; }
        
        .sin-tickets {
            text-align: center;
            padding: 80px 20px;
            color: #666;
            font-size: 1.5rem;
        }
        
        .status-bar {
            position: fixed;
            top: 0;
            right: 0;
            background: rgba(0,0,0,0.8);
            color: var(--color-secundario);
            padding: 5px 15px;
            border-radius: 0 0 0 10px;
            font-size: 0.85rem;
            z-index: 1001;
        }
    </style>
</head>
<body>
    <div class="header-rd">
        <h1 class="titulo-rd">
            📋 PROCESADAS DEL DÍA (<?php echo LUGAR; ?>)
        </h1>
        <div class="stats-rd">
            <span>🎫 <?php echo $total_ordenes; ?> órdenes</span>
            <span>📦 <?php echo $total_productos; ?> productos</span>
        </div>
    </div>

    <div class="status-bar" id="statusBar">
        <span>✅ HISTORIAL</span>
        <span id="clockText"></span>
    </div>

    <div class="main-content">
        <?php if (empty($tickets)): ?>
            <div class="sin-tickets">
                📋 NO HAY ÓRDENES PROCESADAS HOY
            </div>
        <?php else: ?>
            <?php foreach ($tickets as $ticket): ?>
                <div class="ticket-card">
                    <div class="ticket-header">
                        <div>
                            <span class="ticket-numero">TICKET #<?php echo h($ticket['ticketid']); ?></span>
                            <span class="ticket-cliente">- <?php echo h($ticket['cliente']); ?></span>
                            <span class="completada-badge">✓ COMPLETADA</span>
                        </div>
                        <div class="ticket-tiempos">
                            <span>📥 <?php echo date('H:i', strtotime($ticket['fecha_orden'])); ?></span>
                            <span>📤 <?php echo date('H:i', strtotime($ticket['completetime'])); ?></span>
                            <span>⏱️ <?php echo $ticket['minutos_proceso']; ?> min</span>
                        </div>
                    </div>
                    
                    <?php 
                    ksort($ticket['grupos']);
                    foreach ($ticket['grupos'] as $grupo): 
                    ?>
                        <div class="grupo-contenedor">
                            <div class="grupo-badge-vertical <?php 
                                if ($grupo['tipo_servicio'] === 'PARA_LLEVAR') echo 'grupo-para-llevar-vertical';
                                elseif ($grupo['tipo_servicio'] === 'CAMINERA') echo 'grupo-caminera-vertical';
                                else echo 'grupo-local-vertical';
                            ?>">
                                <?php 
                                if ($grupo['tipo_servicio'] === 'PARA_LLEVAR') echo 'P/LLEVAR';
                                elseif ($grupo['tipo_servicio'] === 'CAMINERA') echo 'CAMINERA';
                                else echo 'LOCAL';
                                ?>
                            </div>
                            
                            <div class="grupo-productos">
                                <?php foreach ($grupo['productos'] as $producto): ?>
                                    <div class="producto-padre">
                                        <div class="producto-linea-compacta">
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <span class="producto-cantidad-nombre">
                                                    <?php echo h($producto['cantidad']); ?>x <?php echo h($producto['producto_padre']); ?>
                                                </span>
                                                <?php if (!empty($producto['auxiliares_array'])): ?>
                                                    <span class="extras-inline">
                                                        <?php echo implode(' | ', array_map('h', $producto['auxiliares_array'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="display:flex; align-items:center; gap:6px;">
                                                <span class="producto-estacion estacion-<?php echo h($producto['estacion']); ?>">
                                                    <?php 
                                                    if ($producto['estacion'] === '1') echo 'BARRA';
                                                    elseif ($producto['estacion'] === '2') echo 'COCINA';
                                                    elseif ($producto['estacion'] === '3') echo 'BEBIDAS';
                                                    else echo 'GENERAL';
                                                    ?>
                                                </span>
                                                <span class="badge-status <?php echo $producto['station_status'] === 'QUITADO' ? 'badge-quitado' : 'badge-entregado'; ?>">
                                                    <?php echo $producto['station_status'] === 'QUITADO' ? '✗ QUITADO' : '✓ ENTREGADO'; ?>
                                                </span>
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
                hour: '2-digit', minute: '2-digit', second: '2-digit' 
            });
            document.getElementById('clockText').textContent = ' | ' + timeString;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            updateClock();
            setInterval(updateClock, 1000);
            // Refresh cada 30 segundos (no necesita ser tan frecuente)
            setTimeout(() => window.location.reload(), 30000);
        });
    </script>
</body>
</html>