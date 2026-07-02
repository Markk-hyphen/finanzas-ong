# API testing con Bruno

Collection de [Bruno](https://www.usebruno.com/) para testear la API de finanzas-ong a mano. Abrir esta carpeta (`api/`) como colección en Bruno.

## Setup

1. Abrir Bruno → "Open Collection" → seleccionar la carpeta `api/`.
2. Elegir el environment arriba a la derecha: **staging** o **prod** (`api/environments/`).
3. En `staging.bru` el login/password ya vienen cargados (`ezequiel.torrontegui` / `asdasd`). En `prod.bru` son secrets vacíos — completarlos en `api/.env` (gitignoreado, ver `api/.env.example`).

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

## Variables del environment

| Var | Qué es | Se setea |
|---|---|---|
| `base_url` | Host del entorno | fijo por environment |
| `login` / `password` | Credenciales para el login inicial | fijo (staging) / secret (prod) |
| `token` | JWT activo | automático (login o login-as) |
| `project_id` | Proyecto sobre el que estás testeando | manual |
| `member_id` | Socio a impersonar con login-as | manual — sacalo de `qa/02-members.bru`, el default de fábrica no es un id válido (autoincremental, cambia por entorno) |
| `batch_id` | Batch del último pago creado | automático (`create-payment`) |
