# Registro de commits del agente

## [QA] feat: 5 endpoints QA para testeo manual con Bruno
**Hash:** `6758a3d`
**Rama:** `main`
**Fecha:** 2026-07-01

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `backend/src/Controller/QaController.php` | nuevo | `POST /api/qa/login-as/{memberId}` — genera un JWT del socio pedido sin password, vía `JWTTokenManagerInterface::create()` |
| `backend/src/Controller/ProjectController.php` | modificado | `GET /api/projects/{id}/members` (socios + % del proyecto) y `GET /api/projects/{id}/overview` (balances/movements/pendingPayments de TODOS los socios, no solo el logueado) |
| `backend/src/Controller/MemberController.php` | modificado | `GET /api/members` — lista global de socios |
| `backend/src/Controller/PaymentController.php` | modificado | `GET /api/payments/{batchId}` — inspecciona los movimientos de un batch |
| `api/qa/01-login-as.bru`, `api/members/01-list.bru`, `api/projects/03-members.bru`, `api/projects/04-overview.bru`, `api/payments/04-batch.bru` | nuevo | Requests Bruno para los 5 endpoints, mismo patrón que la collection existente |
| `api/environments/{staging,prod}.bru` | modificado | agrega var `member_id` |

### Justificación
Objetivo: eliminar la fricción de testear a mano flujos multi-usuario (ej. confirmar un pago exige loguearse como el socio receptor). Decisión consciente y explícita del usuario: estos endpoints **no llevan el check de membresía** (`403 Access denied`) que sí tienen `dashboard`/`expense`/`payment` — son herramientas de testeo, no vistas de negocio, y esa capa de auth extra no aporta nada al objetivo.

El diseño no obvio es el guard de `login-as`: compara `kernel.environment === 'prod'` (no `'production'`) para tirar 404 en producción. Este proyecto usa nombres de entorno CORTOS desde el merge `1932745` (`prod`/`dev`/`staging`, ver `agent-commits.md` [SYNC] y `docs` de [[project-reference]]) — comparar contra el nombre largo viejo habría dejado el endpoint abierto en prod de forma silenciosa. Se verificó el guard de punta a punta: se compiló la imagen `app-backend-prod:1.0` y se corrió un container aislado con `APP_ENV=prod` contra la misma DB de dev — `login-as` devolvió 404 real, mientras `/api/members` (sin guard) siguió respondiendo 200, confirmando que el bloqueo es específico y no colateral.

Se decidió que `login-as` siga exigiendo un token válido previo (no se tocó `security.yaml` ni `access_control`) en vez de hacer la ruta pública: evita abrir una superficie nueva en el firewall y el riesgo de ordenar mal las reglas de `access_control`, y de todos modos cumple el objetivo (un solo login real con password, después impersonar a cualquiera).

## [SYNC] Merge oliva-upstream/main — aislamiento de entornos + APP_ENV corto + down-staging + CI
**Hash:** `1932745`
**Rama:** `main`
**Fecha:** 2026-06-30

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| docker-compose.prod.yml | modificado | `APP_ENV=production` → `prod` (backend + scheduler) |
| docker-compose.override.yml | modificado | `APP_ENV=development` → `dev` (3 servicios) |
| docker-compose.staging.yml | modificado | comentarios actualizados a `prod`; código de override sin cambios |
| docker-compose.shared-infra.yml | modificado | resuelto a favor de los comentarios detallados propios (alias derivado, sin cambio de código) |
| .env.example | modificado | `APP_ENV=development` → `dev` |
| backend/Dockerfile | modificado | stage `frankenphp_staging`: `ENV APP_ENV=staging` + `rm .env.local.php` heredado del stage prod |
| backend/config/packages/zenstruck_foundry.yaml | modificado | resolución trivial de whitespace (ya tenía `when@staging`) |
| scripts/deploy-staging.sh | modificado | `up -d --build` (build-everything) en vez de enumerar servicios |
| scripts/down-staging.sh | nuevo | análogo a `down.sh` para el stack de staging (4 overlays, `-p <app>-staging`, sin `-v`) |
| .github/workflows/ci.yml | modificado | guard `prod-config` (falla si `APP_ENV != prod`), smoke post-merge, caché de capas GHA |
| CLAUDE.md | modificado | resuelto a favor del texto propio; numeración de ambos runbooks corregida (shared-infra 1-12, staging 1-7) |
| README.md | modificado | se mantiene la tabla de stack en español de la app (se descartó la tabla en inglés del framework) |
| agent-commits.md | conflicto add/add | resuelto `--ours`: el log es de la app, no se importan hashes del repo de Oliva |

