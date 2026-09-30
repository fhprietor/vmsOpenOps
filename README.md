# VmsOpenOps

Módulo de operaciones para **phpVMS 7**: *jumpseat* (traslado del piloto) y *ferry*
(traslado de una aeronave) con **precio por distancia**, flujo de aprobación por
parte del staff, control de saldo y estadísticas.

- **Alias:** `vmsopenops`
- **Providers:** `AppServiceProvider`, `EventServiceProvider`, `RouteServiceProvider`
- **Tabla principal:** `vms_open_ops_requests`
- **Versión del módulo:** 1.0.0 (`Config/config.php`)

## Requisitos

- phpVMS 7 (Laravel 10, PHP 8.1+), MySQL/MariaDB.
- El módulo de finanzas de phpVMS debe estar operativo: los cobros, los saldos y
  los abonos se registran en el diario contable del core.

## Funcionalidad

| Función | Qué hace |
|---|---|
| **Jumpseat** | El piloto se traslada a otro aeropuerto. Coste = distancia × precio/NM (con mínimo). |
| **Ferry** | Traslado de una aeronave entre aeropuertos. Precio por distancia y mínimos por categoría (ligera / media / pesada); puede exigir certificación del piloto. |
| **Charter** | Creación de vuelos charter desde el panel del piloto. |
| **Aprobaciones** | El staff aprueba o rechaza la solicitud, con notas. Estados en la tabla de solicitudes. |
| **Saldo** | Se consulta el balance del piloto y se puede cobrar en el momento o dejar pendiente. |
| **Estadísticas** | Página `/vmsopenops/stats` (top score, millas, número de vuelos, rutas, subflotas, aeronaves y aeropuertos), cacheada 5 minutos. |
| **Notificaciones** | Correo y Discord (`OperationRequested`, `OperationApproved`). Usa los webhooks de Discord del core y uno propio de staff. |

## Rutas

### Piloto — prefijo `vmsopenops` (middleware `web`, y `auth` en el grupo)

| Método | URI | Controlador |
|---|---|---|
| GET | `/vmsopenops/jumpseat` | `Frontend\JumpseatController@index` |
| GET | `/vmsopenops/jumpseat/create` | `@create` |
| POST | `/vmsopenops/jumpseat` | `@store` |
| POST | `/vmsopenops/jumpseat/preview` | `@preview` |
| DELETE | `/vmsopenops/jumpseat/{id}` | `@cancel` |
| GET | `/vmsopenops/ferry` | `Frontend\FerryController@index` |
| GET | `/vmsopenops/ferry/create` | `@create` |
| POST | `/vmsopenops/ferry` | `@store` |
| DELETE | `/vmsopenops/ferry/{id}` | `@cancel` |
| GET | `/vmsopenops/charter/create` | `Frontend\CharterController@create` |
| POST | `/vmsopenops/charter` | `@store` |
| POST | `/vmsopenops/charter/preview` | `@preview` |
| POST | `/vmsopenops/charter/aircraft` | `@getAvailableAircraft` |
| GET | `/vmsopenops/stats` | `Frontend\StatisticsController@index` |

### Admin — prefijo `admin/vmsopenops` (middleware `web` + `ability:admin,admin-access`)

| Método | URI | Controlador |
|---|---|---|
| GET | `/admin/vmsopenops` | `Admin\OperationsController@index` |
| POST | `/admin/vmsopenops/{id}/approve` | `@approve` |
| POST | `/admin/vmsopenops/{id}/reject` | `@reject` |
| GET | `/admin/vmsopenops/settings` | `@settings` |
| POST | `/admin/vmsopenops/settings` | `@updateSettings` |

Enlace de admin registrado: **Operations → `/admin/vmsopenops`**.

### API — prefijo `api/vmsopenops` (middleware `web` + `auth`)

