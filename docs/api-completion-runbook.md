# Runbook — Completar la API de finanzas-ong

> **Para quién es esto:** guía de ejecución paso a paso, prescriptiva. Seguir al pie
> de la letra; las decisiones de diseño ya están tomadas (sección 1). No inventar
> endpoints, roles ni validaciones fuera de lo acá especificado. Ante una duda no
> cubierta, **parar y preguntar**, no improvisar.
>
> **Rama:** `feat/api-completion` (ya creada desde `main`).
> **Idioma del código:** inglés (ver `CLAUDE.md` §"Convención de idioma"). Textos
> de error hacia el cliente pueden ir en español.

## 0. Objetivo

Hoy la API es casi toda de lectura: no se puede crear un proyecto, un socio, una
membresía ni un material sin tocar Postgres a mano. Este runbook agrega los
endpoints de escritura/administración que faltan, respetando dos invariantes de
dominio (ledger append-only + proyecto blindado si sus % no suman 100).

## 1. Decisiones cerradas (NO re-discutir)

### 1.1 Autorización por endpoint

| ID | Método + ruta | Quién | Notas |
|----|---------------|-------|-------|
| A1 | `POST /api/projects` | **admin** | Crea proyecto **vacío** (sin socios) |
| A2 | `PATCH /api/projects/{id}` | **admin** | Renombrar |
| A3 | `POST /api/members` | **admin** | Flag opcional `admin` en el body |
| A4 | `PATCH /api/members/{id}` | **admin** | Editar `name`/`email`/`active` de otro |
| A5 | `PUT /api/members/{id}/roles` | **admin** | Endpoint aparte: otorgar/revocar `ROLE_ADMIN` |
| A6 | `PATCH /api/me` | **self** (cualquier autenticado) | Solo lo propio: `name`/`email`/`password`. **NO** relaciones con proyectos |
| A7 | `POST /api/projects/{id}/members` | **admin** | Asociar socio + %. Solo si el proyecto **no tiene movimientos** |
| A9 | `DELETE /api/projects/{id}/members/{memberId}` | **admin** | Desvincular. Solo si el proyecto **no tiene movimientos** |
| A10 | `POST /api/materials` | **cualquier socio** | Idempotente por `normalizedName` |
| A11 | `PATCH /api/materials/{id}` | **admin** | Renombrar |
| A12 | `GET /api/movements/{id}` | **socio del proyecto** del movimiento | Detalle de 1 movimiento |

> **A8 (editar % de una membresía) queda FUERA de alcance a propósito.** En setup es
> redundante (para corregir un %, se hace A9 + A7); con movimientos está prohibido.
> Es la superficie más peligrosa del sistema y se rediseña en otra sesión (ver §10).

- **admin** = atributo `#[IsGranted('ROLE_ADMIN')]` sobre la acción (igual que
  `MovementController::cancel`).
- **self** = usar `$this->getUser()`; nunca recibe un id de otro socio.
- **socio** (sin rol) = basta con estar autenticado (`ROLE_MEMBER`, que todos tienen).

### 1.2 Invariantes de dominio (transversales)

- **INV-1 · Blindaje <100%:** un proyecto está *activo* (usable para registrar
  movimientos) **solo si la suma de los `MemberProject.percentage` de sus socios es
  exactamente `100.00`**. El estado es **derivado** (se calcula al vuelo, sin columna
  ni migración nueva). Si no está activo, se bloquean las operaciones de escritura de
  ledger (ver §3.2).
- **INV-2 · Congelamiento de membresía:** en cuanto un proyecto tiene **al menos un
  movimiento** (expense/payment/cancellation), su membresía queda **congelada**:
  A7 y A9 devuelven `409`. Esto evita reescribir retroactivamente los balances (que
  se derivan del % actual). Supersede el criterio per-socio que se había hablado: es
  per-**proyecto**, más estricto y coherente.
- **INV-3 · Suma de %:** A7 rechaza (`422`) cualquier alta que haga que la suma de %
  del proyecto **supere `100.00`**. (Se permiten estados parciales `<100` durante el
  setup.)
- **INV-4 · Ledger append-only:** los movimientos **nunca** se editan ni borran (ya
  es así). Este runbook **no** agrega update/delete de movimientos.

### 1.3 Fixtures