### Justificación
El backport a Oliva (framework, `~/app`) de los fixes que finanzas-ong había aplicado
primero (alias derivado `3bb2b12`, frontend staging `ac2e02a`, override APP_ENV `9523ca8`)
ya estaba en `oliva-upstream/main`. Este merge lo trae de vuelta más lo genuinamente nuevo.

**Por qué un merge y no cherry-pick:** mantener la línea limpia con `oliva-upstream` para
futuros pulls. La mayoría de los 8 conflictos eran duplicados (misma idea, otra redacción):
se resolvieron conservando los comentarios más informativos y tomando la única mejora real
de código (`deploy-staging.sh` con `up -d --build`, robusto ante nuevos servicios buildeables).

**El cambio de riesgo (APP_ENV corto):** en prod, Symfony matchea `when@prod` por string
exacto. Con `APP_ENV=production` (largo) ese bloque NUNCA aplicaba → prod corría sin proxies
precompilados ni pools de cache de Doctrine. El fix pasa a `prod` (corto) y los reactiva.
Riesgo real bajo (los pools usan filesystem, no Redis; los proxies se hornean en build), pero
es el estreno de ese path en prod — por eso el deploy es en 2 olas (staging → prod vigilado).
Staging usa `APP_ENV=staging` (`when@staging: *dev`), así que NO ejercita `when@prod`: ese
cambio solo se valida en prod. Rollback trivial: `git revert -m 1 1932745`.

Validado antes del push con `docker compose config` para `-p ong` (APP_ENV=prod, alias
`ong-frontend`) y `-p ong-staging` (APP_ENV=staging, alias `ong-staging-frontend`).

## [DX] feat(api): Bruno collection para testing de todos los endpoints
**Hash:** `27ff98c`
**Rama:** `main`
**Fecha:** 2026-06-30

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| api/bruno.json | nuevo | Raíz de la collection Bruno |
| api/.env.example | nuevo | Template de credenciales secretas para prod |
| api/environments/staging.bru | nuevo | Environment con usuario seed real (ezequiel.torrontegui/asdasd) y project_id: 1 |
| api/environments/prod.bru | nuevo | Environment prod; login/password en api/.env gitignoreado |
| api/auth/01-login.bru | nuevo | POST /api/login + script post-response que captura el token automáticamente |
| api/auth/02-me.bru | nuevo | GET /api/me |
| api/health/01-health.bru | nuevo | GET /health |
| api/projects/01-list.bru | nuevo | GET /api/projects |
| api/projects/02-dashboard.bru | nuevo | GET /api/projects/{{project_id}}/dashboard |
| api/expenses/01-create-expense.bru | nuevo | POST /api/projects/{{project_id}}/expenses |
| api/payments/01-preview.bru | nuevo | POST /api/projects/{{project_id}}/payments/preview |
| api/payments/02-create-payment.bru | nuevo | POST /api/projects/{{project_id}}/payments + captura batch_id |
| api/payments/03-confirm.bru | nuevo | POST /api/payments/{{batch_id}}/confirm |
| api/admin/01-cancel-movement.bru | nuevo | POST /api/movements/{{movement_id}}/cancel (ROLE_ADMIN) |
| api/materials/01-search.bru | nuevo | GET /api/materials?q=tierra |

### Justificación
El caso de uso que disparó esto ("confirmar todos los pagos pendientes del proyecto X del usuario Y") requería login → dashboard → copiar batch_ids → confirmar cada uno a mano. Con la collection: Login captura el token vía script post-response, Create Payment captura el batchId automáticamente, Confirm lo consume — 3 clicks sin copy-paste.

Se evaluó si la collection debía vivir en Oliva (framework base). Decisión: NO como archivos físicos (Bruno es app externa, los requests son 100% dominio de finanzas-ong, n=1 es prematuro para extraer convención de archivos). SÍ como convención documentada en Oliva: una línea en README + un paso en el runbook de deploy simétrico al `fixtures:load` de seeding. Se ejecuta en sesión separada sobre `~/app`.

## [STAGING — fix] fix(staging): imagen propia de frontend (app-frontend-staging) + build en deploy
**Hash:** `ac2e02a`
**Rama:** `main`
**Fecha:** 2026-06-30

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `docker-compose.staging.yml` | modificado | Nuevo bloque `frontend` con `image: app-frontend-staging:1.0`. Hereda el build de prod.yml (`context: frontend`, `target: production`); solo cambia el tag para no compartir imagen con prod |
| `scripts/deploy-staging.sh` | modificado | `build backend` → `build backend frontend` (el frontend nunca se rebuildeaba en staging). Se elimina del header el prerequisito #3 obsoleto (placeholder `CHANGE-THIS-ALIAS-frontend`, ya inexistente desde el alias derivado de `3bb2b12`) |

