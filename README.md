# VmsOpenOps

Módulo de operaciones para **phpVMS 7**: *jumpseat* (traslado del piloto) y *ferry*
(traslado de una aeronave) con **precio por distancia**, flujo de aprobación por
el staff, control de saldo y estadísticas.

- **Alias:** `vmsopenops`
- **Providers:** `AppServiceProvider`, `EventServiceProvider`, `RouteServiceProvider`
- **Tabla principal:** `vms_open_ops_requests`
- **Versión declarada:** `1.0.0` (solo en `Config/config.php`; `module.json` no tiene campo `version`)

## Requisitos

- phpVMS 7 (Laravel 10, PHP 8.1+), MySQL/MariaDB.
- El módulo de finanzas de phpVMS debe estar operativo: cobros, saldos y abonos
  se registran en el diario contable del core.
- **Un worker de colas en marcha**: las notificaciones (`OperationRequested`,
  `OperationApproved`) son `ShouldQueue`. Sin `queue:work` no salen los correos
  ni los avisos de Discord.

## Funcionalidad

| Función | Qué hace |
|---|---|
| **Jumpseat** | El piloto se traslada a otro aeropuerto. Coste = distancia × precio/NM, con mínimo. |
| **Ferry** | Traslado de una aeronave. Precio por distancia y mínimos por categoría MTOW (ligera / media / pesada); puede exigir certificación (rango con la subflota). |
| **Charter** | Crea un vuelo charter (`Flight` inactivo/invisible) y su `Bid` desde el panel del piloto. Sin listado propio: solo el formulario. |
| **Aprobaciones** | El staff aprueba/rechaza con notas. `status`: 0 pendiente, 1 aprobado, 2 rechazado. `type`: 0 solicitud, 1 inmediato (con cobro). |
| **Saldo** | Consulta el balance del piloto y permite cobrar al momento o dejar la solicitud pendiente. |
| **Estadísticas** | `/vmsopenops/stats` + `GET /api/vmsopenops/stats`: top score, millas, nº de vuelos, rutas, subflotas, aeronaves y aeropuertos; caché 5 min. |
| **Notificaciones** | Correo y Discord: `OperationRequested` y `OperationApproved`, canal `discord_webhook`. |

**Enlaces de menú** que registra el módulo (`ModuleService`): Jumpseat
(`/vmsopenops/jumpseat`), Ferry (`/vmsopenops/ferry`), Charter
(`/vmsopenops/charter/create`) y Statistics (`/vmsopenops/stats`) en el frontend;
**Operations** (`/admin/vmsopenops`) en el admin.

### Regla de cobro

- **Vía inmediata** (`type=1`, con saldo suficiente): debita el diario y mueve al
  piloto o a la aeronave; se auto-aprueba.
- **Aprobación por el staff**: mueve al piloto/aeronave pero **no debita** — el
  coste lo absorbe la aerolínea (comportamiento deliberado, documentado en el
  controlador de admin).

## Rutas

### Piloto — prefijo `vmsopenops` (`web`, y `auth` en el grupo)

| Método | URI | Controlador@método |
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
| POST | `/vmsopenops/charter/aircraft` | `@getAvailableAircraft` — **el método no existe (roto)** |
| GET | `/vmsopenops/stats` | `Frontend\StatisticsController@index` |

### Admin — prefijo `admin/vmsopenops` (`web` + `ability:admin,admin-access`)

| Método | URI | Controlador@método |
|---|---|---|
| GET | `/admin/vmsopenops` | `Admin\OperationsController@index` |
| POST | `/admin/vmsopenops/{id}/approve` | `@approve` |
| POST | `/admin/vmsopenops/{id}/reject` | `@reject` |
| GET | `/admin/vmsopenops/settings` | `@settings` |
| POST | `/admin/vmsopenops/settings` | `@updateSettings` |

### API — prefijo `api/vmsopenops` (`web` + `auth`)