- Agregar un admin de pruebas en `AppFixtures`: `marcosfsoler@gmail.com`,
  password `marcos32`, `ROLE_ADMIN`. (Solo dev/staging; las fixtures no corren en
  prod.) Ver §5.

## 2. Setup de rama

Ya estás en `feat/api-completion`. Levantar el entorno dev y confirmar verde antes
de tocar nada:

```bash
docker compose up -d
docker compose exec backend bin/console doctrine:migrations:status   # sin pendientes
docker compose exec backend curl -s http://localhost/health          # {"status":"ok"}
docker compose exec backend bin/console doctrine:fixtures:load --group=dev --no-interaction
```

No hay migraciones nuevas en toda esta feature (INV-1 es derivado, no hay columnas
nuevas). Si en algún momento generás una migración, es señal de que te desviaste del
diseño: **parar**.

## 3. Paso 1 — Guard transversal (implementar ANTES que los endpoints)

### 3.1 Servicio `ProjectGuard`

Crear `backend/src/Domain/Service/ProjectGuard.php`. Concentra los dos chequeos
derivados para no repetir queries por los controllers. Es deliberadamente chico y
autocontenido: el rediseño futuro (§10) lo puede tirar sin arrastrar nada.

```php
<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class ProjectGuard
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** Suma de los porcentajes de las membresías del proyecto, con escala 2. */
    public function sumPercentage(Project $project): string
    {
        $sum = $this->em->createQuery(
            'SELECT COALESCE(SUM(mp.percentage), 0) FROM App\Entity\MemberProject mp WHERE mp.project = :project'
        )->setParameter('project', $project)->getSingleScalarResult();

        return bcadd((string) $sum, '0', 2);
    }

    /** Un proyecto es "activo" (usable) si sus % suman exactamente 100.00 (INV-1). */
    public function isActive(Project $project): bool
    {
        return bccomp($this->sumPercentage($project), '100.00', 2) === 0;
    }

    /** True si el proyecto ya tiene algún movimiento (INV-2: congela la membresía). */
    public function hasMovements(Project $project): bool
    {
        $count = $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\Movement m WHERE m.project = :project'
        )->setParameter('project', $project)->getSingleScalarResult();

        return (int) $count > 0;
    }
}
```

Inyectar por constructor donde haga falta (autowiring ya está configurado en el
proyecto).

### 3.2 Aplicar INV-1 en los controllers existentes (blindaje <100%)

**Objetivo:** con el proyecto no-activo, bloquear registrar gastos y pagos; permitir
`confirm`, `cancel` y todas las lecturas (verificado: esas operan sobre ledger ya
existente y el hueco de % no las afecta).

- **`ExpenseController::create`** — después del chequeo de membresía (`403`) y antes
  de validar el `amount`, agregar:

  ```php
  if (!$this->projectGuard->isActive($project)) {
      return new JsonResponse(
          ['error' => 'El proyecto no está activo: los porcentajes de los socios deben sumar 100% antes de registrar movimientos.'],
          409
      );
  }
  ```

- **`PaymentController::resolveAllocation`** — mismo bloque, después del chequeo de
  membresía. Con esto quedan cubiertos **preview** y **create** (ambos pasan por
  `resolveAllocation`). **No** tocar `confirm` ni `batch` (deben seguir funcionando
  con proyecto no-activo).

- **`MovementController::cancel`** — **no** tocar. Cancelar debe funcionar siempre.

Inyectar `ProjectGuard` en el constructor de `ExpenseController` y `PaymentController`.

## 4. Paso 2 — Endpoints nuevos

Convenciones para todos:
- Body JSON: `json_decode($request->getContent(), true) ?? []` (igual que los
  controllers actuales).
- Validaciones de string: `trim()` + chequeo de vacío → `400`.
- Recurso inexistente → `404`. Conflicto de estado/unicidad → `409`. Regla de
  dominio violada → `422`. Falta de permiso la maneja `#[IsGranted]` → `403`.
- Respuestas: devolver el recurso creado/modificado con su `id`. `201` en creación,
  `200` en edición.
- Los `#[Route]` se auto-importan (no tocar `config/routes/`).

### A1 · `POST /api/projects` (admin)
- **Body:** `{ "name": string }`.
- **Validar:** `name` no vacío → si no, `400`.
- **Hacer:** `new Project($name)`; persist + flush. Nace vacío → inactivo (0% ≠ 100).
- **200/201:** `201 { "id", "name", "active": false }`.
- Poner la acción en `ProjectController` con `#[IsGranted('ROLE_ADMIN')]`.

