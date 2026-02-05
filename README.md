# Comandero Remote Display

Sistema de comandero para Unicenta POS desarrollado para mostrar órdenes activas en cocina/bar con soporte multi-estación.

## Información del Proyecto
- **Base:** PHP + MySQL + JavaScript
- **Target Hardware:** Windows 10/11, Android, tablets
- **Interface:** Touchscreen / Air mouse navigation
- **Origen:** Integración con Unicenta oPOS

## Versiones

### v5.2.0: Servicio Windows + Multi-estación ✅ (Actual)
- Servicio Windows independiente para procesamiento cada 10 segundos (NSSM)
- Multi-estación (estación 9) - Productos visibles en BARRA y COCINA
- Vista separada para órdenes procesadas
- Logs organizados por semana/día
- Funciones centralizadas en `functions_rd.php`
- Sincronización de refreshes por estación

### v5.1.0: Sistema de Notificaciones ✅
- Sistema inteligente de notificaciones (solo recarga cuando hay cambios)
- Tabla `rd_status` para control de nuevos/cambios
- API unificada (`api_rd.php`)
- Botones táctiles optimizados para airmouse

### v5.0.0: Grupos y Tipos de Servicio ✅
- Sistema de grupos (LOCAL, PARA_LLEVAR, CAMINERA)
- Botón cíclico para cambiar tipo de servicio
- Badge vertical con tipo de servicio
- Auto-completar órdenes

### v4.0.0: Remote Display Multi-estación ✅
- Sistema multi-estación (GENERAL, BARRA, ALIMENTOS, BEBIDAS)
- Tabla `ordenes_rd` separada de Unicenta
- Estados de producto (EN_PROCESO, ENTREGO_ESTACION, QUITADO)

### v2.0.0: UI Improvements ✅
- Interfaz modernizada con cards y gradientes
- Indicadores de urgencia por tiempo
- Auto-refresh inteligente

### v1.0.0: Original Implementation ✅
- Implementación básica con refresh automático
- Query directo a Unicenta

## Arquitectura v5.2
```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Unicenta POS  │────▶│  Servicio NSSM   │────▶│   ordenes_rd    │
│   (tickets)     │     │  (cada 10 seg)   │     │   (tabla)       │
└─────────────────┘     └──────────────────┘     └────────┬────────┘
                                                          │
                        ┌─────────────────────────────────┼─────────────────────────────────┐
                        │                                 │                                 │
                        ▼                                 ▼                                 ▼
                ┌───────────────┐                ┌───────────────┐                ┌───────────────┐
                │    GENERAL    │                │     BARRA     │                │   ALIMENTOS   │
                │  (seg :00)    │                │   (seg :02)   │                │   (seg :04)   │
                └───────────────┘                └───────────────┘                └───────────────┘
```

## Estaciones

| Estación | Productos | Refresh | Función |
|----------|-----------|---------|---------|
| GENERAL | Todos | :00, :10, :20... | Control maestro, completar órdenes |
| BARRA | Estación 1 y 9 | :02, :12, :22... | Tacos, burros, cemitas |
| ALIMENTOS | Estación 2 y 9 | :04, :14, :24... | Chilaquiles, tortas |
| BEBIDAS | Estación 3 | :06, :16, :26... | Aguas, cafés, licuados |