| Método | URI | Controlador |
|---|---|---|
| GET | `/api/vmsopenops/operations` | `Api\OperationsController@index` |
| GET | `/api/vmsopenops/operations/pending` | `@checkPending` |
| GET | `/api/vmsopenops/user/balance` | `@getUserBalance` |
| POST | `/api/vmsopenops/jumpseat/preview` | `@previewJumpseat` |
| POST | `/api/vmsopenops/jumpseat` | `@storeJumpseat` |
| POST | `/api/vmsopenops/ferry/available` | `@getAvailableAircraft` |
| POST | `/api/vmsopenops/ferry` | `@storeFerry` |
| POST | `/api/vmsopenops/ferry/preview` | `@previewFerry` |
| DELETE | `/api/vmsopenops/{id}` | `@cancel` |
| GET | `/api/vmsopenops/stats` | `Api\StatisticsController@getData` |

> Estos endpoints usan **sesión web + cookie**, no la API key de phpVMS
> (`api.auth`). Están pensados para el JavaScript de las vistas del módulo.

## Modelo de datos

`vms_open_ops_requests` (`Modules\VmsOpenOps\Models\OperationRequest`):

```
id, operation_type, user_id, from_airport_id, to_airport_id,
aircraft_id, subfleet_id, aircraft_distance, distance, cost, reason,
type, status, approved_by, admin_notes, approved_at, created_at, updated_at
```

## Settings (grupo `VmsOpenOps`)

Se crean con la migración `2024_01_01_000002_add_operations_settings.php` y se
editan en **Admin → Settings → VmsOpenOps** (o en la propia página de settings
del módulo):

| Clave | Por defecto | Descripción |
|---|---|---|
| `vms_open_ops.jumpseat.enabled` | `true` | Activar jumpseat |
| `vms_open_ops.jumpseat.cost_per_nm` | `250` | Precio por NM (**centavos**) |
| `vms_open_ops.jumpseat.min_cost` | `5000` | Coste mínimo |
| `vms_open_ops.ferry.enabled` | `true` | Activar ferry |
| `vms_open_ops.ferry.cost_per_nm` | `500` | Precio por NM (**centavos**) |
| `vms_open_ops.ferry.min_cost_light` | `20000` | Mínimo aeronave ligera |
| `vms_open_ops.ferry.min_cost_medium` | `50000` | Mínimo aeronave media |
| `vms_open_ops.ferry.min_cost_heavy` | — | Mínimo aeronave pesada |
| `vms_open_ops.ferry.require_certification` | `true` | Exigir certificación |
| `vms_open_ops.require_reason` | `true` | Exigir motivo en la solicitud |
| `vms_open_ops.max_reason_length` | `500` | Longitud máxima del motivo |
| `vms_open_ops.discord_staff_webhook` | — | Webhook de Discord para avisar al staff (**secreto**) |

`Config/config.php` define además los valores por defecto del módulo
(`config('vmsopenops.*')`) para cuando no hay settings en base de datos.

## Instalación

1. Copiar el módulo a `modules/VmsOpenOps`.
2. **Admin → Modules** → activar `VmsOpenOps`.
3. Visitar `/update` para lanzar migraciones y sembrar los settings.
4. Ajustar precios y opciones en **Admin → Settings → VmsOpenOps**.
5. Opcional: rellenar el webhook de Discord de staff para recibir avisos.

Como submódulo del repositorio central:

```bash
git submodule update --init modules/VmsOpenOps
# actualizar el pin desde el repo central:
git -C modules/VmsOpenOps pull origin main && git add modules/VmsOpenOps && git commit
```

## Estructura

```
Config/            config.php (defaults)
Database/Migrations/  tabla de solicitudes + settings
Http/Controllers/  Admin/  Api/  Frontend/
Http/Routes/       admin.php  api.php  web.php
Models/            OperationRequest
Notifications/     OperationRequested, OperationApproved
Providers/         App, Event, Route
Resources/views/   admin/  frontend/  layouts/
Resources/lang/    traducciones
```

## Notas

- **Los importes van en centavos**, siguiendo la convención del módulo de
  finanzas de phpVMS (`cost_per_nm = 30` son 0,30 por NM).
- La tabla de settings tiene una **fila duplicada con `id` vacío** para
  `vms_open_ops.jumpseat.enabled` (resto de una migración anterior). Es
  inofensiva, pero conviene borrarla.
- `vms_open_ops.discord_staff_webhook` es un **secreto**: vive en la base de
  datos, nunca en el repositorio.
- La pantalla de charter no tiene índice propio (solo `create`); si se quiere un
  listado, hay que añadirlo.