### A2 · `PATCH /api/projects/{id}` (admin)
- **Body:** `{ "name": string }`.
- **404** si no existe el proyecto. **400** si `name` vacío.
- `setName()` + flush. **200** `{ "id", "name" }`.

### A3 · `POST /api/members` (admin)
- **Body:** `{ "name": string, "email": string, "password": string, "admin"?: bool }`.
- **Validar:** los tres strings no vacíos (`400`); `email` con `filter_var(..., FILTER_VALIDATE_EMAIL)` (`400` si inválido); password mínimo 6 chars (`400`).
- **409** si ya existe un `Member` con ese `email` (ver `CreateMemberCommand` como
  referencia — misma lógica).
- **Hacer:** `new Member($name, $email)`; `setPassword($hasher->hashPassword(...))`;
  si `admin === true` → `setRoles(['ROLE_ADMIN'])`. Persist + flush.
- Inyectar `UserPasswordHasherInterface` (igual que `CreateMemberCommand`).
- **201** `{ "id", "name", "email", "roles" }`.

### A4 · `PATCH /api/members/{id}` (admin)
- **Body parcial:** cualquiera de `{ "name"?, "email"?, "active"? }`.
- **404** si no existe. Solo aplicar los campos presentes.
- Si viene `email` y cambia: validar formato (`400`) y unicidad (`409`).
- `active` es bool (soft-delete: `setActive(false)`).
- **NO** tocar roles acá (eso es A5). **200** `{ "id", "name", "email", "active", "roles" }`.

### A5 · `PUT /api/members/{id}/roles` (admin)
- **Body:** `{ "admin": bool }`. Endpoint dedicado (Marcos lo pidió aparte).
- **404** si no existe.
- **422** si el target es el propio usuario logueado y `admin === false`
  (evitar que un admin se auto-revoque y quede el sistema sin admins accesibles).
- **Hacer:** `admin === true` → `setRoles(['ROLE_ADMIN'])`; `false` → `setRoles([])`
  (el `getRoles()` de la entidad ya agrega `ROLE_MEMBER` solo). Flush.
- **200** `{ "id", "roles" }`.

### A6 · `PATCH /api/me` (self, cualquier autenticado)
- **Body parcial:** `{ "name"?, "email"?, "password"? }`. Actúa **solo** sobre
  `$this->getUser()`; nunca recibe id de otro. **NO** acepta campos de proyecto/%.
- `email`: validar formato (`400`) + unicidad contra otros (`409`).
- `password`: mínimo 6 chars (`400`) → `setPassword($hasher->hashPassword($me, $pw))`.
- **200** `{ "id", "name", "email" }`. (Va en `MemberController`.)

### A7 · `POST /api/projects/{id}/members` (admin)
- **Body:** `{ "memberId": int, "percentage": string|number }`.
- **404** si no existe el proyecto o el socio.
- **409 (INV-2)** si `projectGuard.hasMovements(project)` → *"No se puede modificar
  la membresía de un proyecto que ya tiene movimientos."*
- **409** si el socio ya está asociado a ese proyecto (membresía duplicada).
- **Validar** `percentage`: numérico, `> 0`, `<= 100`, escala 2 (`bcadd($p,'0',2)`).
  `422`/`400` si no.
- **422 (INV-3):** si `sumPercentage(project) + percentage > 100.00`
  (`bccomp(bcadd(sum,pct,2), '100.00', 2) > 0`).
- **Hacer:** `new MemberProject($member, $project, $percentage)`; persist + flush.
- **201** `{ "projectId", "memberId", "percentage", "projectActive": bool }`
  (`projectActive` = `isActive` recalculado tras el alta — así el cliente ve cuándo
  llegó a 100%).
- Poner en `ProjectController`.

### A9 · `DELETE /api/projects/{id}/members/{memberId}` (admin)
- **404** si no existe la membresía (proyecto+socio).
- **409 (INV-2)** si `projectGuard.hasMovements(project)` → mismo mensaje que A7.
- **Hacer:** remover la `MemberProject` (`$em->remove($mp)`) + flush.
- **200** `{ "projectId", "memberId", "projectActive": bool }`.