### Justificación
**El bug (login roto en staging):** el frontend de staging no tenía imagen propia → heredaba `app-frontend-prod:1.0` de `prod.yml`, y `deploy-staging.sh` solo corría `build backend`. Resultado: el bundle JS de staging era el viejo (de antes del login-por-username, `66f7473`/`c52e11b`) y posteaba la clave anterior (`email`/`username`) en vez de `login`. El backend, con `username_path: login` en `security.yaml`, devolvía `400 BadRequestHttpException "The key 'login' must be provided"`; el frontend, al parsear la página de excepción HTML de Symfony como JSON, explotaba con `"Unexpected token '<'"`. El JWT (sospechoso inicial) estaba intacto — las claves se generaron bien en el primer boot y el entrypoint las salteó correctamente por existir ya.

**Por qué imagen propia y no solo `build frontend`:** dar a staging su tag `app-frontend-staging:1.0` lo aísla a nivel imagen igual que el backend (`app-backend-staging:1.0`). Sin tag propio, rebuildear el front en staging pisaría la imagen `app-frontend-prod:1.0` que sirve producción — footgun si staging está en un commit adelantado a prod. El frontend era la **única** pieza de staging no aislada de prod; este fix la alinea con el resto. Es el **tercer caso del mismo patrón** de aislar staging por recurso scopeado al entorno: alias de red derivado del project name (`3bb2b12`) → imagen de backend (`app-backend-staging`) → ahora imagen de frontend.

**Fuera de alcance:** no se tocó el frontend de prod ni su tag (prod sigue en `app-frontend-prod:1.0`). El target de build del frontend de staging sigue siendo `production` a propósito — el front no necesita dev-deps (a diferencia del backend, que en staging suma Foundry/fixtures); solo cambia el nombre de la imagen.

---

## [STAGING — fix] fix(staging): alias de frontend derivado del project name (anti-colisión proxy_net)
**Hash:** `3bb2b12`
**Rama:** `main`
**Fecha:** 2026-06-29

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `docker-compose.shared-infra.yml` | modificado | Alias del frontend en `proxy_net`: `ong-frontend` (hardcodeado) → `${COMPOSE_PROJECT_NAME:-app}-frontend` (derivado). Comentarios del requisito #3 y del bloque frontend reescritos para explicar el alias derivado |
| `CLAUDE.md` | modificado | Sección shared-infra ("Cada fork debe…") y runbook (paso 5) ya no piden reemplazar el placeholder `CHANGE-THIS-ALIAS-frontend`; runbook de staging gana paso 8 (vhost `staging.<dominio>` propio, scp + caddy reload) |

### Justificación
**El bug:** `proxy_net` es una red **externa compartida** entre todos los stacks `-p` del VPS. El alias del frontend estaba hardcodeado (`ong-frontend`), así que prod (`-p ong`) y staging (`-p ong-staging`) registraban el **mismo** alias en esa red → el reverse-proxy de infra hacía round-robin entre ambos frontends y el dominio de prod podía rutear intermitentemente al frontend de staging (que pega a la DB desechable). Riesgo real para prod si staging quedaba up. Confirmado empíricamente con `docker compose config` (staging heredaba `ong-frontend`).

**Por qué derivar del project name y no `!override` en `staging.yml`:** derivar el alias de `${COMPOSE_PROJECT_NAME}` resuelve la **clase entera** del bug con un cambio de una línea, es coherente con la línea ya existente que deriva el *network name* (`${COMPOSE_PROJECT_NAME:-app}_app_network`), no necesita `!override` sobre una lista anidada (más frágil de mergear), no toca `docker-compose.staging.yml`, y **elimina un paso manual** del runbook (ya no hay placeholder que reemplazar por fork). Verificado: `-p ong` sigue dando `ong-frontend` (idéntico a hoy → no toca prod en runtime ni rompe el Caddyfile), `-p ong-staging` da `ong-staging-frontend`.

**Fuera de alcance (a propósito):** (a) `provision-postgres.sh` ya crea la extensión `vector` como superuser — el "posible bug" de la memoria era histórico, no requiere cambio; (b) la deuda `when@prod` (APP_ENV largo `production` no activa los bloques `when@prod`) es de optimización y tocarla impacta prod en runtime → se deja para tratar aparte. El vhost de staging en `~/infra/caddy/Caddyfile` se editó local pero NO va en este commit (infra es repo aparte, se sincroniza por scp).

