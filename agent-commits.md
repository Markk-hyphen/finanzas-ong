# Registro de commits del agente

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