### A10 · `POST /api/materials` (cualquier socio)
- **Body:** `{ "name": string }`. **400** si vacío.
- **Idempotente (C8):** buscar por `normalizedName = Material::normalize($name)`.
  - Si existe → devolver el existente, **200** `{ "id", "name", "created": false }`.
  - Si no → `new Material($name)`, persist + flush, **201** `{ "id", "name", "created": true }`.
- Sin `#[IsGranted]` (basta estar autenticado). Va en `MaterialController`.

### A11 · `PATCH /api/materials/{id}` (admin)
- **Body:** `{ "name": string }`. **404** si no existe. **400** si vacío.
- **409** si otro material distinto ya tiene ese `normalizedName`.
- `setName()` (recalcula `normalizedName` solo) + flush. **200** `{ "id", "name" }`.

### A12 · `GET /api/movements/{id}` (socio del proyecto)
- **404** si no existe el movimiento.
- **403** si el usuario logueado no es socio del proyecto del movimiento
  (misma query de membresía que usan los demás controllers).
- **200** con el detalle. Campos (espejo de la serialización de `overview` + extras):
  `id, type, status, amount, fromMemberId, toMemberId, batchId, material (name),
  quantity, reason, createdById, createdAt`. Va en `MovementController`.

## 5. Paso 3 — Fixtures (admin de pruebas)

En `backend/src/DataFixtures/AppFixtures.php`, agregar un socio admin. La
`MemberFactory` hashea el password sola (`initialize()`), y acepta `roles`:

```php
MemberFactory::createOne([
    'name'     => 'Marcos Soler',
    'email'    => 'marcosfsoler@gmail.com',
    'password' => 'marcos32',
    'roles'    => ['ROLE_ADMIN'],
]);
```

Agregarlo junto a los demás `MemberFactory::createOne(...)` (no hace falta asociarlo
a ningún proyecto: como admin no necesita membresía para las ops de administración).
Recargar: `doctrine:fixtures:load --group=dev --no-interaction`.

## 6. Paso 4 — Colección Bruno (`api/`)

Agregar un `.bru` por cada endpoint nuevo, siguiendo el formato exacto de los
existentes (ver `api/projects/01-list.bru`, `api/expenses/01-create-expense.bru`).
`auth: bearer` con `token: {{token}}` salvo indicación. Carpetas nuevas: `admin/`
ya existe (cancel) — agregar ahí las ops de admin, o crear subcarpetas por recurso
si queda más claro. Sugerido:

| Archivo | Request |
|---|---|
| `projects/05-create.bru` | A1 `POST /api/projects` |
| `projects/06-rename.bru` | A2 `PATCH /api/projects/{{project_id}}` |
| `projects/07-add-member.bru` | A7 `POST /api/projects/{{project_id}}/members` |
| `projects/08-remove-member.bru` | A9 `DELETE /api/projects/{{project_id}}/members/{{member_id}}` |
| `members/02-create.bru` | A3 `POST /api/members` |
| `members/03-edit.bru` | A4 `PATCH /api/members/{{member_id}}` |
| `members/04-set-roles.bru` | A5 `PUT /api/members/{{member_id}}/roles` |
| `members/05-me-update.bru` | A6 `PATCH /api/me` |
| `materials/02-create.bru` | A10 `POST /api/materials` |
| `materials/03-rename.bru` | A11 `PATCH /api/materials/{{material_id}}` |
| `movements/01-get.bru` | A12 `GET /api/movements/{{movement_id}}` |

- Para las ops de **admin**, el flujo es: `qa/02-members.bru` para ver ids →
  setear `member_id` al de **Marcos** (el admin) → `qa/01-login-as.bru` para tener
  `{{token}}` de admin → correr el request de admin.
- Agregar al environment (`api/environments/staging.bru`) las vars que falten:
  `material_id`. `member_id`, `project_id`, `batch_id`, `movement_id` ya existen.
- Actualizar `api/README.md`: nueva sección "Administración (crear proyectos/socios/
  membresías/materiales)" explicando que requiere token de admin (login-as sobre
  Marcos) y el flujo de armar un proyecto de cero: crear proyecto → agregar socios
  hasta 100% → recién ahí acepta gastos/pagos.

## 7. Paso 5 — Tests