---

## [FASE 3 — decisión] docs: ir a infra compartida antes de levantar la app
**Hash:** `9c5f0a1`
**Rama:** `main`
**Fecha:** 2026-06-24

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `docs/deploy-vps-planificacion.md` | modificado | Marca como decidida la "infra compartida desde el día 1" en §7; agrega §8 "Próximos pasos — Fase 3" con el plan detallado de retomada (red `shared_network`, `~/infra/` con Postgres+RabbitMQ+Caddy, base/vhost por app, adaptación del compose de finanzas-ong); actualiza el "Estado" del doc apuntando a §8 como próximo paso |

### Justificación
El usuario confirmó que crypto-market-pulse (segunda app sobre Oliva) está por deployarse pronto, así que en vez de levantar finanzas-ong con su Postgres/RabbitMQ propios y migrar después, se decide montar la infra compartida (Fase 3 del plan) **antes** de levantar la app. Esto evita re-tocar todo cuando aparezca la segunda app y aprovecha el droplet de 1 GB (la motivación original de la infra compartida: no multiplicar Postgres/RabbitMQ en idle por cada app). Se documentó como §8 un punto de retomada explícito porque acá termina la sesión y el próximo Claude tiene que arrancar por la infra compartida, no por el `up -d` de la app. NOTA: finanzas-ong no usa colas (sin profile `queues`), así que el RabbitMQ compartido es opcional de arranque — lo estrenará crypto-market-pulse si usa Messenger.

---

## [FASE 5 — ejecución] docs: repo privado en GitHub + deploy key del VPS
**Hash:** `b17b69b`
**Rama:** `main`
**Fecha:** 2026-06-24

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `docs/deploy-vps-planificacion.md` | modificado | Reemplaza el paso "Clonar el repo" de la Fase 5 por un walkthrough completo de cómo se creó `finanzas-ong` como repo privado en GitHub y cómo el VPS obtuvo acceso de lectura sin exponer un PAT amplio; actualiza el "Estado" del doc de "planificación" a "en ejecución" |

### Justificación
El repo `finanzas-ong` nunca tuvo remoto propio (solo `oliva-upstream` apuntando al framework). Al ir a deployar se hizo todo el bootstrap real: intento fallido con un token fine-grained (403, sin permiso de crear repos a nivel cuenta), creación del repo con un token classic `repo`+`workflow` (este último necesario por `.github/workflows/ci.yml`), push inicial, y — para no dejar ese token amplio en el VPS — generación de una deploy key SSH de solo lectura específica del repo, registrada vía API (`read_only: true`) y usada con un alias en `~/.ssh/config` del servidor. El doc de deploy no tenía nada de esto documentado (asumía "clonar el repo" como un paso trivial) y el usuario pidió explícitamente dejarlo registrado para no repetir la investigación en el futuro (p.ej. al deployar la segunda app sobre el mismo VPS).

---

## [FASE 5 — prep] merge: oliva-upstream/main (cierre del gap de upgrade manual)
**Hash:** `7bf530e` (merge) precedido por `1cbf55d`
**Rama:** `main`
**Fecha:** 2026-06-24

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `.env.example` | modificado (`1cbf55d`) | Iguala el comentario de `COMPOSE_PROFILES` al de Oliva upstream (menciona Mercure embebido y formato de lista de perfiles), para que el merge no generara un conflicto trivial |
| `CLAUDE.md` | conflicto resuelto (ours) | Se mantuvo la sección "Convención de idioma en el código", específica de finanzas-ong y ausente en Oliva — no era contenido en conflicto real, solo posición de líneas |
| `claude-commands.md` | conflicto resuelto (ours, add/add) | Oliva trajo una versión vieja de `claude reiniciar` sin el flag `-f` ni el path de memoria correcto; se conservó la versión local (`[x=60] [-f]`, memoria en `-home-marcos-finanzas-ong`) |
| `README.md` | merge automático | Se actualizó al README genérico de Oliva más reciente (tabla de profiles, badges, arquitectura) — finanzas-ong nunca lo customizó, así que no hay pérdida de contenido propio |
| `oliva-upgrade-optional-services.md` | nuevo (vía merge) | Doc de spec del framework que documenta el upgrade de Compose profiles; llega tal cual desde Oliva |
| `docker-compose.yml`, `docker-compose.override.yml`, `docker-compose.prod.yml` | sin cambios | Ya eran idénticos a `oliva-upstream/main` (port a mano previo, commit `03241e1`), el merge no tocó nada ahí |

