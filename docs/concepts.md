# Conceptos técnicos

Explicaciones teóricas de las tecnologías que forman parte del framework Oliva.
Este archivo es acumulativo: nunca se borra contenido, solo se agrega — ver el
protocolo de explicaciones conceptuales en `CLAUDE.md`.

---

## pgvector

### Qué es

`pgvector` es una extensión de PostgreSQL que agrega un tipo de dato nativo llamado `vector` y operadores para hacer **búsqueda por similitud** entre vectores. En términos simples: te permite guardar y comparar listas de números flotantes directamente en tu base de datos Postgres existente, sin necesidad de una base de datos vectorial separada (Pinecone, Weaviate, Qdrant, etc.).

### Para qué sirve

Cuando un modelo de IA procesa un texto (un documento, un mensaje, una descripción de producto, etc.), puede generar un **embedding**: una lista de números flotantes que representa el *significado semántico* de ese texto. Dos contenidos sobre el mismo tema tienen embeddings parecidos (vectorialmente cercanos); dos contenidos sin relación tienen embeddings lejanos.

`pgvector` permite hacer consultas como:
> *"Dame los 5 registros más similares semánticamente a esta query del usuario"*

sin mover los datos fuera de Postgres. Es la base para features de **búsqueda semántica** o **RAG** (retrieval-augmented generation) en cualquier dominio.

### Cómo funciona por dentro

Un embedding es un punto en un espacio de alta dimensión (por ejemplo, 1536 dimensiones si usás `text-embedding-3-small` de OpenAI, o 1024/1536 con modelos de Voyage AI). La "distancia" entre dos puntos mide qué tan parecidos son semánticamente.

`pgvector` soporta tres métricas de distancia:

| Operador | Métrica | Cuándo usarla |
|---|---|---|
| `<->` | L2 (distancia euclidiana) | cuando la magnitud importa |
| `<=>` | Cosine similarity | **la más común para texto** — mide ángulo, ignora magnitud |
| `<#>` | Inner product | embeddings normalizados |

Para texto casi siempre se usa **cosine similarity** (`<=>`).

### Índices: HNSW vs IVFFlat

Sin índice, una búsqueda de similitud es un *full scan* (compara el vector query contra todos los vectores de la tabla). Escala mal. `pgvector` ofrece dos índices aproximados (ANN - Approximate Nearest Neighbor):

**IVFFlat** (Inverted File with Flat quantization):
- Divide los vectores en `lists` clusters (centroides).
- Para buscar, primero encuentra los clusters más cercanos y solo escanea esos.
- Más rápido de construir, menos memoria.
- Necesita datos cargados *antes* de crear el índice para que los clusters sean buenos.
- Parámetro clave: `lists` (típicamente `sqrt(n_rows)`).

**HNSW** (Hierarchical Navigable Small World):
- Construye un grafo de navegación multinivel.
- Más lento de construir y más memoria, pero **mayor recall** (encuentra los vecinos correctos más seguido) y mejor latencia de query.
- Se puede construir antes de tener datos (crece dinámicamente).
- **Recomendado para producción** salvo que el índice sea muy grande.

### Cómo se usa en Symfony/Doctrine

`pgvector` no tiene soporte nativo en Doctrine. Se necesita declarar un tipo custom que mapea el tipo SQL `vector(n)` a un array PHP de floats. El flujo es:

```
PHP array de floats → Doctrine Type custom → SQL vector(n) en Postgres
```

En una entidad se declara así:

```php
#[ORM\Column(type: 'vector', length: 1536)]
private array $embedding = [];
```

Y en la migración se genera:

```sql
ALTER TABLE mi_entidad ADD COLUMN embedding vector(1536);
CREATE INDEX ON mi_entidad USING hnsw (embedding vector_cosine_ops);
```

Para queries de similitud se usa SQL nativo o DQL con funciones custom:

```php
$em->createNativeQuery(
    'SELECT * FROM mi_entidad ORDER BY embedding <=> :query_vec LIMIT 5',
    $rsm
)->setParameter('query_vec', $queryEmbedding);
```

### Por qué en Postgres y no en una DB vectorial dedicada

Para la escala de la mayoría de las apps construidas sobre Oliva (miles/decenas de miles de registros), `pgvector` es más que suficiente y tiene ventajas reales:

- **Un solo servicio menos** en Docker Compose (la imagen `pgvector/pgvector` es un Postgres normal con la extensión compilada; si no la usás, no cambia nada).
- **Transacciones** — el embedding y los metadatos del registro se guardan atómicamente.
- **SQL estándar** — podés filtrar por cualquier columna *y* ordenar por similitud en una sola query.
- **Backup unificado** — un `pg_dump` y tenés todo.

Una DB vectorial dedicada tiene sentido a partir de decenas de millones de vectores o cuando necesitás features muy específicas (multi-tenancy vectorial, filtros complejos a escala masiva).

---

## Deploy a producción (Fase 5)

### Qué es y qué no es

"Deploy" en este proyecto significa: correr exactamente los mismos contenedores que en dev, pero en un servidor remoto, con imágenes compiladas, secretos reales, HTTPS automático y reinicio ante caídas. No hay ninguna plataforma PaaS, no hay Kubernetes, no hay CI/CD automático — es Docker Compose sobre un VPS.

---

### Las tres capas del stack en producción

```
Internet
    │ HTTPS :443 / HTTP :80
    ▼
[frontend — Caddy]          termina TLS, sirve HTML/JS/CSS estáticos
    │ reverse_proxy HTTP (red interna Docker)
    ├──► /api/*              → backend:80
    ├──► /.well-known/mercure* → backend:80
    └──► /health             → backend:80
         │
         ▼
[backend — FrankenPHP/Caddy]  PHP en worker mode, hub Mercure incorporado
    │ Doctrine ORM
    ▼
[database — Postgres]

[rabbitmq]  broker interno — nunca expuesto al exterior
[scheduler] supercronic — corre tareas cron dentro de la red interna
```

Lo que resuelve HTTPS: **solo el frontend Caddy**. El backend y el resto hablan HTTP entre sí por la red interna de Docker, que es privada. Esto se llama TLS termination: un solo punto habla con el exterior en HTTPS; el resto del sistema queda aislado.

---

### Cómo funciona el HTTPS automático (Let's Encrypt + Caddy)

Caddy es capaz de obtener y renovar certificados TLS de Let's Encrypt sin ninguna configuración manual. El único requisito es que el `SERVER_NAME` del Caddyfile sea un dominio real apuntado a la IP del servidor.

El Caddyfile del frontend usa:
```
{$SERVER_NAME:localhost} {
    ...
}
```

Cuando `SERVER_NAME=tudominio.com`, Caddy detecta que no es `localhost`, hace el challenge ACME con Let's Encrypt, descarga el certificado y lo renueva automáticamente cada 60 días. El certificado se persiste en un volumen de Docker para sobrevivir reinicios.