| Método | URI | Controlador@método |
|---|---|---|
| GET | `/api/vmsopenops/operations` | `Api\OperationsController@index` |
| GET | `/api/vmsopenops/operations/pending` | `@checkPending` — **roto, ver Notas** |
| GET | `/api/vmsopenops/user/balance` | `@getUserBalance` |
| POST | `/api/vmsopenops/jumpseat/preview` | `@previewJumpseat` |
| POST | `/api/vmsopenops/jumpseat` | `@storeJumpseat` |
| POST | `/api/vmsopenops/ferry/available` | `@getAvailableAircraft` |
| POST | `/api/vmsopenops/ferry/preview` | `@previewFerry` |
| POST | `/api/vmsopenops/ferry` | `@storeFerry` — **roto, ver Notas** |
| DELETE | `/api/vmsopenops/{id}` | `@cancel` |
| GET | `/api/vmsopenops/stats` | `Api\StatisticsController@getData` |

> Estos endpoints usan **sesión web + cookie** (y CSRF), **no** la API key
> (`api.auth`). Sirven al JavaScript de las vistas del módulo; no son consumibles
> por un cliente ACARS externo.

## Modelo de datos

`vms_open_ops_requests` (`Modules\VmsOpenOps\Models\OperationRequest`, clave
primaria autoincremental, no UUID):

```
id, operation_type (jumpseat|ferry), user_id, from_airport_id, to_airport_id,
aircraft_id, subfleet_id, aircraft_distance, distance, cost, reason,
type (0 solicitud / 1 inmediato), status (0 pendiente / 1 aprobado / 2 rechazado),
approved_by, admin_notes, approved_at, created_at, updated_at
```

Índices: compuesto `(user_id, operation_type, status)`, más `aircraft_id`,
`subfleet_id`, `from_airport_id`, `to_airport_id`. Claves foráneas a `users`,
`aircraft`, `subfleets` y `users` (aprobador) con `cascade` / `set null`.

Relaciones: `user`, `fromAirport`, `toAirport`, `aircraft`, `subfleet`,
`approver`. Accessors: `cost_formatted`, `operation_type_text`, `type_text`,
`status_text`, `status_badge_class`. Scopes: `pending`, `approved`, `rejected`,
`jumpseat`, `ferry`, `forUser`.

## Settings (grupo `VmsOpenOps`)

Los valores en tiempo de ejecución salen **siempre de la tabla `settings`** vía
`setting()`. **`Config/config.php` no lo lee ningún código** (solo sirve de
documentación de los valores previstos).

| Clave | Sembrada por migración | Por defecto en código |
|---|---|---|
| `vms_open_ops.jumpseat.enabled` | sí (`true`) | `true` |
| `vms_open_ops.jumpseat.cost_per_nm` | sí (`250`) | `250` |
| `vms_open_ops.jumpseat.min_cost` | **no** | `5000` |
| `vms_open_ops.ferry.enabled` | sí (`true`) | `true` |
| `vms_open_ops.ferry.cost_per_nm` | sí (`500`) | `500` |
| `vms_open_ops.ferry.min_cost_light` | **no** | `20000` |
| `vms_open_ops.ferry.min_cost_medium` | **no** | `50000` |
| `vms_open_ops.ferry.min_cost_heavy` | **no** | `100000` |
| `vms_open_ops.ferry.require_certification` | sí (`true`) | `true` |
| `vms_open_ops.require_reason` | sí (`true`) | `true` |
| `vms_open_ops.max_reason_length` | sí (`500`) | `500` |
| `vms_open_ops.discord_staff_webhook` | **no** (se crea al guardar el admin) | `''` |

Los importes van en **centavos** (convención de finanzas de phpVMS). Nota: el
formulario de admin gestiona las 11 claves, pero la migración solo siembra 7; las
4 restantes existen únicamente como valor por defecto en código hasta que un
admin guarde el formulario.

## Instalación

