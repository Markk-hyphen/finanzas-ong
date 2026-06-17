# Planificación: VPS + infraestructura para apps Oliva

Guía de planificación para pasar de cero a tener un VPS con varias apps
basadas en Oliva deployadas. Pensada para uso propio / staging, con la
primera app real siendo la de la ONG (carga de gastos por 2 socios).

> **Estado:** planificación. Todavía no hay VPS contratado. Este doc es el
> plan a ejecutar cuando se contrate el servicio.

---

## 1. Contexto y decisiones tomadas

- **Uso:** apps propias, testeos, staging. La primera app real (ONG) tiene
  ~2 usuarios cargando gastos esporádicos → carga prácticamente nula.
- **Usuarios finales en Argentina** → la latencia importa. El datacenter
  viable más cercano es **São Paulo** (~30-50 ms desde Buenos Aires).
  Europa (Hetzner) queda descartado para usuarios reales (~250 ms).
- **Crecimiento esperado:** de 1 app a eventualmente 5-20 apps pequeñas.
- **Stack por app (Oliva):** FrankenPHP (backend) + Vite/Caddy (frontend) +
  PostgreSQL/pgvector + RabbitMQ + Mercure, orquestado con Docker Compose.

### Costo estimado por escenario (São Paulo)

| Escenario | RAM recomendada | Proveedor | Precio aprox/mes |
|---|---|---|---|
| 1 app, 2 usuarios esporádicos (arranque) | 1 GB | Vultr / DigitalOcean SP | ~$6 USD |
| 1 app con margen cómodo | 2 GB | Vultr / DigitalOcean SP | ~$12 USD |
| 5 apps + infra compartida, ~10 users/app | 4 GB | Vultr / DigitalOcean SP | ~$24 USD |
| 15-20 apps + infra compartida | 16-32 GB | Vultr SP / Hetzner (si sin users finales) | ~$80-90 USD |

> Precios aproximados, verificar al contratar. Ambos proveedores permiten
> **resize sin perder datos** en minutos → arrancar chico y escalar.

**Recomendación de arranque:** Vultr o DigitalOcean São Paulo, plan **1 GB
(~$6 USD/mes)** para la app de la ONG. Resize a 2 GB si falta memoria.

---

## 2. Clave del diseño: infraestructura compartida

El stack Oliva por defecto levanta **su propio** Postgres + RabbitMQ por app.
Con 1 app está bien, pero no escala: 15 apps × (Postgres + RabbitMQ) =
8-15 GB solo en idle.

**Solución:** una sola instancia de Postgres y una de RabbitMQ compartidas
entre todas las apps. Cada app usa su propia base de datos (Postgres) y su
propio vhost (RabbitMQ), pero el proceso es uno solo.

```
VPS
├── infra/ (docker-compose propio, siempre arriba)
│   ├── reverse-proxy   (Caddy o Traefik, enruta por subdominio + TLS)
│   ├── postgres        (1 instancia, N bases — una por app)
│   └── rabbitmq        (1 instancia, N vhosts — uno por app)
│
├── ong/                (app Oliva: solo backend + frontend + scheduler)
├── app2/
└── appN/
```

Cada app deja de levantar `database` y `rabbitmq` en su compose; en su lugar
apunta vía variables de entorno al Postgres/RabbitMQ compartido.

Esto baja el consumo de ~500 MB-1 GB por app a ~200-300 MB por app.

> **Nota:** Para la **primera** app (ONG) no es obligatorio montar la infra
> compartida desde el día uno — se puede arrancar con el `docker-compose.prod.yml`
> tal cual (Postgres/RabbitMQ propios) y migrar a infra compartida cuando
> aparezca la segunda app. Decidir según ganas de hacer el setup de una.

---

## 3. Fases de ejecución

### Fase 0 — Contratar y preparar el VPS

1. Crear cuenta en Vultr o DigitalOcean.
2. Desplegar un VPS en **São Paulo**, Ubuntu LTS (24.04), plan 1-2 GB.
3. Agregar tu **clave SSH pública** al crear el servidor (no usar password).
4. Anotar la IP pública.

### Fase 1 — Hardening básico del servidor

```bash
# Conectarse
ssh root@IP_DEL_VPS

# Actualizar
apt update && apt upgrade -y

# Usuario no-root con sudo
adduser marcos
usermod -aG sudo marcos
# copiar tu clave SSH al nuevo usuario, luego deshabilitar login root por SSH

# Firewall: solo SSH + HTTP + HTTPS
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw enable

# fail2ban contra fuerza bruta SSH
apt install -y fail2ban
```

Endurecer `/etc/ssh/sshd_config`: `PermitRootLogin no`,
`PasswordAuthentication no`. Reiniciar `sshd`.

### Fase 2 — Docker + Docker Compose

