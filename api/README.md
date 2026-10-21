# API testing con Bruno

Collection de [Bruno](https://www.usebruno.com/) para testear la API de finanzas-ong a mano. Abrir esta carpeta (`api/`) como colección en Bruno.

## Setup

1. Abrir Bruno → "Open Collection" → seleccionar la carpeta `api/`.
2. Elegir el environment arriba a la derecha: **local**, **staging** o **prod** (`api/environments/`).
3. En `local.bru` y `staging.bru` el login/password ya vienen cargados (`marcosfsoler` / `marcos32`, el admin de fixtures). En `prod.bru` son secrets vacíos — completarlos en `api/.env` (gitignoreado, ver `api/.env.example`).
4. `local.bru` apunta a `http://localhost` (backend expuesto por `docker-compose.override.yml`) — usar mientras corrés `docker compose up` en tu máquina, sin tocar staging/prod.

## Flujo básico

```
Login → List Projects → Project Dashboard
```

`auth/01-login.bru` guarda el token automáticamente (`bru.setVar("token", ...)`) — de ahí en adelante todos los requests usan `{{token}}` solos, sin copiar nada a mano.

## Flujo QA: testear como cualquier socio SIN loguearte

Esto es lo que resuelve `qa/01-login-as.bru`. Sirve para todo lo que antes obligaba a desloguearte y volver a loguear como otro socio — el caso típico es **confirmar un pago**, que solo puede hacerlo el socio que lo recibe.

**Ya no hace falta loguearse primero.** `qa/01-login-as.bru` y `qa/02-members.bru` son públicos (ver `security.yaml`, prefijo `^/api/qa`): ni piden password ni token previo. Son el punto de entrada de todo el testeo QA.

**Por qué hay dos requests y no uno:** `login-as` necesita un `id` numérico, y los ids son autoincrementales — cambian según el historial de fixtures de cada entorno (en staging la DB es desechable y se recarga seguido, así que el rango de ids se corre en cada reload). No hay un id "de siempre" que sirva de default. `qa/02-members.bru` existe para descubrir los ids reales **sin token** — es la versión pública/liviana (`id`, `name`, `username`) del endpoint de negocio `members/01-list.bru` (que sí exige `{{token}}` y no sirve para arrancar en frío).

**Pasos:**

1. **`qa/02-members.bru`** — `GET /api/qa/members`, sin token. Trae todos los socios con su `id` real.
2. Editar la var `member_id` del environment (arriba a la derecha, ícono del ojo, o clic en el nombre del environment) con el `id` del socio que querés ser.
3. **`qa/01-login-as.bru`** — `POST /api/qa/login-as/{{member_id}}`. Devuelve el JWT de ESE socio y lo guarda en `{{token}}`.
4. Correr el request que necesites como ese socio: por ejemplo `payments/03-confirm.bru` si tiene un pago pendiente a su nombre.
5. Para cambiar de socio, repetir el paso 3 con otro `member_id` (no hace falta volver a correr `qa/02-members.bru` salvo que no te acuerdes el id).

**Ejemplo concreto — crear y confirmar un pago sin loguearte nunca:**

```
1. qa/02-members.bru          (ver ids reales, sin token)
2. [setear member_id = id del pagador]
3. qa/01-login-as.bru         ({{token}} = pagador)
4. payments/02-create-payment.bru   (paga → guarda batch_id)
5. [setear member_id = id del acreedor]
6. qa/01-login-as.bru         ({{token}} = acreedor)
7. payments/03-confirm.bru    (confirma el batch — el acreedor lo recibe)
```

Antes, cada cambio de socio era: logout, recordar la password, login. Ahora es un solo click, y ni siquiera hace falta memorizar un id — `qa/02-members.bru` te los muestra al toque.

> **Ni `login-as` ni `qa/02-members.bru` funcionan en prod** (a propósito — dan 404). El guard es a nivel de controller (`kernel.environment === 'prod'`) en cada acción de `QaController`, así que hacerlas públicas en `security.yaml` no cambia nada en prod. Solo están disponibles en `dev`/`staging`.
>
> **`auth/01-login.bru` sigue existiendo** para probar el login real con password (email o username), pero ya no es un prerequisito del flujo QA.

## Vistas omniscientes (sin filtrar por el usuario logueado)

- **`projects/02-dashboard.bru`** — dashboard *egocéntrico*: solo tus movimientos y tus pagos pendientes.
- **`projects/04-overview.bru`** — la misma info pero de **todos** los socios del proyecto (todos los balances, todos los movimientos, todos los pagos pendientes). Útil para ver el estado completo sin tener que impersonar a cada socio uno por uno.

## Otros requests útiles

| Request | Qué hace |
|---|---|
| `members/01-list.bru` | Lista global de socios (id, nombre, username, email), requiere `{{token}}` | endpoint de negocio, no QA |
| `qa/02-members.bru` | Igual pero sin `{{token}}` (id, nombre, username) — para arrancar en frío | dev/staging únicamente |
| `payments/04-batch.bru` | Inspecciona los movimientos de un `{{batch_id}}` (útil después de crear un pago, antes de confirmarlo) |
| `admin/01-cancel-movement.bru` | Cancela un movimiento (requiere `ROLE_ADMIN`) |
| `materials/01-search.bru` | Autocomplete de materiales |
| `movements/01-get.bru` | Detalle de un movimiento (solo visible para socios del proyecto) |

## Administración (crear proyectos/socios/membresías/materiales)

Endpoints de escritura agregados para dejar de tocar Postgres a mano. Requieren token de **admin** (`login-as` sobre Marcos, `marcosfsoler@gmail.com` — ver `qa/02-members.bru` para encontrar su id) salvo `materials/02-create.bru` y `members/05-me-update.bru`, que alcanza con estar autenticado como cualquier socio.

**Flujo para armar un proyecto de cero:**

```
1. qa/02-members.bru              (ver id del admin y de los socios a agregar)
2. qa/01-login-as.bru             ({{token}} = admin)
3. projects/05-create.bru         (crea el proyecto → responde active: false)
4. projects/07-add-member.bru     (repetir por cada socio hasta sumar 100%)
   → cuando la suma llega a 100.00, la respuesta trae projectActive: true
5. A partir de ahí, expenses/payments funcionan normalmente sobre el proyecto
```

**Invariantes que blindan el dominio (ver `docs/api-completion-runbook.md` para el detalle):**

- **INV-1 (blindaje <100%):** un proyecto con la suma de % distinta de 100.00 rechaza `expenses`/`payments` con `409`. Es la razón del paso 4 arriba.
- **INV-2 (congelamiento):** en cuanto el proyecto tiene un movimiento, `projects/07-add-member.bru` y `projects/08-remove-member.bru` devuelven `409` — la membresía queda fija para no reescribir balances históricos.
- **INV-3 (suma ≤ 100%):** `projects/07-add-member.bru` rechaza con `422` cualquier alta que haga superar el 100%.

**Endpoints:**

| Request | Quién | Qué hace |
|---|---|---|
| `projects/05-create.bru` | admin | Crea un proyecto vacío |
| `projects/06-rename.bru` | admin | Renombra un proyecto |
| `projects/07-add-member.bru` | admin | Asocia un socio con un % (bloqueado si el proyecto ya tiene movimientos) |
| `projects/08-remove-member.bru` | admin | Desvincula un socio (mismo bloqueo) |
| `members/02-create.bru` | admin | Crea un socio (`admin: true/false` en el body) |
| `members/03-edit.bru` | admin | Edita `name`/`email`/`active` de un socio |
| `members/04-set-roles.bru` | admin | Otorga/revoca `ROLE_ADMIN` (un admin no puede auto-revocarse) |
| `members/05-me-update.bru` | self | Edita el propio `name`/`email`/`password` — no toca proyectos/%. Cambiar `email` o `password` exige `currentPassword` en el body (403 si falta o es incorrecta) |
| `materials/02-create.bru` | cualquier socio | Crea un material, idempotente por nombre normalizado |
| `materials/03-rename.bru` | admin | Renombra un material |

## Modos de confirmación y anulación por el usuario (Fase 1)

Diseño completo en `docs/fase1-modos-de-confirmacion.md` (enmienda E1 del MANIFIESTO).

| Request | Quién | Qué hace |
|---|---|---|
| `projects/09-settings.bru` | admin | Modo de confirmación (`required` / `optimistic` / `auto_timeout`), plazo de objeción y ventana de autocorrección |
| `instance/01-capabilities.bru` | autenticado | Si esta instancia puede ofrecer `auto_timeout` (hay scheduler vivo) |
| `payments/05-withdraw.bru` | pagador | Retira sus partes pendientes de `{{batch_id}}` |
| `payments/06-reject.bru` | receptor | Rechaza su parte de `{{batch_id}}` ("no me llegó") |
| `expenses/02-undo.bru` | autor | Deshace su gasto `{{movement_id}}` dentro de la ventana |
| `notifications/01-list.bru` | autenticado | Sus últimas notificaciones + `unreadCount` |
| `notifications/02-mark-read.bru` | autenticado | Marca `{{notification_id}}` como leída |
| `notifications/03-subscription-token.bru` | autenticado | JWT para suscribirse a su propio tópico de Mercure |

El dashboard (`projects/02-dashboard.bru`) devuelve en cada movimiento `actions`
(`undo` / `withdraw` / `reject`) con lo que el socio logueado puede hacer ahora.

**Probar `auto_timeout` en dev:** levantar el scheduler con
`docker compose --profile cron up -d scheduler` (y rebuildear su imagen si
cambió `composer.lock`: no monta `vendor`). La capacidad aparece tras la
primera corrida del job (cada 5 min) o corriéndolo a mano:
`docker compose exec scheduler bin/console app:payments:auto-confirm -v`.

## Variables del environment

| Var | Qué es | Se setea |
|---|---|---|
| `base_url` | Host del entorno | fijo por environment |
| `login` / `password` | Credenciales para el login inicial | fijo (staging) / secret (prod) |
| `token` | JWT activo | automático (login o login-as) |
| `project_id` | Proyecto sobre el que estás testeando | manual |
| `member_id` | Socio a impersonar con login-as, o a administrar (agregar a un proyecto, editar, etc.) | manual — sacalo de `qa/02-members.bru`, el default de fábrica no es un id válido (autoincremental, cambia por entorno) |
| `material_id` | Material a renombrar (`materials/03-rename.bru`) | manual — sacalo de `materials/01-search.bru` o de la respuesta de `materials/02-create.bru` |
| `batch_id` | Batch del último pago creado | automático (`create-payment`) |
| `movement_id` | Movimiento a cancelar o consultar en detalle | manual |
| `notification_id` | Notificación a marcar como leída | manual — sacalo de `notifications/01-list.bru` |
