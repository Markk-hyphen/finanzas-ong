# Registro de commits del agente

## [FASE 5 — prep] fix: port del upgrade de Oliva (Compose profiles) a finanzas-ong
**Hash:** `03241e1`
**Rama:** `main`
**Fecha:** 2026-06-17

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `docker-compose.yml` | modificado | `rabbitmq` pasa a `profiles: ["queues"]`; se quita `depends_on: rabbitmq` de `backend` y `scheduler` (no usan Messenger) |
| `docker-compose.override.yml` | modificado | `worker` pasa a `profiles: ["queues"]`; se quita `depends_on: rabbitmq` de `scheduler` |
| `.env.example` | modificado | agrega `COMPOSE_PROFILES=` documentado (vacío por defecto) |
| `.env` | modificado (gitignored) | agrega `COMPOSE_PROFILES=` vacío |

### Justificación
finanzas-ong forkeó Oliva antes de que existiera el mecanismo de Compose profiles (`COMPOSE_PROFILES`, commit `9d2abc4` en Oliva). Es prerequisito de la Fase 5 (deploy a prod, ver `docs/deploy-vps-planificacion.md` y el runbook de deploy): sin esto, `rabbitmq`/`worker` se levantarían siempre aunque la app no los use, desperdiciando RAM en un VPS de 1 GB. Se eligió el port a mano (Opción A del runbook) en vez de cherry-pick porque la divergencia entre ambos repos era mínima (3-4 líneas, confirmado con diff directo contra Oliva). Verificado localmente: `docker compose config --services` sin profile devuelve solo `database`/`backend`/`frontend`/`scheduler`; con `COMPOSE_PROFILES=queues` aparecen también `rabbitmq`/`worker`. Se levantó el stack real (`docker compose up -d`) sin profile y `GET /health` respondió `{"status":"ok"}` con solo los 4 servicios core corriendo.

---

## [FASE 5 — plan] docs: guía de planificación VPS + infra para apps Oliva
**Hash:** `<pendiente>`
**Rama:** `main`
**Fecha:** 2026-06-16

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `docs/deploy-vps-planificacion.md` | nuevo | Guía de planificación para contratar VPS y montar la infra de deploy de apps Oliva |

### Justificación
El usuario quiere dejar registrado el plan de infraestructura discutido (elección de VPS, costos, infra compartida, fases de ejecución, backups) para retomarlo cuando contrate el servicio. Decisiones clave documentadas: datacenter en São Paulo por usuarios finales en Argentina (~30-50 ms vs ~250 ms a Europa); arranque con plan 1 GB (~$6 USD/mes) para la app de la ONG; patrón de infra compartida (un Postgres + un RabbitMQ para todas las apps) para escalar a 5-20 apps sin multiplicar el consumo de RAM. Se documenta también la deuda conocida del `worker` sin definición de prod (de CLAUDE.md). Es un doc de planificación, no toca código de la app.

---

## [FASE 4 — fix] fix: show login error in Spanish instead of Lexik's English message
**Hash:** `04afc57`
**Rama:** `main`
**Fecha:** 2026-06-16

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `frontend/src/js/api.js` | modificado | Reemplaza `d.message` (mensaje en inglés de Lexik) por texto fijo en español |

### Justificación
Lexik devuelve `"Invalid credentials."` en inglés cuando el login falla. En vez de confiar en el mensaje del backend (que puede cambiar o ser en otro idioma), se hardcodea el texto orientado al usuario directamente en el frontend: "Email o contraseña incorrectos." Esto es coherente con la convención del proyecto: el código va en inglés, los textos al usuario van en español.

---

