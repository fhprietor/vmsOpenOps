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
| **Estadísticas** | `/vmsopenops/stats` (**página pública**) con dos bloques: arriba los **totales históricos de la compañía** (14 tarjetas: pilotos con PIREP, pilotos activos y del mes, aeronaves, vuelos programados, rutas únicas, PIREPs aceptados, vuelos hoy/ayer, horas voladas, destinos, combustible en kg, distancia y hubs) que se renderizan en el HTML y se cachean 15 min; abajo los **rankings por periodo** (top score, millas, nº de vuelos, rutas, subflotas, aeronaves y aeropuertos, caché 5 min) vía `GET /api/vmsopenops/stats`, que sigue detrás de `auth` y sólo se carga con sesión. |
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

### Precios

Hay una **única** implementación del cálculo:
`Modules\VmsOpenOps\Support\OpsPricing` (en centavos). La usan el frontend y la
API, así que lo que se cotiza es lo que se cobra.

| Operación | Fórmula |
|---|---|
| Jumpseat | `max(round(distancia_NM × precio_NM), minimo)` |
| Ferry | `max(round(distancia_NM × precio_NM), minimo_por_MTOW)` |

`minimo_por_MTOW` (ferry): ≤ 7.000 kg → ligero; ≤ 136.000 kg → medio; por encima
→ pesado; sin MTOW → medio. Todos los importes en **centavos**.

> Antes cada controlador lo calculaba por su cuenta y el ferry por API
> multiplicaba por 100 (la vista cotizaba con la API y el cobro lo hacía el
> frontend, con lo que el precio mostrado podía no ser el cobrado).

## Rutas

### Piloto — prefijo `vmsopenops` (`web`, y `auth` en el grupo)

| Método | URI | Controlador@método |
|---|---|---|
| GET | `/vmsopenops/jumpseat` | `Frontend\JumpseatController@index` |
| GET | `/vmsopenops/jumpseat/create` | `@create` |
| POST | `/vmsopenops/jumpseat` | `@store` |
| DELETE | `/vmsopenops/jumpseat/{id}` | `@cancel` |
| GET | `/vmsopenops/ferry` | `Frontend\FerryController@index` |
| GET | `/vmsopenops/ferry/create` | `@create` |
| POST | `/vmsopenops/ferry` | `@store` |
| DELETE | `/vmsopenops/ferry/{id}` | `@cancel` |
| GET | `/vmsopenops/charter/create` | `Frontend\CharterController@create` |
| POST | `/vmsopenops/charter` | `@store` |
| POST | `/vmsopenops/charter/preview` | `@preview` |
| GET | `/vmsopenops/stats` | `Frontend\StatisticsController@index` (público: totales sin sesión) |

> `POST /vmsopenops/charter/aircraft` se **eliminó**: apuntaba a un método
> inexistente y ninguna vista lo usaba (las aeronaves las pasa `create()`).

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
| GET | `/api/vmsopenops/operations/pending` | `@checkPending` |
| GET | `/api/vmsopenops/user/balance` | `@getUserBalance` |
| POST | `/api/vmsopenops/jumpseat/preview` | `@previewJumpseat` |
| POST | `/api/vmsopenops/jumpseat` | `@storeJumpseat` |
| POST | `/api/vmsopenops/ferry/available` | `@getAvailableAircraft` |
| POST | `/api/vmsopenops/ferry/preview` | `@previewFerry` |
| POST | `/api/vmsopenops/ferry` | `@storeFerry` |
| DELETE | `/api/vmsopenops/{id}` | `@cancel` |
| GET | `/api/vmsopenops/stats` | `Api\StatisticsController@getData` (requiere sesión) |

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

Dos capas:

- **`Config/config.php`** define los **valores por defecto** (único sitio donde
  viven). El provider lo registra como `config('vmsopenops.*')`.
- La tabla **`settings`** guarda el valor efectivo (lo que se edita en
  Admin → Settings). El código lee siempre con
  `setting('vms_open_ops_x', config('vmsopenops.y'))`.

