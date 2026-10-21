# Finanzas ONG

Sistema de autogestión financiera **socios-para-socios**, construido sobre el framework [Oliva](https://github.com/Markk-hyphen/oliva).

![PHP](https://img.shields.io/badge/PHP-8.3-black?style=flat-square&logo=php)
![Symfony](https://img.shields.io/badge/Backend-Symfony%207.4-000000?style=flat-square&logo=symfony)
![Vite](https://img.shields.io/badge/Frontend-Vite%206-646CFF?style=flat-square&logo=vite&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/Database-PostgreSQL-336791?style=flat-square&logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Infrastructure-Docker%20Compose-2496ED?style=flat-square&logo=docker&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

---

## Qué es esto

N socios construyen N proyectos (ej.: una sala de cultivo). Cada socio registra **gastos** (frontea plata para comprar algo) y **pagos** (transfiere plata a otro socio para saldar lo que debe). El sistema lleva, por proyecto, el **balance neto de cada socio**: cuánto aportó, cuánto debe, cuánto se le debe.

- Usuarios: un puñado de socios, uso desde el celular (web app mobile-first responsive).
- Prioridad: **correctitud financiera y trazabilidad** por encima de features.
- Hosting: VPS con Docker Compose de producción + dominio + HTTPS.

Ver `MANIFIESTO.md` para la especificación técnica completa (modelo de datos, reglas de dominio, API, fases de construcción).

---

## Stack

| Capa | Tecnología |
|---|---|
| Backend | Symfony 7.4 (PHP 8.3+) · FrankenPHP · Caddy · JWT Auth |
| Frontend | Vite 6 · Vanilla JS · Bootstrap 5 · SweetAlert2 |
| Base de datos | PostgreSQL + pgvector |
| Infraestructura | Docker Compose · multi-stage builds |

---

## Levantar el proyecto

### Prerequisitos

- [Docker](https://docs.docker.com/get-docker/) con Compose v2.10+

### Desarrollo

```bash
git clone <repo>
cd finanzas-ong
cp .env.example .env
# completar .env, .env.backend, .env.frontend
docker compose up
```

| Servicio | URL |
|---|---|
| Frontend | http://localhost:3000 |
| Backend API | http://localhost:80/api |

### Producción

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

The frontend is served by Caddy on **http://localhost**. The backend, database, RabbitMQ and scheduler are internal — never directly reachable from outside.

> **Known gap:** there's no production `worker` service yet. If your app uses Messenger queues for real, add one to `docker-compose.prod.yml` (image `${COMPOSE_PROJECT_NAME}-backend:prod`, command `messenger:consume`, no exposed ports) before going live — `scheduler` is already covered in prod.

Para deploy sobre infra compartida (VPS con Postgres/reverse-proxy compartidos), ver `CLAUDE.md` → sección "Infra compartida".

---

## Estructura

```
finanzas-ong/
├── backend/                # Symfony 7.4 (FrankenPHP + Caddy)
│   ├── src/
│   │   ├── Domain/         # lógica financiera pura (sin dependencias de Symfony)
│   │   ├── Entity/
│   │   └── Controller/
│   ├── migrations/
│   └── frankenphp/
├── frontend/               # Vite app (mobile-first)
│   └── src/
├── docs/
│   └── concepts.md
├── MANIFIESTO.md           # especificación técnica completa
├── docker-compose.yml
├── docker-compose.override.yml
└── docker-compose.prod.yml
```

---

## Principios de diseño

- **Plata en `NUMERIC`, jamás `float`.** Montos como `numeric(18,2)` en DB y BCMath en PHP.
- **Balances derivados, nunca almacenados.** El balance se calcula como un fold sobre la tabla de movimientos — nunca hay un campo "balance" en la base.
- **Ledger append-only.** Nunca `UPDATE` ni `DELETE` sobre un movimiento. Corregir = registrar una anulación + insertar la corrección.
- **Confirmación bilateral de pagos.** Un pago nace `pendiente` y no impacta el balance hasta que el receptor lo confirma.

---

## License

MIT