## [FASE 4] feat: Phase 4 — mobile-first SPA frontend
**Hash:** `da84d50`
**Rama:** `main`
**Fecha:** 2026-06-16

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `backend/src/Controller/MemberController.php` | nuevo | GET `/api/me` — devuelve id, name, email, roles del usuario autenticado |
| `frontend/index.html` | modificado | Reemplaza demo Oliva por shell del SPA (navbar fija + `#app`) |
| `frontend/src/style.css` | modificado | Reemplaza estilos de la demo por reset mínimo + helper autocomplete |
| `frontend/src/main.js` | modificado | Reemplaza entrada de la demo por router hash-based + dispatcher |
| `frontend/src/js/api.js` | nuevo | Fetch wrapper con JWT, storage helpers, isAdmin() |
| `frontend/src/js/nav.js` | nuevo | navigate() y setNav() para controlar la barra superior |
| `frontend/src/js/views/login.js` | nuevo | Pantalla de login: llama /api/login + /api/me, guarda en localStorage |
| `frontend/src/js/views/projects.js` | nuevo | Lista de proyectos del socio autenticado |
| `frontend/src/js/views/dashboard.js` | nuevo | Dashboard: balance propio, balances del proyecto, movimientos, pendientes de confirmar, anular (admin) |
| `frontend/src/js/views/expense.js` | nuevo | Formulario de gasto con autocomplete de materiales y SweetAlert2 para warning de anomalía |
| `frontend/src/js/views/payment.js` | nuevo | Formulario de pago: preview del reparto → confirmar |

### Justificación
Se extrae `navigate()` y `setNav()` a `nav.js` para evitar dependencias circulares con `main.js` (que importa las views, que necesitan navegar). El `GET /api/me` se agrega al backend porque el JWT de Lexik solo contiene email, no ID; el frontend necesita el ID para identificar el balance propio en el dashboard. El build de Vite confirma que todos los módulos resuelven correctamente (13 modules, 0 errores).

## [FASE 3] feat: Phase 3 — REST API with JWT auth and roles
**Hash:** `f406e0b`
**Rama:** `main`
**Fecha:** 2026-06-16

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `backend/src/Application/MovementLoader.php` | nuevo | Carga Movement entities de un proyecto y los mapea a MovementRecord[] para el dominio |
| `backend/src/Application/ShareLoader.php` | nuevo | Carga MemberProject entities de un proyecto y los mapea a MemberShare[] para el dominio |
| `backend/src/Domain/Service/BalanceCalculator.php` | modificado | Agrega `computeTotalCost()` público que filtra activos y suma gastos |
| `backend/src/Controller/LoginController.php` | nuevo | Ruta GET `/api/login` requerida para que json_login de Symfony registre el endpoint en el router |
| `backend/src/Controller/ProjectController.php` | nuevo | GET `/api/projects` y GET `/api/projects/{id}/dashboard` |
| `backend/src/Controller/ExpenseController.php` | nuevo | POST `/api/projects/{id}/expenses` con validación y warning de anomalía |
| `backend/src/Controller/PaymentController.php` | nuevo | POST preview/create de pagos y POST confirm por batch_id |
| `backend/src/Controller/MovementController.php` | nuevo | POST `/api/movements/{id}/cancel` (ROLE_ADMIN only, cancela batch entero si aplica) |
| `backend/src/Controller/MaterialController.php` | nuevo | GET `/api/materials?q=...` autocomplete por normalized_name |

### Justificación
La capa Application (`MovementLoader`, `ShareLoader`) es el único punto donde se traduce entre Doctrine entities y los value objects del dominio puro. Esto mantiene `Domain/` sin dependencias de Symfony/Doctrine. El `LoginController` existe solo para registrar la ruta `/api/login` en el router de Symfony — el security firewall intercepta la request antes de que el método se ejecute. El endpoint de cancelación usa `#[IsGranted('ROLE_ADMIN')]` en vez de `access_control` en security.yaml para mantener la restricción co-localizada con el código. Para batch payments, `cancel` crea una fila de anulación por cada movimiento del batch (append-only). Los 403 devuelven HTML en dev por el ExceptionListener de Symfony — comportamiento esperado en development.

