# CHANGELOG - Comandero 🍳

Todas las mejoras y cambios importantes del sistema de órdenes en tiempo real.

## [v5.2.2] - 2026-02-05

### 🔧 Fixed
- Productos multi-estación (estación 9) ahora visibles en BARRA y COCINA
- Notificaciones de nuevos/cambios ahora llegan a ambas estaciones para productos multi-estación

### 🎨 Improved
- Configuración de base de datos movida a archivo `.env`
- Agregado `.env.example` como plantilla
- Repositorio Git inicializado para control de versiones

## [v5.2.1] - 2026-01-23

### ✨ Added
- **Status bar mejorado** - Muestra último ticket procesado y hora del servicio
- Campos `ultimo_ticket` y `ultima_ejecucion` en tabla `rd_status`
- Caso `status` en `api_rd.php` para consultar estado del servicio
- `procesar_tickets.php` - Funciones de procesamiento separadas
- `cron_rd.php` - Alternativa web para procesamiento (backup)

### 🔧 Fixed
- Restauración de `php.exe` corrupto (0 bytes) en XAMPP
- Servicio NSSM funcionando correctamente con PHP CLI

### 🎨 Improved
- `vista_rd.php` ahora solo muestra órdenes activas (igual que GENERAL)
- Botón PROCESADAS agregado a `vista_rd.php`
- Status bar en tiempo real actualiza cada 10 segundos
- GENERAL ya no procesa tickets (lo hace el servicio NSSM)

### 🔄 Changed
- Procesamiento de tickets movido completamente al servicio NSSM
- `comandero_rd.php` ahora solo muestra órdenes

## [v5.2.0] - 2026-01-23 🚀

### ✨ Added
- **Servicio Windows** para procesamiento automático cada 10 segundos (NSSM)
- `procesar_ordenes.php` - Script independiente de procesamiento
- `servicio_rd.bat` - Loop para ejecutar el procesador
- **Multi-estación (estación 9)** - Productos visibles en BARRA y COCINA simultáneamente
- `procesadas_rd.php` - Vista separada para historial de órdenes completadas
- Botón "PROCESADAS" en GENERAL para abrir historial en ventana nueva
- `functions_rd.php` - Funciones centralizadas del sistema
- Sistema de logs organizado por semana/día (`logs/202604/audit_2026-01-23.log`)

### 🔧 Fixed
- Sincronización de refreshes por estación para evitar colisiones:
  - GENERAL: segundos 00, 10, 20, 30, 40, 50
  - BARRA: segundos 02, 12, 22, 32, 42, 52
  - ALIMENTOS: segundos 04, 14, 24, 34, 44, 54
  - BEBIDAS: segundos 06, 16, 26, 36, 46, 56

### 🎨 Improved
- GENERAL solo muestra órdenes activas (procesadas en vista separada)
- Separación de conexión DB y funciones en archivos independientes

### 🔄 Changed
- Procesamiento de tickets movido de GENERAL al servicio Windows
- `connection.php` ahora solo contiene conexión DB
- Funciones de log centralizadas en `functions_rd.php`

---

## [v5.1.0] - 2026-01-22 🚀

### ✨ Added
- **Sistema de notificaciones inteligente** - Estaciones solo recargan cuando hay cambios
- Tabla `rd_status` para control de nuevos/cambios por estación
- `api_rd.php` - API unificada para verificar cambios (check, enterado_nuevo, enterado_cambios)
- Campos `nuevo_barra`, `nuevo_alimentos`, `nuevo_bebidas` para detectar órdenes nuevas
- Campos `cambios_barra`, `cambios_alimentos`, `cambios_bebidas` para notificar modificaciones
- Comunicación entre estaciones (GENERAL avisa a estaciones cuando modifica algo)

### 🔧 Fixed
- Botones cambiados de `<a>` a `<button>` para mejor compatibilidad con airmouse/touchscreen
- Eliminado arrastre y selección de texto en botones táctiles

### 🎨 Improved
- CSS para botones táctiles (`user-select: none`, `touch-action: manipulation`)

