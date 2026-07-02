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

## Flujo QA: testear como otro socio sin su password

Esto es lo que resuelve `qa/01-login-as.bru`. Sirve para todo lo que antes obligaba a desloguearte y volver a loguear como otro socio — el caso típico es **confirmar un pago**, que solo puede hacerlo el socio que lo recibe.

**Pasos:**

1. **`auth/01-login.bru`** — Login normal con cualquier socio (ej. el de `staging.bru`, Ezequiel). Esto es la ÚNICA vez que necesitás una password real en toda la sesión de testeo.
2. **`projects/03-members.bru`** — Trae los socios del proyecto activo (`{{project_id}}`) con su `id`, nombre y %. De ahí sacás el `id` del socio que querés impersonar.
3. Editar la var `member_id` del environment (arriba a la derecha, ícono del ojo, o clic en el nombre del environment) con el `id` que copiaste.
4. **`qa/01-login-as.bru`** — `POST /api/qa/login-as/{{member_id}}`. Devuelve un JWT de ESE socio y lo guarda en `{{token}}` (pisa el token de Ezequiel). A partir de acá, todos los requests actúan como si ese socio estuviera logueado — sin haber tipeado su password.
5. Correr el request que necesites como ese socio: por ejemplo `payments/03-confirm.bru` si tiene un pago pendiente a su nombre.
6. Para volver a ser otro socio, repetir el paso 4 con otro `member_id` (no hace falta re-loguearse con password).

**Ejemplo concreto — confirmar un pago sin cambiar de usuario a mano:**

```
1. auth/01-login.bru          (Ezequiel, password real)
2. projects/01-list.bru       (ver en qué proyectos está Ezequiel)
3. payments/02-create-payment.bru   (Ezequiel paga → guarda batch_id)
4. projects/03-members.bru    (ver quién es el acreedor y su member_id)
5. [setear member_id = id del acreedor]
6. qa/01-login-as.bru         (ahora {{token}} es del acreedor)
7. payments/03-confirm.bru    (confirma el batch — el acreedor lo recibe)
```

Sin login-as, el paso 6 hubiera sido: logout, pedir/recordar la password del acreedor, login. Con login-as es un solo click.

> **login-as NO funciona en prod** (a propósito — da 404). Es una herramienta de testeo, solo está disponible en `dev`/`staging`.

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