## [FASE 2] feat: Phase 2 — pure domain logic and tests
**Hash:** `d531ba3`
**Rama:** `main`
**Fecha:** 2026-06-16

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `backend/src/Domain/Model/MovementRecord.php` | nuevo | Value object inmutable de movimiento para el dominio puro |
| `backend/src/Domain/Model/MemberShare.php` | nuevo | Value object con memberId + percentage |
| `backend/src/Domain/Model/Allocation.php` | nuevo | Value object de resultado de reparto (toMemberId + amount) |
| `backend/src/Domain/Strategy/AllocationStrategy.php` | nuevo | Interfaz del allocator — seam para estrategias futuras |
| `backend/src/Domain/Strategy/ProportionalToCredit.php` | nuevo | Implementación MVP: reparto proporcional al crédito con largest-remainder |
| `backend/src/Domain/Service/BalanceCalculator.php` | nuevo | Fold sobre movimientos activos; distribuye obligaciones con largest-remainder para garantizar Σ=0 |
| `backend/src/Domain/Service/AllocationValidator.php` | nuevo | Valida resultado del reparto (suma, cap por acreedor, cap por deuda del pagador) |
| `backend/src/Domain/Service/AnomalyDetector.php` | nuevo | Warning si monto > 5×mediana; nunca bloquea |
| `backend/tests/Domain/*Test.php` (×4) | nuevo | Suite PHPUnit: 29 tests, 143 assertions |
| `backend/Dockerfile` | modificado | Agrega extensión bcmath |

### Justificación
El error de conservación (1 test fallido inicialmente) se debía a que la suma de obligaciones redondeadas a 2 decimales no era exactamente igual al costo total. Se resolvió aplicando el mismo algoritmo de largest-remainder ya usado en el allocator, garantizando que la distribución de obligaciones sea exacta centavo a centavo.

## [FASE 1] feat: Phase 1 — entities, migration, security config
**Hash:** `daaa7d3`
**Rama:** `main`
**Fecha:** 2026-06-16

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `backend/src/Entity/Member.php` | nuevo | Entidad user con UserInterface/PasswordAuthenticatedUserInterface para JWT |
| `backend/src/Entity/Project.php` | nuevo | Entidad proyecto |
| `backend/src/Entity/MemberProject.php` | nuevo | Join entity con PK compuesta (member+project) y campo `percentage` |
| `backend/src/Entity/Material.php` | nuevo | Entidad material con `normalized_name` generado automáticamente vía `iconv` |
| `backend/src/Entity/Movement.php` | nuevo | Ledger append-only; constantes para tipos y estados; `$cancelsMovement` auto-referencial documentado |
| `backend/migrations/Version20260616145224.php` | nuevo | Migración con todas las tablas + 8 CHECK constraints (tipo, estado, forma por tipo, monto, porcentaje) |
| `backend/config/packages/security.yaml` | modificado | User provider apunta a `Member::email`; firewall JWT para `/api`; login en `/api/login` |
| `CLAUDE.md` | modificado | Agrega convención: todo el código fuente en inglés (PHP, JS, DB, config) |

### Justificación
Los nombres de tabla/columna en inglés se eligieron porque el usuario estableció que toda convención de código es en inglés — esto incluye identifadores generados por Doctrine. Los CHECK constraints se agregan manualmente a la migración porque Doctrine no los genera desde atributos PHP. El `security.yaml` se configura aquí (no en Fase 3) porque el firewall JWT debe existir para que el cache compile correctamente; los endpoints concretos se agregan en Fase 3.



## [FASE 0] chore: agregar manifiesto del proyecto y registro de commits del agente
**Hash:** `aafad92`
**Rama:** `main`
**Fecha:** 2026-06-13

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| MANIFIESTO.md | nuevo | Especificación del sistema de finanzas ONG (dominio, modelo de datos, reglas, fases) |
| agent-commits.md | nuevo | Registro de commits del agente, según protocolo de CLAUDE.md |

### Justificación
`MANIFIESTO.md` estaba sin trackear desde antes de esta sesión; se commitea como punto de partida documentado del proyecto. `agent-commits.md` se crea ahora porque el proyecto lo requiere para trabajo autónomo/multi-sesión (CLAUDE.md, "Protocolo de commits del agente").

Como parte de Fase 0 (Reconocimiento) se relevaron las convenciones del repo: no hay entidades de dominio, controllers más allá de `HealthController`, ni implementación de JWT/auth (el bundle está presente pero sin `User` entity ni claves generadas). No hay nada de dominio para "espejar" — Fase 1/3 deberá crear esos patrones desde cero. También se creó `.env` (raíz, gitignored) con valores dev consistentes con `.env.backend`/`.env.frontend` y se verificó que `docker compose up` levanta todos los servicios healthy, `/health` responde `ok`, Mercure responde como espera el CI y no hay migraciones pendientes (solo la de pgvector).