### Justificación
finanzas-ong forkeó Oliva en el commit `b5e5e4f` y desde entonces venía portando los upgrades del framework a mano (ver `03241e1`), generando deuda: 6 commits de Oliva (`59b78f3`...`4236b5d`) quedaban "huérfanos", sin reflejarse en el historial de finanzas-ong aunque su contenido funcional ya estuviera replicado. El usuario preguntó si era viable evitar el port a mano a futuro. Se confirmó que sí: como finanzas-ong comparte historia real con Oliva (hay merge-base), un `git merge oliva-upstream/main` normal funciona — los conflictos solo aparecen en los pocos archivos que ambos repos tocan (`CLAUDE.md`, `claude-commands.md`, `README.md`, `.env.example`), nunca en el código específico de la app (entidades, controllers, frontend SPA), que vive en paths que Oliva no conoce. Se hizo el merge para cerrar el gap formalmente y dejar `main` alineado 1:1 con `oliva-upstream/main` como base limpia antes de crear el repo remoto propio de finanzas-ong. Este mecanismo (`git fetch oliva-upstream && git merge oliva-upstream/main`) queda como el procedimiento estándar para futuros upgrades de Oliva, reemplazando el port a mano. Se documentó también del lado de Oliva (`/home/marcos/app`) para que quede registrado como procedimiento recomendado para cualquier fork.

---

## [META] feat: flag -f en `claude reiniciar` para saltar estimación de contexto
**Hash:** `3b5780b`
**Rama:** `main`
**Fecha:** 2026-06-17

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `claude-commands.md` | modificado | agrega flag `-f` a `claude reiniciar [x=60] [-f]`: salta el paso de estimar % de contexto y va directo a los pasos de guardado (commit pendiente, agent-commits.md, memoria) |

### Justificación
El usuario pidió evitar que el agente calcule/declare un porcentaje de contexto cuando ya sabe que quiere guardar el estado — la estimación no es precisa y agrega un paso innecesario cuando la intención ya es clara. El mismo cambio se replicó en `claude-commands.md` de Oliva (`/home/marcos/app`) para mantener el protocolo consistente entre ambos repos.

---

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
**Hash:** `4042837`
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

## [DEPLOY 2] Merge oliva-upstream/main — fix entrypoint prod + scheduler + JWT keys
**Hash:** `9b4233d`
**Rama:** `main`
**Fecha:** 2026-06-26

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `backend/Dockerfile` | modificado | CMD de prod sin wrapper `sh -c` → `$1=frankenphp`, el bloque de setup del entrypoint (JWT + wait-db + migrate) ahora corre en prod |
| `docker-compose.prod.yml` | modificado | `scheduler`: agrega `command: supercronic` (en prod heredaba el CMD del backend y corría frankenphp en vez del cron). `backend`: bind-mount `./secrets/jwt:/app/config/jwt` para persistir las claves JWT en el host |
| `.gitignore` | modificado | Ignora `secrets/` |
| `CLAUDE.md` | modificado | Runbook actualizado: migraciones automáticas, paso de backup de claves JWT |

### Justificación
Bug root cause: el CMD de prod era `sh -c "sleep 3 && frankenphp run ..."`, así que el entrypoint recibía `$1=sh` y su guard `[ "$1" = 'frankenphp' ]` nunca matcheaba. El bloque de setup (generación de claves JWT, wait-for-db, migraciones) se salteaba silenciosamente en producción desde el día uno — solo corría en dev, donde el stage redefine el CMD como `frankenphp` directamente. Se detectó al hacer el primer login real: `JWTEncodeFailureException` porque no había `private.pem`. El sleep era un parche de race de DB que el propio wait-for-db (60 reintentos) cubre mejor. Las claves JWT se persisten en `./secrets/jwt` (bind-mount al host, gitignored) en vez de regenerarse en cada boot: tokens estables, sin deslogueos por recreate de container.

## [DEPLOY 1] feat: comando app:member:create para crear socios desde consola
**Hash:** `569feac`
**Rama:** `main`
**Fecha:** 2026-06-26

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| `backend/src/Command/CreateMemberCommand.php` | nuevo | Comando Symfony `app:member:create <name> <email> <password> [--admin]` |

### Justificación
No hay sistema de registro en la app (fuera de scope del MVP). Para crear el primer usuario admin en prod sin hacer SQL a mano ni depender de fixtures de dev, se crea un comando de consola que usa `UserPasswordHasherInterface` de Symfony (no `password_hash` manual) y valida que el email no exista. Con `--admin` asigna `ROLE_ADMIN`; sin el flag solo tiene `ROLE_MEMBER` (que la entidad agrega siempre en `getRoles()`).

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
