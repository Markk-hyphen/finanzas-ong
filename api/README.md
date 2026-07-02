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

**Ya no hace falta loguearse primero.** `qa/01-login-as.bru` es público (ver `security.yaml`): mintea un JWT de cualquier socio sin password ni token previo. Es el punto de entrada de todo el testeo QA.

**Pasos:**

1. **`members/01-list.bru`** (o `projects/03-members.bru` para un proyecto) — Trae los socios con su `id`, nombre y username. De ahí sacás el `id` del socio que querés ser.
2. Editar la var `member_id` del environment (arriba a la derecha, ícono del ojo, o clic en el nombre del environment) con el `id` que copiaste.
3. **`qa/01-login-as.bru`** — `POST /api/qa/login-as/{{member_id}}`. Devuelve un JWT de ESE socio y lo guarda en `{{token}}`. A partir de acá, todos los requests actúan como si ese socio estuviera logueado — sin password, sin login previo.
4. Correr el request que necesites como ese socio: por ejemplo `payments/03-confirm.bru` si tiene un pago pendiente a su nombre.
5. Para cambiar de socio, repetir el paso 3 con otro `member_id`.

**Ejemplo concreto — crear y confirmar un pago sin loguearte nunca:**

```
1. members/01-list.bru        (ver IDs de socios)
2. [setear member_id = id del pagador]
3. qa/01-login-as.bru         ({{token}} = pagador)
4. payments/02-create-payment.bru   (paga → guarda batch_id)
5. [setear member_id = id del acreedor]
6. qa/01-login-as.bru         ({{token}} = acreedor)
7. payments/03-confirm.bru    (confirma el batch — el acreedor lo recibe)
```

Antes, cada cambio de socio era: logout, recordar la password, login. Ahora es un solo click.

> **login-as NO funciona en prod** (a propósito — da 404). El guard es a nivel de controller (`kernel.environment === 'prod'`), así que hacerlo público en `security.yaml` no cambia nada en prod. Solo está disponible en `dev`/`staging`.
>
> **`auth/01-login.bru` sigue existiendo** para probar el login real con password (email o username), pero ya no es un prerequisito del flujo QA.

## Vistas omniscientes (sin filtrar por el usuario logueado)

- **`projects/02-dashboard.bru`** — dashboard *egocéntrico*: solo tus movimientos y tus pagos pendientes.
- **`projects/04-overview.bru`** — la misma info pero de **todos** los socios del proyecto (todos los balances, todos los movimientos, todos los pagos pendientes). Útil para ver el estado completo sin tener que impersonar a cada socio uno por uno.

## Otros requests útiles

| Request | Qué hace |
|---|---|
| `members/01-list.bru` | Lista global de socios (id, nombre, username, email) — para no andar buscando IDs en las fixtures |
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
| `member_id` | Socio a impersonar con login-as | manual |
| `batch_id` | Batch del último pago creado | automático (`create-payment`) |