El proyecto solo tiene tests unitarios de dominio (`tests/Domain/`, sin DB). No hay
harness funcional HTTP. Estrategia (no montar infra de tests nueva salvo que sobre
tiempo):

1. **Verificación de integración primaria = colección Bruno** (es la convención del
   proyecto, ver `CLAUDE.md` runbook §12). Correr cada `.bru` contra dev y confirmar
   los códigos esperados. **Obligatorio.**
2. **Unit test opcional del guard:** si querés cubrir INV-1/INV-3 con PHPUnit, la
   lógica pura testeable es la suma/comparación de %. Podés extraer un helper puro
   `percentageExceeds100(string $sum, string $add): bool` y testearlo en
   `tests/Domain/`, sin DB. No es obligatorio.
3. **NO** intentar montar `WebTestCase` con base de datos si no hay tiempo: no existe
   template en el repo y es más riesgo que valor para esta feature.

Antes de cerrar, correr lo que ya hay verde:

```bash
docker compose exec backend vendor/bin/phpunit
docker compose exec backend bin/console doctrine:schema:validate   # debe seguir OK
```

## 8. Paso 6 — Verificación end-to-end (checklist manual)

Correr en dev con las fixtures cargadas. Esto **prueba las dos invariantes**, que es
lo más importante:

1. **Login como admin:** `login-as` sobre Marcos (`marcosfsoler@gmail.com`).
2. **Crear proyecto vacío** (A1) → responde `active: false`.
3. **Gasto rechazado en proyecto inactivo:** intentar `POST .../expenses` sobre ese
   proyecto → **409** (INV-1 funciona).
4. **Agregar socios** (A7) hasta que la suma llegue a **100%** → el último alta
   responde `projectActive: true`.
5. **Alta que excede 100%** (A7 con un % que pase de 100) → **422** (INV-3).
6. **Gasto ahora aceptado:** `POST .../expenses` sobre el proyecto ya activo → **201**.
7. **Congelamiento:** con el proyecto ya con movimientos, intentar A7 o A9 → **409**
   (INV-2 funciona).
8. **Confirmar/cancelar siguen andando** aunque toques cosas: crear un pago, confirmarlo
   con `login-as` del acreedor → OK.
9. **Material idempotente** (A10): crear "Cemento" dos veces → segunda vez
   `created: false` con el mismo `id`.
10. **Self-service** (A6): `login-as` de un socio común, `PATCH /api/me` cambiando su
    nombre → **200**; confirmar que **no** puede tocar proyectos ni %.
11. **Roles** (A5): admin promueve a un socio a admin → **200**; el socio ahora puede
    crear proyectos.

Dejar registro del resultado del checklist (aunque sea un comentario en el PR/commit).

## 9. Commits

- Trabajar en `feat/api-completion`. Sugerido separar en commits temáticos:
  `feat(api): project guard + blindaje <100%`, `feat(api): projects/members/roles
  write endpoints`, `feat(api): membership + materials endpoints`, `test(fixtures):
  admin de pruebas`, `docs(api): bruno requests + README`.
- Registrar en `agent-commits.md` **solo** si algún commit tiene una decisión de
  diseño no obvia que valga documentar (formato detallado, ver `CLAUDE.md`
  §"Protocolo de commits del agente"). El guard/INV-2 (congelamiento per-proyecto)
  es candidato razonable a registrar. Preguntarle a Marcos antes de anotar.
- **No** pushear ni abrir PR salvo que Marcos lo pida.

## 10. Fuera de alcance — para la sesión de rediseño

Lo que este runbook **deja afuera a propósito** y espera a una sesión + rama aparte
(ver la memoria de diseño del proyecto):

- **A8 (editar % de una membresía)** y, más en general, **la mutabilidad de
  membresías/% en proyectos con historia.** El balance se **deriva del % actual en
  cada lectura**, así que editar un % o sacar un socio reescribe retroactivamente la
  historia. El congelamiento (INV-2) es un **parche** que evita el problema
  prohibiendo el cambio, no lo resuelve de raíz.
- La solución integral (¿snapshot del % por movimiento? ¿% versionado? ¿congelar
  formalmente el proyecto al primer movimiento como estado explícito?) se **planifica
  y se reevalúa si tiene sentido** en esa sesión. Los cambios de este runbook están
  hechos para **no estorbar** ese rediseño: el `ProjectGuard` es chico y descartable,
  y no se agregó ninguna columna/migración.