Para que esto funcione el servidor necesita:
- Puerto 80 abierto (Let's Encrypt hace el challenge HTTP-01 por ahí).
- Puerto 443 abierto (para servir HTTPS).
- DNS del dominio apuntando a la IP del servidor (registro A).

---

### Qué hay que hacer — paso a paso

#### 1. Aprovisionar el VPS

Cualquier provider sirve (Hetzner, DigitalOcean, Vultr, etc.). Lo mínimo para este proyecto: **2 GB RAM, 1 vCPU, 20 GB disco**. Ubuntu 22.04 LTS.

```bash
# En el VPS — instalar Docker
curl -fsSL https://get.docker.com | sh
usermod -aG docker $USER   # para no usar sudo
# Docker Compose viene incluido como plugin: docker compose (sin guión)
```

Abrir firewall: solo puertos **22** (SSH), **80** (HTTP / ACME challenge), **443** (HTTPS). Todo lo demás cerrado — RabbitMQ, Postgres, etc. quedan solo en la red interna de Docker.

#### 2. Registrar dominio y apuntar DNS

Registro A: `tudominio.com → IP-del-VPS`. Propagación DNS puede tardar minutos a horas. Verificar con `dig tudominio.com +short`.

#### 3. Generar las claves JWT

Las claves RSA para el JWT se generan **una sola vez** y se guardan en el VPS. No van en git.

```bash
# Dentro del VPS, con la imagen ya construida, o localmente si tenés el backend corriendo
docker compose exec backend bin/console lexik:jwt:generate-keypair
# Genera backend/config/jwt/private.pem y public.pem
```

Estos archivos hay que copiarlos al VPS y montarlos como volumen, **o** regenerarlos directamente en el VPS después del primer `docker compose build`.

#### 4. Completar `.env.prod`

Actualmente el archivo dice `#TODO`. Hay que completarlo con todos los valores reales. Es el único archivo con secretos y **nunca va a git** (ya está en `.gitignore`).

Variables críticas a definir:

| Variable | Qué es | Ejemplo |
|---|---|---|
| `SERVER_NAME` | Dominio real — Caddy lo usa para TLS | `tudominio.com` |
| `POSTGRES_USER` / `PASSWORD` / `DB` | Credenciales de Postgres en producción | valores fuertes, únicos |
| `DATABASE_URL` / `BACKEND_DATABASE_URL` | DSN de conexión | `postgresql://user:pass@database:5432/db` |
| `JWT_PASSPHRASE` | Frase para cifrar la clave privada RSA | string largo aleatorio |
| `MERCURE_PUBLISHER_JWT_KEY` / `MERCURE_JWT_SECRET` | Claves del hub Mercure | strings largos aleatorios |
| `MERCURE_PUBLIC_URL` | URL pública del hub (la ve el frontend) | `https://tudominio.com/.well-known/mercure` |
| `RABBITMQ_USER` / `PASSWORD` / `DSN` | Credenciales de RabbitMQ | valores fuertes |
| `APP_SECRET` | Secret de Symfony (CSRF, etc.) | `bin/console secrets:generate-keys` o random |

#### 5. Ajustes al `docker-compose.prod.yml`

El archivo actual expone solo el puerto 80. Para HTTPS hay que agregar el 443 y los volúmenes de certificados de Caddy:

```yaml
frontend:
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"   # HTTP/3
    volumes:
      - frontend_caddy_data:/data     # certificados TLS
      - frontend_caddy_config:/config
```

Los volúmenes `frontend_caddy_data` y `frontend_caddy_config` ya están declarados en el archivo — solo falta el mapeo en `ports`.

#### 6. Subir el código al VPS y hacer el build

```bash
# En el VPS
git clone <repo> finanzas-ong
cd finanzas-ong
# Copiar .env, .env.backend, .env.frontend y .env.prod al VPS (scp, rsync, o pegar manualmente)
# Copiar las claves JWT (config/jwt/*.pem) si se generaron en otro lado

# Build de imágenes de producción
docker compose -f docker-compose.yml -f docker-compose.prod.yml build

# Levantar en background
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

#### 7. Correr las migraciones

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
    exec backend bin/console doctrine:migrations:migrate --no-interaction
```

Solo hay que correrlas en el primer deploy y cada vez que haya una migración nueva.

#### 8. Verificar

```bash
curl https://tudominio.com/health          # → {"status":"ok"}
curl https://tudominio.com/.well-known/mercure  # → 400 (esperado, Mercure sin token)
```

Si `/health` responde, el backend está levantado. Si el certificado TLS es válido, Caddy hizo el challenge exitosamente.

---

### Cosas que ya están listas (no hay que tocar)

- **FrankenPHP worker mode en prod**: el `docker-compose.prod.yml` ya usa el target `frankenphp_prod` del Dockerfile, que activa el Caddyfile de worker (`worker.Caddyfile`). El PHP corre en modo worker (proceso permanente, no fork-per-request) — mucho más rápido.
- **Build de Vite baked en la imagen**: el stage `production` del frontend Dockerfile corre `yarn build` y deja los archivos estáticos en `/usr/share/caddy`. Caddy los sirve directamente sin Node en runtime.
- **SPA hash routing no necesita `try_files`**: los hashes (`#/login`, `#/projects`) nunca llegan al servidor — el browser los resuelve localmente. Caddy siempre sirve `index.html` y el JS router hace el resto. El `try_files` comentado en el Caddyfile es solo para SPAs con `pushState` (History API).
- **Scheduler en prod**: el servicio `scheduler` ya tiene su definición en `docker-compose.prod.yml` (target `frankenphp_prod`). Corre `supercronic` con el `crontab` del repo.
- **Compresión y cache headers**: el Caddyfile del frontend ya tiene `encode zstd gzip` y `Cache-Control: immutable` para los assets estáticos.
- **Security headers**: el Caddyfile ya agrega `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y `Content-Security-Policy`.

---

### Cosas que requieren atención antes de ir a prod

| Punto | Estado actual | Qué hacer |
|---|---|---|
| `.env.prod` | `#TODO` | Completar con todos los secretos reales |
| Puertos 443 en `docker-compose.prod.yml` | Solo tiene `:80` | Agregar `443:443` y `443:443/udp` |
| Claves JWT | Se generan en dev (no van a prod) | Regenerar en el VPS |
| Mercure `anonymous` | Activado en el Caddyfile del backend | En esta app está bien para MVP (no se publican eventos sensibles desde el server al frontend en Fase 5); revisar si se agregan features de Mercure |
| Logs de Caddy | Nivel `DEBUG` en el Caddyfile del frontend | Cambiar a `INFO` o `ERROR` en prod para no llenar disco |
| Backup de Postgres | No hay nada implementado | Fuera del MVP, pero planear antes de tener datos reales |

---

### Deuda conocida del framework: worker sin definición de producción

Como documenta `CLAUDE.md`: el servicio `worker` (que corre `messenger:consume`) solo existe en `docker-compose.override.yml` (dev). No hay equivalente en prod. Este proyecto **no usa colas** en las Fases 1–4 (no hay mensajes async), así que no es bloqueante para Fase 5. Pero si en el futuro se agrega procesamiento async (notificaciones, exportes, etc.), hay que agregar un servicio `worker` al `docker-compose.prod.yml` antes de activarlo.

---

## RabbitMQ

### Qué es

RabbitMQ es un **message broker**: un proceso intermediario que recibe mensajes de un productor, los guarda en colas, y los entrega a uno o más consumidores. Es el equivalente a una oficina de correo: el que manda la carta (productor) no necesita saber quién la va a leer ni cuándo; solo la deposita, y el broker se ocupa de la entrega.

El protocolo que usa RabbitMQ se llama **AMQP** (Advanced Message Queuing Protocol).

### Conceptos clave

| Concepto | Qué es |
|---|---|
| **Producer** | Quien publica el mensaje (ej: un comando programado que detectó trabajo nuevo) |
| **Exchange** | Punto de entrada. Recibe el mensaje y decide a qué colas mandarlo según reglas de routing |
| **Queue** | Buffer persistente donde esperan los mensajes hasta ser consumidos |
| **Consumer** | Quien procesa los mensajes (ej: un worker de Symfony Messenger) |
| **Binding** | Regla que conecta un exchange con una queue |
| **Routing key** | Etiqueta en el mensaje que usa el exchange para decidir el destino |

El flujo es siempre: `Producer → Exchange → Queue → Consumer`.

### Por qué no llamar directamente al worker

La alternativa "simple" sería que el productor llame al worker por HTTP o ejecute la lógica directamente. El problema:

- Si el worker se cae, el trabajo se pierde.
- Si llegan muchos eventos a la vez, el productor se bloquea esperando respuesta.
- No podés escalar consumidores sin cambiar el productor.

Con un broker en el medio:
- El mensaje **persiste en la cola** aunque el worker esté caído. Cuando vuelve, lo procesa.
- El productor termina en microsegundos (solo publica). El procesamiento es **asíncrono**.
- Podés levantar varios workers en paralelo sin tocar el productor.

### Cómo se usa en este framework

El patrón general habilitado por Oliva es:

```
[scheduler / comando]
     │ publica un mensaje (Symfony Messenger)
     ▼
[RabbitMQ - exchange]
     │ routing → queue
     ▼
[worker - bin/console messenger:consume]
     │ consume el mensaje
     │ ejecuta la lógica de negocio
     │ persiste en Postgres (eventualmente con embedding via pgvector)
     ▼
[Mercure Hub]
     │ push SSE al frontend
     ▼
[Dashboard en vivo]
```

Symfony usa su componente **Messenger** para abstraer RabbitMQ: en el código PHP se hace `$bus->dispatch(new MiMensaje(...))` y Messenger se encarga del transporte AMQP. No hay llamadas manuales a la librería de RabbitMQ. Ver `docs/examples/` para un esqueleto de mensaje + handler + comando de polling.

### Por qué RabbitMQ y no Kafka o Redis Streams

- **Kafka** es correcto para millones de mensajes/segundo y retención larga. Overkill para la mayoría de apps y agrega complejidad operativa real.
- **Redis Streams** es válido y más liviano, pero RabbitMQ tiene mejor integración nativa con **Symfony Messenger** (transport oficial, reintentos, dead-letter queues out of the box).
- **RabbitMQ** tiene UI de management (`localhost:15672`) que facilita debug en desarrollo.

Si una app no necesita colas, el servicio `rabbitmq` (y `scheduler`/`worker`) se puede quitar de `docker-compose.yml` sin afectar al resto.

---

## AMQP Routing y el bug de `default_publish_routing_key`

### El problema en una línea

Se publicaron mensajes al exchange `ingest`. La queue `ingest` quedó en 0. Los mensajes desaparecieron en silencio.

### Cómo funciona el routing en AMQP (tipo `direct`)

Un exchange `direct` es como un clasificador postal con reglas exactas:

```
Producer publica mensaje con routing_key="ingest"
         │
         ▼
   [Exchange "ingest" — tipo direct]
         │
         │  ¿hay algún binding cuya binding_key == routing_key del mensaje?
         │
    ─────┴─────────────────────────────────────
    SÍ: "ingest" == "ingest"        NO: descarta el mensaje (silencio)
         │
         ▼
   [Queue "ingest"]
```

El **binding** es el cable que conecta exchange con queue. Se define con una `binding_key`. Cuando llega un mensaje, el exchange compara el `routing_key` del mensaje contra la `binding_key` del binding. Si hay match exacto → entrega. Si no hay match → el mensaje se descarta **sin error, sin log, sin excepción**.

Esto es importante: **AMQP no falla ruidosamente cuando un mensaje no rutea. Lo descarta silenciosamente.** El producer recibe un "OK" del broker igualmente.

### Por qué pasa el bug

Una configuración típica (e incompleta) de Symfony Messenger es:

```yaml
transports:
    ingest:
        dsn: '%env(RABBITMQ_DSN)%'
        options:
            exchange:
                name: ingest
                type: direct
            queues:
                ingest:
                    binding_keys: [ingest]
```

Los `binding_keys: [ingest]` crean el binding `exchange → queue` con binding_key `"ingest"`. Eso es correcto.

El problema está en **qué routing_key usa Symfony al publicar**. El comportamiento de Symfony Messenger al publicar un mensaje a AMQP es:

```
routing_key = AmqpRoutingKeyStamp (si está presente en el envelope)
           ?? default_publish_routing_key (si está configurado en el exchange)
           ?? "" (string vacío — el default si no hay nada)
```

Si no hay stamp ni `default_publish_routing_key`, Symfony publica todos los mensajes con `routing_key = ""`.

El exchange recibe los mensajes (por eso `publish_in` sube), busca un binding con `binding_key == ""`, no encuentra ninguno, y los descarta. La queue queda en 0. Sin error.

### El fix

Agregar `default_publish_routing_key` al exchange:

```yaml
exchange:
    name: ingest
    type: direct
    default_publish_routing_key: ingest   # ← esto faltaba
```

Ahora Symfony publica con `routing_key = "ingest"`, que matchea `binding_key = "ingest"`, y el mensaje llega a la queue. Esto ya está aplicado en `backend/config/packages/messenger.yaml`.

### Cómo se detecta

No hay ningún error en el lado del producer. El `$bus->dispatch(...)` retorna éxito. El exchange recibe el mensaje. El único indicio es que la queue permanece vacía aunque se publique.

La forma de diagnosticarlo es comparar las métricas del exchange vs la queue en la management UI de RabbitMQ (`localhost:15672`):

- Exchange `ingest` → `publish_in: N`, `publish_out: 0`
- Queue `ingest` → `deliver_get: 0`

`publish_out: 0` significa que el exchange no envió nada a ninguna queue (ni a otro exchange). Ahí se confirma que el problema es de routing, no de consumo.

### Cuándo `publish_out` es 0 y cuándo no

`publish_out` en un exchange cuenta únicamente los mensajes que el exchange reenvió a **otro exchange** (exchange-to-exchange routing). NO cuenta los mensajes entregados a queues. Así que `publish_out: 0` con `publish_in: N > 0` no necesariamente es un bug — solo significa que no hay exchange-to-exchange routing. El indicador real es `deliver_get` en la queue.

### Regla para recordar

> En un exchange `direct` de RabbitMQ, el mensaje solo llega a la queue si `routing_key del mensaje == binding_key del binding`. Symfony Messenger usa routing key vacío por defecto. Siempre configurar `default_publish_routing_key` cuando el exchange es `direct`.
