# Registro de commits del agente

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