```bash
curl -fsSL https://get.docker.com | sh
usermod -aG docker marcos      # usar docker sin sudo (re-loguear)
docker compose version          # verificar plugin compose v2
```

### Fase 3 — Infraestructura compartida (cuando haya ≥2 apps)

Crear `~/infra/docker-compose.yml` con:

- **reverse-proxy** (recomiendo Caddy por TLS automático con Let's Encrypt).
  Enruta cada subdominio al contenedor frontend de la app correspondiente.
- **postgres** (`pgvector/pgvector:pg15`) con volumen persistente.
- **rabbitmq** (`rabbitmq:3-management`) con volumen persistente.

Todos los contenedores (infra + apps) comparten una red Docker externa, p.ej.
`shared_network`, para que las apps "vean" al Postgres/RabbitMQ por nombre.

```bash
docker network create shared_network
```

Crear base + usuario por app en el Postgres compartido, y vhost en RabbitMQ.

### Fase 4 — DNS y dominios

1. Tener un dominio (o subdominio) apuntando al VPS.
2. Registro **A** `ong.tudominio.com` → IP del VPS.
   Opcional: wildcard `*.tudominio.com` → IP, para no tocar DNS por cada app.
3. Caddy obtiene certificados TLS automáticamente al primer request.

### Fase 5 — Deployar una app Oliva (ej. la ONG)

El repo de la ONG vive en `/home/marcos/finanzas-ong` (local) — Oliva base
está en `/home/marcos/app`.

1. Clonar el repo de la app en el VPS (`~/ong`).
2. Crear `.env`, `.env.backend`, `.env.frontend` de **producción**
   (secretos reales: `APP_SECRET`, `JWT_PASSPHRASE`, passwords, claves
   Mercure con `openssl rand hex 32`). **Nunca** commitear estos.
3. Si se usa infra compartida: editar el compose de la app para **quitar**
   los servicios `database` y `rabbitmq`, conectar a `shared_network`, y
   apuntar `DATABASE_URL` / `RABBITMQ_DSN` al host compartido.
4. Build y levantar:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml build
   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
   ```
5. Correr migraciones:
   ```bash
   docker compose exec backend bin/console doctrine:migrations:migrate --no-interaction
   ```
6. Verificar health: `GET /health` → `{"status":"ok"}` y
   `/.well-known/mercure`.

---

## 4. Deuda conocida a resolver antes de prod (de CLAUDE.md)

- **`worker` no tiene definición de producción.** El `docker-compose.override.yml`
  (dev) define `worker` con `messenger:consume`, pero no hay equivalente en
  `docker-compose.yml` ni en `docker-compose.prod.yml`. Si la app usa
  RabbitMQ/Messenger en serio, **agregar un servicio `worker`** (imagen
  `app-backend-prod:1.0`, comando `messenger:consume`, sin puertos) antes de
  ir a prod. El `scheduler` sí está cubierto en prod.

  > La app de la ONG (carga de gastos simple) probablemente **no** necesita
  > worker async todavía — confirmar si hay colas en uso antes de complicarse.

---

## 5. Backups

Imprescindible aunque sea staging, porque hay datos reales de la ONG.

- **Postgres:** `pg_dump` diario por base, vía el contenedor `scheduler`
  (supercronic + `backend/scheduler/crontab`) o un cron del host. Guardar
  los dumps fuera del VPS (object storage barato de Vultr/DO, o `rsync` a
  otra máquina).
- **Snapshots del VPS:** Vultr/DO ofrecen snapshots automáticos por un
  extra mensual (~20% del precio). Útil pero **no reemplaza** los dumps de
  Postgres.
- Probar la **restauración** al menos una vez — un backup no testeado no es
  un backup.

---

## 6. Checklist de puesta en marcha

- [ ] VPS contratado en São Paulo (Ubuntu LTS, 1-2 GB)
- [ ] SSH con clave, root login deshabilitado, `ufw` + `fail2ban`
- [ ] Docker + Compose v2 instalados
- [ ] Dominio/subdominio apuntando a la IP
- [ ] (si ≥2 apps) `shared_network` + infra compartida arriba
- [ ] App ONG clonada, `.env` de prod con secretos reales
- [ ] Build de prod OK, contenedores `up -d`
- [ ] Migraciones corridas, `/health` y Mercure verificados
- [ ] Backup de Postgres automatizado y restauración probada
- [ ] (si usa Messenger) servicio `worker` de prod definido

---

## 7. Decisiones pendientes (definir al ejecutar)

- ¿Vultr o DigitalOcean? (UX similar; Vultr suele salir un poco más barato).
- ¿Infra compartida desde el día 1 o Postgres/RabbitMQ propios hasta la 2da app?
- ¿Caddy o Traefik como reverse proxy? (Caddy = TLS automático y config simple).
- ¿Dominio propio nuevo o subdominio de uno existente?
- ¿La ONG necesita Messenger/colas, o se puede simplificar el stack?
