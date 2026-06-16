# Oliva

Framework base para construir webapps: backend Symfony (FrankenPHP), frontend Vite, base de datos PostgreSQL (con pgvector), tiempo real vía Mercure, colas vía RabbitMQ + Symfony Messenger, y un contenedor scheduler (cron) para tareas programadas. Todo orquestado con Docker Compose.

Este `CLAUDE.md` es la guía operativa para agentes que trabajen sobre proyectos construidos con Oliva. La documentación humana (stack, getting started) está en `README.md`.

## Comandos principales

### Desarrollo
```bash
docker compose up
```
El `docker-compose.override.yml` se aplica automáticamente: monta el código fuente con hot-reload, expone el backend en `:80` y el frontend en `:3000`.

### Producción
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```
Usa imágenes compiladas (`app-backend-prod:1.0`, `app-frontend-prod:1.0`). El frontend queda expuesto en `:80`.

### Build de producción
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
```

## Estructura
- `backend/` — Symfony con FrankenPHP (Doctrine ORM/Migrations, pgvector, Mercure, Messenger)
- `frontend/` — Vite (Node), incluye demo de Mercure en vivo (`src/js/live.js`, sección "Live" de `index.html`)
- `docs/concepts.md` — glosario técnico acumulativo (pgvector, RabbitMQ, AMQP, etc.)
- `docs/examples/` — esqueletos de referencia (`*.php.example`) no registrados como código de la app
- `.env`, `.env.backend`, `.env.frontend` — variables por entorno
- `.env.prod` — variables de producción

## Servicios incluidos y cómo desactivarlos

Además de `database`, `backend` y `frontend`, `docker-compose.yml` define:

| Servicio | Para qué | Si la app no lo necesita |
|---|---|---|
| `rabbitmq` | Broker para Symfony Messenger (colas `ingest`/`enrich` de ejemplo, comentadas) | Quitar el bloque `rabbitmq` y las dependencias `depends_on: rabbitmq` de `backend`/`scheduler`/`worker` |
| `scheduler` | Ejecuta `supercronic` con `backend/scheduler/crontab` para tareas periódicas | Quitar el bloque `scheduler` (y `backend/scheduler/crontab`) |
| `worker` (en `docker-compose.override.yml`, dev) | Corre `bin/console messenger:consume` | Quitar el bloque `worker` si no hay mensajes async |

La base de datos usa la imagen `pgvector/pgvector:pg${POSTGRES_VERSION}` — es un Postgres normal con la extensión `vector` compilada y una migración que la habilita (`CREATE EXTENSION IF NOT EXISTS vector`). No tiene costo si la app no usa tipos `vector`; no hace falta quitarla.

### Deuda conocida: `worker` no tiene definición de producción

`docker-compose.override.yml` (dev) define `worker` con `bin/console messenger:consume`, pero no hay equivalente en `docker-compose.prod.yml` ni en el `docker-compose.yml` base. Si una app usa RabbitMQ/Messenger en serio, antes de ir a producción hay que agregar un servicio `worker` (imagen `app-backend-prod:1.0`, comando `messenger:consume`, sin exponer puertos). `scheduler` sí está cubierto en prod (usa `app-backend-prod:1.0` / target `frankenphp_prod`, igual que `backend`).

Mercure (`/.well-known/mercure`) está siempre activo vía el hub de Caddy (`backend/frankenphp/Caddyfile`); la demo de canvas en vivo (`backend/public/live.php` + `frontend/src/js/live.js`) muestra que funciona out of the box.

## Variables de entorno

Ver `.env.example` como referencia. Agrupadas por servicio:
- **DB**: `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB`, `DATABASE_URL` / `BACKEND_DATABASE_URL`
- **Mercure**: `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_JWT_SECRET`, `CADDY_MERCURE_URL`, `CADDY_MERCURE_PUBLIC_URL`
- **RabbitMQ**: `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_DSN`
- **JWT**: `JWT_PASSPHRASE`

## Health check y CI

- `GET /health` → `{"status": "ok"}` (`backend/src/Controller/HealthController.php`).
- `.github/workflows/ci.yml`: build de imágenes, levanta los servicios, chequea `/health` y `/.well-known/mercure`, corre migraciones, valida el schema de Doctrine y corre PHPUnit. Job adicional de lint de Dockerfile con hadolint.

## Protocolo de explicaciones conceptuales

Cuando el usuario pide que se le explique un concepto (`"explicame X"`, `"enseñame X"`), el agente debe:
1. Agregar la explicación al **final** de `docs/concepts.md` como una nueva sección `## NombreDelConcepto`.
2. Commitear `docs/concepts.md` junto con el trabajo en curso (o en un commit separado si el usuario lo pide).
3. No repetir explicaciones ya presentes en el archivo — si el concepto ya está, referenciarlo.

`docs/concepts.md` es acumulativo: nunca se borra contenido, solo se agrega.

## Protocolo de commits del agente

Para proyectos donde se quiera dejar un registro detallado de cada cambio del agente (recomendado para trabajo autónomo o de varias sesiones), crear un `agent-commits.md` en la raíz del proyecto y registrar ahí **cada commit que realice el agente**, antes o junto con el commit de git.

### Formato

```markdown
## [PASO X.Y] <título del commit>
**Hash:** `<hash completo>`
**Rama:** `<rama>`
**Fecha:** <fecha>

### Cambios
| Archivo | Tipo | Descripción |
|---|---|---|
| path/archivo | nuevo/modificado/eliminado | qué hace |

### Justificación
<por qué se tomaron estas decisiones, qué problema resuelven, qué alternativas se descartaron>
```

### Reglas
- Registrar **todos** los archivos modificados, no solo los principales.
- La justificación debe responder **por qué**, no solo describir el qué (eso ya lo hace la tabla).
- Si un cambio resuelve un problema encontrado durante la ejecución (no previsto en el plan), documentarlo explícitamente.
- El commit de git y la entrada en `agent-commits.md` van en el **mismo commit**.

## Convención de idioma en el código

**Todo el código fuente se escribe en inglés.** Esto incluye:
- PHP: clases, propiedades, métodos, variables, constantes, parámetros
- JavaScript: variables, funciones, clases, nombres de módulos
- Base de datos: nombres de tablas y columnas (generados por Doctrine desde el PHP)
- Configuración (YAML, JSON, etc.): claves y valores que sean identificadores de código
- Comentarios en el código

Los textos orientados al usuario (respuestas API, mensajes de error hacia el frontend, documentación en Markdown) pueden estar en español.

## Chequeos de sanidad (inicio y cierre de sesión)

Correr al **inicio** de cada sesión antes de tocar código:

```bash
git branch --show-current          # confirmar la rama de trabajo esperada
git status -s                      # debe estar limpio (o saber qué hay pendiente)
git log --oneline -3               # orientarse en dónde quedó la sesión anterior
docker compose ps                  # todos los servicios healthy
docker compose exec backend bin/console doctrine:migrations:status  # sin migraciones pendientes
```

Verificar al **cierre** de sesión antes de cortar:

```bash
git status -s                      # sin archivos sin commitear (salvo intencional)
git log --oneline -3               # último commit con mensaje claro
```

Si el proyecto usa `agent-commits.md`, verificar que el último commit tenga su entrada correspondiente.