### 🔄 Changed
- BARRA ya no hace refresh constante, solo cuando hay nuevos o cambios
- Eliminado sistema de conteos anterior, reemplazado por IDs y flags

---

## [v5.0.0] - 2025-10-02

### ✨ Added
- **Sistema de grupos por tipo de servicio** (LOCAL, PARA_LLEVAR, CAMINERA)
- Campo `grupo_numero` para agrupar productos del mismo tipo de servicio
- Campo `tipo_servicio` en `ordenes_rd`
- Botón cíclico para cambiar tipo: LOCAL → P/LLEVAR → CAMINERA → LOCAL
- Badge vertical con tipo de servicio en cada grupo
- `cambiar_tipo_rd.php` - Cambiar tipo de servicio por grupo
- `completar_orden_barra_rd.php` - Completar solo productos de BARRA
- Auto-completar órdenes cuando todas las estaciones terminan

### 🔧 Fixed
- Orden cronológico corregido (más vieja primero)
- Filtrado correcto por estación

### 🎨 Improved
- Diseño de grupos con badge vertical colorizado
- Colores distintivos por tipo de servicio

---

## [v4.0.0] - 2025-09-15

### ✨ Added
- **Remote Display (RD)** - Sistema multi-estación
- Estaciones: GENERAL, BARRA, ALIMENTOS, BEBIDAS
- Tabla `ordenes_rd` para almacenar órdenes procesadas
- Campo `station_status` (EN_PROCESO, ENTREGO_ESTACION, QUITADO)
- `borrarlinea_rd.php` - Marcar productos como LISTO
- `completar_orden_rd.php` - Completar orden completa

### 🔄 Changed
- Separación de lectura (UniCenta) y escritura (ordenes_rd)

---

## [v2.0.0] - 2025-06-10

### ✨ Added
- Sistema de tipografía con REM para escalabilidad perfecta
- Títulos balanceados: 2rem para órdenes, 1.8rem para nombres
- Función `timeElapsed()` simplificada y optimizada
- Sincronización de timezone entre PHP y MySQL
- Debug temporal para troubleshooting de tiempos

### 🔧 Fixed
- **CRÍTICO:** Diferencia de 1 hora entre servidor PHP y MySQL
- Cálculo preciso de minutos transcurridos
- Aplicación correcta de clases CSS de urgencia
- Formato consistente de tiempo (X min vs Xm)

### 🎨 Improved
- Interface moderna con jerarquía visual clara
- Colores de urgencia más precisos:
  - 🟢 0-5 min: Verde (normal)
  - 🟡 5-10 min: Amarillo (precaución)  
  - 🔴 +10 min: Rojo pulsante (urgente)
- Responsive design optimizado para pantallas grandes
- Performance mejorada en auto-refresh

### 🔄 Changed
- Migración de px a rem para mejor escalabilidad
- Simplificación de lógica de tiempo transcurrido
- Eliminación de código legacy innecesario

---

## [v1.5.0] - 2025-05-28

### ✨ Added
- Auto-refresh cada 5 segundos
- Sistema de colores por urgencia
- Indicadores visuales de tiempo transcurrido
- Animaciones CSS para órdenes críticas

### 🔧 Fixed
- Conexión estable con base de datos
- Optimización de consultas SQL
- Mejora en responsive design

---

## [v1.0.0] - 2025-05-15

### ✨ Added
- Sistema básico de display de órdenes
- Conexión con base de datos MySQL
- Interface inicial responsive
- Estructura base del proyecto

---

## 📝 Formato

Este changelog sigue [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) y usa [Semantic Versioning](https://semver.org/).

### Tipos de cambios:
- **✨ Added** - Nuevas funcionalidades
- **🔧 Fixed** - Corrección de bugs
- **🎨 Improved** - Mejoras en UX/UI
- **🔄 Changed** - Cambios en funcionalidad existente
- **❌ Removed** - Funcionalidades eliminadas
- **🔒 Security** - Mejoras de seguridad