| Clave | Sembrada por migración | Por defecto (`config`) |
|---|---|---|
| `vms_open_ops.jumpseat.enabled` | sí (`true`) | `true` |
| `vms_open_ops.jumpseat.cost_per_nm` | sí (`250`) | `250` |
| `vms_open_ops.jumpseat.min_cost` | sí (`5000`, mig. 000003) | `5000` |
| `vms_open_ops.ferry.enabled` | sí (`true`) | `true` |
| `vms_open_ops.ferry.cost_per_nm` | sí (`500`) | `500` |
| `vms_open_ops.ferry.min_cost_light` | sí (`20000`, mig. 000003) | `20000` |
| `vms_open_ops.ferry.min_cost_medium` | sí (`50000`, mig. 000003) | `50000` |
| `vms_open_ops.ferry.min_cost_heavy` | sí (`100000`, mig. 000003) | `100000` |
| `vms_open_ops.ferry.require_certification` | sí (`true`) | `true` |
| `vms_open_ops.require_reason` | sí (`true`) | `true` |
| `vms_open_ops.max_reason_length` | sí (`500`) | `500` |
| `vms_open_ops.discord_staff_webhook` | no (se crea al guardar el admin) | `''` |

Los importes van en **centavos** (convención de finanzas de phpVMS). La
migración `2024_01_01_000003_add_missing_operations_settings.php` completa las
cuatro claves que antes solo existían como valor por defecto en código, de modo
que ahora las 11 aparecen y se ajustan desde Admin → Settings.

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

### Arreglado (2026-09-30)

1. **`GET /api/vmsopenops/operations/pending` devolvía 500**: llamaba a
   `OperationRequest::pendingForUser()`, que no existe. Ahora usa
   `pending()->forUser()` con filtro opcional por tipo.
2. **`POST /vmsopenops/charter/aircraft`**: apuntaba a un método inexistente y
   ninguna vista lo usaba → **ruta eliminada**.
3. **El ferry por API no se podía pedir**: comparaba `status` (`'A'`) con
   `AircraftState::PARKED` (`0`) y respondía 400 siempre. Ahora exige
   `state = PARKED` **y** `status = ACTIVE`, igual que el frontend.
4. **Precios unificados** en `Support\OpsPricing` (ver «Precios»): el ferry por
   API ya no multiplica por 100 y la vista cotiza lo mismo que se cobra.
5. **Sembradas las 4 claves de settings** que faltaban (migración `000003`): las
   11 aparecen ya en Admin → Settings.
6. **`updateSettings` escribía por `key` sin `id`**: en una base sin la fila
   previa creaba una fila con `id` vacío (de ahí la huérfana) que además salía en
   el formulario de admin sin nombre. Ahora busca y crea **por `id`**.
7. **Fila de settings huérfana**: la migración `000003` la elimina.
8. **Código muerto**: `Frontend\StatisticsController` solo conserva `index()`; su
   `getData()` sin ruta (que consultaba columnas inexistentes
   `pireps.passengers`/`pireps.cargo`) y sus helpers se han eliminado.
9. **Caracteres sueltos `要`** eliminados de los tres `<thead>`, y quitado el
   import sin usar `AircraftStatus` del controlador de admin.
10. **Guardas redundantes**: `ability:admin,admin-access` estaba aplicada tres
    veces (grupo de rutas, `admin.php` y constructor del controlador). Se
    conserva solo la del grupo (`RouteServiceProvider`); verificado que un
    usuario sin rol admin sigue recibiendo 302.
11. **`Config/config.php` ahora sí se usa**: es la fuente de los valores por
    defecto (`setting('vms_open_ops_x', config('vmsopenops.y'))`), incluidos los
    mínimos que antes solo estaban en código, y se han quitado los defaults
    duplicados de controladores, modelo, notificaciones y vistas.
12. **Previews duplicados eliminados**: las vistas (incluidos los overrides del
    tema vholar) cotizan con la **API**, así que la ruta
    `POST /vmsopenops/jumpseat/preview` y los métodos `preview()` de
    `JumpseatController` y `FerryController` se han eliminado. Queda un único
    preview por operación (`api.vmsopenops.api.{jumpseat,ferry}.preview`).

### Pendiente

- El módulo no tiene `Services/` ni tests.
- `vms_open_ops.discord_staff_webhook` es un **secreto** guardado en la tabla
  `settings`: no debe acabar en git.