## Estructura del Proyecto
```
rd/
├── index_rd.php              # Vista principal (comandero)
├── procesadas_rd.php         # Vista de órdenes completadas
├── connection.php            # Configuración DB
├── functions_rd.php          # Funciones centralizadas
├── api_rd.php                # API para notificaciones
├── procesar_ordenes.php      # Procesador de tickets (servicio NSSM)
├── procesar_tickets.php      # Funciones de procesamiento
├── servicio_rd.bat           # Loop del servicio Windows
├── cron_rd.php               # Alternativa web (backup)
├── borrarlinea_rd.php        # Marcar producto LISTO
├── completar_orden_rd.php    # Completar orden (GENERAL)
├── completar_orden_barra_rd.php  # Completar BARRA
├── cambiar_tipo_rd.php       # Cambiar LOCAL/LLEVAR/CAMINERA
├── vista_rd.php              # Vista solo lectura
└── logs/                     # Logs organizados por semana
    └── 202604/
        ├── audit_2026-01-23.log
        └── errors_2026-01-23.log
````

## Tablas de Base de Datos

### ordenes_rd
```sql
- id, ticket_uuid, ticketid, cliente, fecha_orden
- producto_padre, producto_padre_id, auxiliares (JSON)
- estacion, cantidad, station_status
- station_completed, completetime
- tipo_servicio, grupo_numero
```

### rd_status
```sql
- ultimo_id_barra, ultimo_id_alimentos, ultimo_id_bebidas, ultimo_id_general
- nuevo_barra, nuevo_alimentos, nuevo_bebidas
- cambios_barra, cambios_alimentos, cambios_bebidas
- ultimo_ticket, ultima_ejecucion
```

## Instalación

### 1. Archivos
Copiar todos los archivos a `C:\xampp\htdocs\rd\`

### 2. Base de Datos
```sql
-- Crear tabla rd_status
CREATE TABLE rd_status (
    id INT PRIMARY KEY DEFAULT 1,
    ultimo_id_barra INT DEFAULT 0,
    ultimo_id_alimentos INT DEFAULT 0,
    ultimo_id_bebidas INT DEFAULT 0,
    ultimo_id_general INT DEFAULT 0,
    nuevo_barra TINYINT DEFAULT 0,
    nuevo_alimentos TINYINT DEFAULT 0,
    nuevo_bebidas TINYINT DEFAULT 0,
    cambios_barra TINYINT DEFAULT 0,
    cambios_alimentos TINYINT DEFAULT 0,
    cambios_bebidas TINYINT DEFAULT 0
);

INSERT INTO rd_status (id) VALUES (1);
```

### 3. Servicio Windows (NSSM)
```cmd
# Descargar NSSM de https://nssm.cc/download
# Instalar servicio
nssm.exe install ComanderoRD "C:\xampp\htdocs\rd\servicio_rd.bat"

# Iniciar servicio
nssm.exe start ComanderoRD

# Verificar estado
nssm.exe status ComanderoRD
```

### 4. Configurar connection.php
```php
<?php
date_default_timezone_set('America/Mexico_City');
setlocale(LC_MONETARY, 'es_MX.UTF-8');

define('LUGAR', 'CU');  // o 'MED'

$connect = mysqli_connect("localhost", "usuario", "password", "database") or die("Error de conexión");

require_once __DIR__ . '/functions_rd.php';
?>
```

## URLs de Acceso

| Estación | URL |
|----------|-----|
| GENERAL | `http://localhost/rd/index_rd.php?tipo=GENERAL` |
| BARRA | `http://localhost/rd/index_rd.php?tipo=BARRA` |
| ALIMENTOS | `http://localhost/rd/index_rd.php?tipo=ALIMENTOS` |
| BEBIDAS | `http://localhost/rd/index_rd.php?tipo=BEBIDAS` |
| Procesadas | `http://localhost/rd/procesadas_rd.php` |

## Compatibilidad

| Plataforma | Estado |
|------------|--------|
| Windows 10/11 Chrome | ✅ Soportado |
| Windows 10/11 Edge | ✅ Soportado |
| Android Chrome | ✅ Soportado |
| iPad Gen 2+ | ⚠️ No recomendado (iOS antiguo) |

## Comandos Útiles NSSM
```cmd
# Ver estado
nssm.exe status ComanderoRD

# Detener servicio
nssm.exe stop ComanderoRD

# Reiniciar servicio
nssm.exe restart ComanderoRD

# Eliminar servicio
nssm.exe remove ComanderoRD confirm
```

## Troubleshooting

### Servicio no procesa
```cmd
# Verificar que PHP funcione
C:\xampp\php\php.exe -v

# Probar script manualmente
C:\xampp\php\php.exe C:\xampp\htdocs\rd\procesar_ordenes.php
```

### Ver logs
```cmd
dir C:\xampp\htdocs\rd\logs\
type C:\xampp\htdocs\rd\logs\202604\errors_2026-01-23.log
```

### Estaciones no actualizan
Verificar tabla `rd_status`:
```sql
SELECT * FROM rd_status;
```

---
**Última actualización:** 2026-01-23
**Versión actual:** v5.2.1
**Estado:** Producción (CU y MED)