# Resumen: qué cambió para el deploy de staging (y por qué)

> Documento de puesta al día. Cubre los cambios de los últimos días (≈ 26–29 jun 2026)
> que habilitan **levantar un stack de staging como app aparte sobre la infra
> compartida del VPS**, sin pisar producción. Acá está lo que importa con su *por qué*;
> el detalle commit-por-commit está en `agent-commits.md` y el `git log`.

---

## El objetivo de fondo

Tener **staging y prod conviviendo en el mismo VPS**, compartiendo el Postgres y el
reverse-proxy de `~/infra`, pero **totalmente aislados** entre sí:

- Project name propio (`-p ong` para prod, `-p ong-staging` para staging).
- DB propia desechable para staging (`ong_staging_db`).
- Dominio propio para staging (`staging.ong.159.223.133.253.nip.io`).

Staging es "una app más" en la infra compartida, no un modo especial de la app de prod.

Esto destapó **dos bugs sutiles** (env y red) que se introdujeron mientras el trabajo
iba medio en piloto automático, y que por suerte se cazaron **antes** de deployar. Son
los dos platos fuertes de este resumen.

---

## 1. La saga del `APP_ENV` — por qué tomó 3 commits

**El feature que lo destapó:** el seeding de staging usa `doctrine:fixtures:load`
(Foundry + Doctrine Fixtures), que **solo existen si el entorno es `staging`** (están
registrados en `bundles.php` para `dev`/`test`/`staging`, no para `production`).

**El síntoma:** `fixtures:load` fallaba con *"command not defined"*. La causa real era
que, en runtime, `APP_ENV` quedaba en `production` en vez de `staging` — entonces los
bundles de fixtures no se cargaban.

**Por qué costó:** el valor de `APP_ENV` venía pisado desde **tres capas distintas**, y
se fue tapando una por una sin ver la de más arriba:

| Commit | Capa que se arregló | Qué se hizo |
|---|---|---|
| `66f7473` | `bundles.php` | Habilitar Foundry + Fixtures en el entorno `staging` |
| `6f40d0b` | Imagen Docker | El stage `frankenphp_staging` heredaba el `.env.local.php` **compilado de prod** (con `APP_ENV=prod`). Se borra y se hornea `ENV APP_ENV=staging` en la imagen |
| `9523ca8` | Docker Compose | **La capa que ganaba:** `docker-compose.prod.yml` setea `environment: APP_ENV=production`, que en Compose tiene **más precedencia que `env_file` y que el `ENV` de la imagen**. El overlay de staging solo pisaba el `env_file`, no el `environment`. Fix: override `environment: APP_ENV=staging` en backend y scheduler |

**La lección que queda (gotcha permanente):** la precedencia de env en Compose es
`environment:` **>** `env_file:` **>** `ENV` de la imagen. Cuando una variable no toma
el valor esperado, **primero diagnosticar, después editar**:

```bash
docker exec <container> printenv APP_ENV    # el valor EFECTIVO
docker compose ... config                   # de qué capa sale
```

Esto evita perseguir el bug por la capa equivocada (que es exactamente lo que pasó).

---

## 2. La colisión de red en `proxy_net` — el bug más sutil

**El bug (commit que lo arregla: `3bb2b12`):** el overlay `docker-compose.shared-infra.yml`
conectaba el `frontend` a la red externa `proxy_net` con un **alias fijo y hardcodeado:
`ong-frontend`**.

`proxy_net` es una red **externa y compartida** entre *todos* los stacks `-p` del VPS.
Con el alias fijo:

- prod (`-p ong`) registraba el alias `ong-frontend`
- staging (`-p ong-staging`) registraba **el mismo** alias `ong-frontend`

→ el reverse-proxy veía dos contenedores con el mismo nombre de red y hacía
**round-robin entre ambos** → el dominio de producción podía terminar ruteando al
**frontend de staging**, que pega contra la **DB desechable**. Un desastre silencioso.

**El fix (una línea):** derivar el alias del project name, igual que ya estaba derivado
el nombre de la red privada:

```yaml
# docker-compose.shared-infra.yml
aliases:
  - ${COMPOSE_PROJECT_NAME:-app}-frontend
```

Resultado:
- `-p ong` → `ong-frontend` (**idéntico a antes** → prod no se toca en runtime, el
  Caddyfile de prod sigue válido).
- `-p ong-staging` → `ong-staging-frontend` (alias único, sin colisión).

**Bonus:** elimina el viejo paso manual del runbook *"reemplazar `CHANGE-THIS-ALIAS-frontend`
por app"*. Ahora el `-p <app>` obligatorio resuelve el alias solo.

> Este es el patrón que te quedó dando vueltas: **derivar identificadores de recursos
> compartidos del `${COMPOSE_PROJECT_NAME}`** para aislar stacks. Vale la pena discutir
> dónde más aplica (otras apps del VPS, generalizarlo en el framework Oliva).

---

## 3. El feature que acompañó: login por username

Commits `c52e11b` + `66f7473`. No es del deploy en sí, pero viajó en el mismo tren:

- Login acepta **email o username** (el frontend ahora postea `login`, no `email`; el
  input se relabeló *"Email o usuario"*).
- El username se **deriva del email**: `federico.perez@gmail.com` → `federico.perez`.
- Migración `Version20260627120000` (username en minúsculas + nombre de índice que
  Doctrine espera, `UNIQ_45A0D2FFF85E0677`, para mantener `schema:validate` en verde).
- Factories Foundry + `AppFixtures`: **15 socios** (password `asdasd`) + **5 proyectos**
  para poblar staging.

---

## Estado: qué está hecho y qué falta

**Hecho y pusheado** (todo verificado en local):
- ✅ Los dos fixes (env + red) y el login por username.
- ✅ El `~/infra/caddy/Caddyfile` **local** ya tiene el vhost de staging
  (`staging.ong... → ong-staging-frontend:80`).

**Falta ejecutar en el VPS** (el código ya está, solo correrlo):
1. **scp** del `~/infra/caddy/Caddyfile` al VPS (infra NO va por git pull) + `caddy reload`.
2. `cd ~/ong && ./scripts/deploy-staging.sh` (pull + down + build + up + migraciones + fixtures).
3. Verificar: `APP_ENV=staging`, migraciones sin pendientes, 15 members, dominio de staging
   rutea a staging y el de prod **sigue** en prod.

---

## Deudas conocidas (no bloquean el deploy)

- **`when@prod` no aplica en prod:** el proyecto usa nombres de entorno *largos*
  (`production`/`development`), pero Symfony solo activa `when@prod`/`when@dev` con los
  *cortos* (`prod`/`dev`). Consecuencia: en prod **no** se activan los optimizadores de
  `doctrine.yaml` (proxies precompilados, cache pools). No crashea, solo corre menos
  optimizado. Tocarlo impacta prod en runtime → tratar aparte, con su propio deploy.
- **JWT sin passphrase en staging:** decisión consciente (sin data sensible aún).
  Ponerle passphrase + backupear `{private,public}.pem` antes de pasar a data real.
- **PAT de GitHub en texto plano** en `.git/config` del remote (local, no se commitea).
  Conviene rotarlo + usar credential helper.
