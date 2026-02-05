    <?php
    /**
     * borrarlinea_rd.php - Manejo de Estados para Remote Display
     * Versión RD 2.0 - Con sistema de auto-completar órdenes
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

    // Función para verificar si una orden debe auto-completarse
    function verificarYAutoCompletar($connect, $ticketid, $estacion_tipo) {
        // Verificar si TODOS los productos del ticket están ENTREGO_ESTACION
        $check_stmt = mysqli_prepare($connect, "
            SELECT 
                COUNT(*) as total_productos,
                SUM(CASE WHEN station_status = 'ENTREGO_ESTACION' THEN 1 ELSE 0 END) as productos_entregados,
                SUM(CASE WHEN station_status = 'QUITADO' THEN 1 ELSE 0 END) as productos_quitados,
                SUM(CASE WHEN station_status = 'EN_PROCESO' THEN 1 ELSE 0 END) as productos_pendientes
            FROM ordenes_rd 
            WHERE ticketid = ? AND completetime IS NULL
        ");
        
        if (!$check_stmt) {
            logError('Error preparando verificación de auto-completar', mysqli_error($connect));
            return false;
        }
        
        mysqli_stmt_bind_param($check_stmt, "i", $ticketid);
        
        if (!mysqli_stmt_execute($check_stmt)) {
            logError('Error ejecutando verificación de auto-completar', mysqli_stmt_error($check_stmt));
            mysqli_stmt_close($check_stmt);
            return false;
        }
        
        $result = mysqli_stmt_get_result($check_stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($check_stmt);
        
        // Verificar condiciones para auto-completar
        $total_productos = $row['total_productos'];
        $productos_entregados = $row['productos_entregados'];
        $productos_quitados = $row['productos_quitados'];
        $productos_pendientes = $row['productos_pendientes'];
        
        logAudit("VERIFICACION AUTO-COMPLETAR - Ticket: $ticketid | Total: $total_productos | Entregados: $productos_entregados | Quitados: $productos_quitados | Pendientes: $productos_pendientes");
        
        // Auto-completar si NO hay productos EN_PROCESO
        if ($productos_pendientes == 0 && $total_productos > 0) {
            // Todos los productos están entregados o quitados
            $update_stmt = mysqli_prepare($connect, "
                UPDATE ordenes_rd 
                SET completetime = CURRENT_TIMESTAMP()
                WHERE ticketid = ? AND completetime IS NULL
            ");
            
            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, "i", $ticketid);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $affected = mysqli_stmt_affected_rows($update_stmt);
                    mysqli_stmt_close($update_stmt);
                    
                    if ($affected > 0) {
                        logAudit("AUTO-COMPLETAR EXITOSO - Ticket: $ticketid completado automáticamente desde estación: $estacion_tipo");
                        return true;
                    } else {
                        logAudit("AUTO-COMPLETAR SIN CAMBIOS - Ticket: $ticketid ya estaba completado");
                        return true;
                    }
                } else {
                    logError('Error ejecutando auto-completar', mysqli_stmt_error($update_stmt));
                    mysqli_stmt_close($update_stmt);
                }
            } else {
                logError('Error preparando auto-completar', mysqli_error($connect));
            }
        }
        
        return false;
    }

    // Validar método HTTP
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        logError('Método HTTP incorrecto', $_SERVER['REQUEST_METHOD']);
        redirectToIndex('Método no permitido', 'error');
    }

    // Validar parámetros requeridos
    if (!isset($_GET["id"])) {
        logError('Parámetros faltantes', 'ID no proporcionado');
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
    $producto_id = trim($_GET["id"]);

    // Validar ID (debe ser numérico y positivo)
    if (!ctype_digit($producto_id) || $producto_id <= 0) {
        logError('ID inválido', $producto_id);
        redirectToIndex('ID de producto inválido', 'error', $estacion_tipo);
    }

    // Verificar conexión a DB
    if (!$connect) {
        logError('Error de conexión DB', mysqli_connect_error());
        redirectToIndex('Error de conexión a base de datos', 'error', $estacion_tipo);
    }

    try {
        // Primero verificar que el producto existe y está EN_PROCESO
        $check_stmt = mysqli_prepare($connect, "
            SELECT id, producto_padre, cantidad, ticketid, estacion, station_status
            FROM ordenes_rd 
            WHERE id = ? AND station_status = 'EN_PROCESO' AND completetime IS NULL
        ");
        
        if (!$check_stmt) {
            throw new Exception('Error preparando consulta de verificación: ' . mysqli_error($connect));
        }
        
        mysqli_stmt_bind_param($check_stmt, "i", $producto_id);
        
        if (!mysqli_stmt_execute($check_stmt)) {
            throw new Exception('Error ejecutando verificación: ' . mysqli_stmt_error($check_stmt));
        }
        
        $result = mysqli_stmt_get_result($check_stmt);
        $producto_data = mysqli_fetch_assoc($result);
        
        if (!$producto_data) {
            mysqli_stmt_close($check_stmt);
            logError('Producto no encontrado o ya procesado', $producto_id);
            redirectToIndex('Producto no encontrado o ya procesado', 'warning', $estacion_tipo);
        }
        
        mysqli_stmt_close($check_stmt);
        
        // Verificar permisos de estación si se especifica
        if (!empty($estacion_tipo) && $estacion_tipo !== 'GENERAL' && $estacion_tipo !== 'BARRA') {
            $estacion_producto = $producto_data['estacion'];
            $estacion_permitida = '';
            
            if ($estacion_tipo === 'ALIMENTOS') {
                $estacion_permitida = '2';
            } elseif ($estacion_tipo === 'BEBIDAS') {
                $estacion_permitida = '3';
            } else {
                $estacion_permitida = '1';
            }
            
            // Solo permitir si es de su estación
            if ($estacion_producto !== $estacion_permitida) {
                logError('Estación sin permisos para este producto', "Estación: $estacion_tipo, Producto estación: $estacion_producto, ID: $producto_id");
                redirectToIndex('Este producto no pertenece a su estación', 'warning', $estacion_tipo);
            }
        }
        
        // Preparar statement para actualizar el producto según la estación
        if (!empty($estacion_tipo) && ($estacion_tipo === 'ALIMENTOS' || $estacion_tipo === 'BEBIDAS' || $estacion_tipo === 'BARRA')) {
            // ALIMENTOS, BEBIDAS y BARRA marcan como ENTREGO_ESTACION
            $update_stmt = mysqli_prepare($connect, "
                UPDATE ordenes_rd 
                SET station_status = 'ENTREGO_ESTACION',
                    station_completed = CURRENT_TIMESTAMP()
                WHERE id = ? 
                AND station_status = 'EN_PROCESO'
            ");
        } else {
            // GENERAL quita productos (los marca como QUITADO)
            $update_stmt = mysqli_prepare($connect, "
                UPDATE ordenes_rd 
                SET station_status = 'QUITADO',
                    station_completed = CURRENT_TIMESTAMP()
                WHERE id = ? 
                AND station_status = 'EN_PROCESO'
            ");
        }
        
        if (!$update_stmt) {
            throw new Exception('Error preparando consulta de actualización: ' . mysqli_error($connect));
        }
        
        // Bind parámetros
        mysqli_stmt_bind_param($update_stmt, "i", $producto_id);
        
        // CORREGIDO: Ejecutar consulta correctamente
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Error ejecutando actualización: ' . mysqli_stmt_error($update_stmt));
        }
        
        // Verificar si se actualizó la fila
        $affected_rows = mysqli_stmt_affected_rows($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        if ($affected_rows > 0) {
            // Éxito - producto actualizado
            $product_info = $producto_data['cantidad'] . 'x ' . $producto_data['producto_padre'];
            $ticketid = $producto_data['ticketid'];
            
            // Mensaje personalizado según estación
            if (!empty($estacion_tipo) && ($estacion_tipo === 'ALIMENTOS' || $estacion_tipo === 'BEBIDAS' || $estacion_tipo === 'BARRA')) {
                $success_msg = "✅ LISTO: $product_info";
                $accion = 'MARCADO_LISTO';
            } else {
                $success_msg = "❌ QUITADO: $product_info";
                $accion = 'QUITADO';
            }
            
            // Log para auditoría con información de estación
            $log_entry = "PRODUCTO $accion: ID=$producto_id, Producto='$product_info', Ticket=$ticketid";
            if (!empty($estacion_tipo)) {
                $log_entry .= " desde estación $estacion_tipo";
            }
            logAudit($log_entry);
            
            // *** VERIFICAR AUTO-COMPLETAR ***
            $auto_completado = verificarYAutoCompletar($connect, $ticketid, $estacion_tipo);
            
            if ($auto_completado) {
                $success_msg .= " | 🎉 ORDEN COMPLETADA AUTOMÁTICAMENTE";
            }
            
            // Si GENERAL modificó algo, avisar a la estación correspondiente
            if ($estacion_tipo === 'GENERAL') {
                marcarCambiosEstacion($connect, $producto_data['estacion']);
            }
            redirectToIndex($success_msg, 'success', $estacion_tipo);
            
        } elseif ($affected_rows === 0) {
            // No se actualizó - posible condición de carrera
            logError('Producto no se pudo actualizar', "ID: $producto_id");
            redirectToIndex('El producto ya fue procesado', 'warning', $estacion_tipo);
            
        } else {
            // Error inesperado
            throw new Exception('Resultado inesperado: affected_rows = ' . $affected_rows);
        }
        
    } catch (Exception $e) {
        // Manejo de errores
        logError('Excepción en procesar producto', $e->getMessage());
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