1. Copiar el módulo a `modules/VmsOpenOps`.
2. **Admin → Modules** → activar `VmsOpenOps`.
3. Visitar `/update` para lanzar migraciones y sembrar los settings.
4. Ajustar precios y opciones en **Admin → Settings → VmsOpenOps** (o en
   **Admin → Operations → Settings**).
5. Opcional: rellenar el webhook de Discord de staff.
6. Asegurar un worker de colas para las notificaciones.

Como submódulo del repositorio central:

```bash
git submodule update --init modules/VmsOpenOps
# actualizar el pin desde el repo central:
git -C modules/VmsOpenOps pull origin main && git add modules/VmsOpenOps && git commit
```

## Estructura

```
Config/              config.php (referencia; no se lee en runtime)
Database/Migrations/ tabla de solicitudes + settings
Http/Controllers/    Admin/  Api/  Frontend/
Http/Routes/         admin.php  api.php  web.php
Models/              OperationRequest
Notifications/       OperationRequested, OperationApproved (ShouldQueue)
Providers/           App, Event, Route
Resources/views/     admin/  frontend/  layouts/
```

## Acoplamiento con el tema

El tema **vholar** sobreescribe 4 vistas del módulo
(`resources/views/layouts/vholar/modules/vmsopenops/frontend/{jumpseat,ferry}/{index,create}.blade.php`).
Además, la vista de estadísticas incluye `vholar::pireps.logbook-styles`. Si se
cambia de tema sin equivalentes, esas páginas se degradan.

Las traducciones **no viven en el módulo**, sino en el core:
`resources/lang/{en,es-es}/vmsopenops.php`. Las vistas propias traen los textos
en inglés escritos a mano.

## Notas y deuda técnica

Defectos verificados, pendientes de arreglar:

1. **`GET /api/vmsopenops/operations/pending` está roto**: llama a
   `OperationRequest::pendingForUser()`, que no existe (solo hay `scopePending` y
   `scopeForUser`). Devuelve 500.
2. **`POST /vmsopenops/charter/aircraft` está roto**: la ruta apunta a
   `CharterController@getAvailableAircraft`, que no está definido (el método
   equivalente está en el controlador de la API). Ninguna vista lo usa.
3. **El ferry por API no se puede pedir**: `Api\OperationsController::storeFerry`
   compara `$aircraft->status` (columna `'A'`) con `AircraftState::PARKED` (`0`),
   así que siempre responde 400 "aeronave no disponible". El controlador de
   frontend lo hace bien con `$aircraft->state`.
4. **El precio del ferry diverge entre API y frontend**: la API multiplica por
   100 de más en `storeFerry`, y en `previewFerry`/`getAvailableAircraft` solo si
   el valor guardado es < 100. La vista de ferry **cotiza con la API** pero
   **guarda con la ruta de frontend**, por lo que el precio mostrado puede
   diferir del cobrado.
5. **Faltan filas de settings**: `jumpseat.min_cost` y los tres
   `ferry.min_cost_*` no se siembran.
6. **Código muerto**: `Frontend\StatisticsController@getData()` no tiene ruta y
   consulta columnas inexistentes (`pireps.passengers`/`pireps.cargo`); la versión
   buena es la de la API (usa `pirep_fares` + `FareType`).
7. **Fila de settings huérfana**: existe una fila duplicada con `id` vacío para
   `vms_open_ops.jumpseat.enabled` (resto de una migración anterior).
8. **Carácter suelto `要`** como primer hijo de `<thead>` en
   `admin/index.blade.php`, `frontend/jumpseat/index.blade.php` y
   `frontend/ferry/index.blade.php`.
9. Guardas redundantes: `ability:admin,admin-access` está aplicada en el grupo de
   rutas, otra vez en `admin.php` y una tercera en el constructor del
   controlador de admin.
10. `vms_open_ops.discord_staff_webhook` es un **secreto** guardado en la tabla
    `settings`: no debe acabar en git.
