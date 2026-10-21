# Fase 3 — Entrega de notificaciones por mail (obligatorio) y Telegram (opt-in)

Upgrade UX + seguridad de código, Fase 3. Enmienda **E3** al `MANIFIESTO.md`.
Se apoya en la Fase 2 (`docs/fase2-eventos-notificaciones.md`).

## 1. Problema

La Fase 2 crea una notificación in-app en los casos en que no enterarse le
puede costar plata a un socio (pago optimistic, pago auto_timeout, autoconfirmación,
retiro, rechazo). Pero solo la ve quien abre la app: si el receptor no entra
antes de que venza el plazo, el aviso no sirvió. Hace falta llevarlo afuera de
la app.

## 2. Diseño

### 2.1 Canales, no eventos nuevos

La Fase 3 **no decide a quién avisar**: eso ya lo resuelve `NotificationTargets`.
Cada fila de `notification` se entrega además por:

| Canal | Quién lo recibe | Opt-out |
|---|---|---|
| Mail | todo socio activo (`Member.email`, ya obligatorio y único) | **No.** Es el canal garantizado. |
| Telegram | el socio que vinculó su cuenta | Sí: vincular/desvincular desde "Mi cuenta" |

Los dos son **capacidad de instancia**, igual que `auto_timeout`:
`GET /api/instance/capabilities` devuelve `email` (hay `MAILER_DSN` distinto de
`null://`) y `telegram` (hay `TELEGRAM_DSN` y `TELEGRAM_BOT_USERNAME`). Si la
instancia no ofrece el canal, no se crean entregas para él.

> **Futuro (hito "app vendible"):** opt-out del mail por socio. Para un grupo de
> socios que se conocen no aporta nada; tendría sentido si la app la usan
> terceros.

### 2.2 Contenido: sin datos financieros

Los mensajes **no incluyen montos ni contrapartes**, porque pasan por
proveedores externos (servidor de mail, Telegram). Solo dicen qué tipo de
evento ocurrió, en qué proyecto y dan el link al dashboard:

> Tenés un pago pendiente en *Proyecto X*: si no lo rechazás antes del plazo,
> se confirma solo. Revisalo en https://…/#/project/12

Texto plano armado en PHP (`NotificationMessage`), sin Twig. El link sale de
`APP_PUBLIC_URL` (URL del frontend).

### 2.3 Entrega: bandeja de salida durable, fuera de la transacción

Mandar un mail dentro de la transacción del pago reproduciría el bug que la
revisión post-Fase 2 arregló con Mercure: un proveedor caído tiraría abajo el
pago y retendría el lock del proyecto.

- **`notification_delivery`**: una fila por notificación y por canal, creada
  por `ActivityRecorder` *dentro* de la misma transacción que el evento (si el
  pago se revierte, no queda nada por mandar). Columnas: `channel`, `status`,
  `attempts`, `next_attempt_at`, `claimed_at`, `last_error`, `sent_at`.
- **Estados:** `pending` → `sending` (reclamada) → `sent` | `pending` (reintento
  programado) | `failed` (se agotaron los intentos) | `skipped` (no aplica más,
  p. ej. el socio se desactivó o desvinculó Telegram antes del envío).
- **Camino rápido:** al terminar el request o el comando
  (`kernel.terminate`/`console.terminate`, como Mercure) se intenta enviar lo
  que encoló ese request. El aviso llega en segundos.
- **Barrido de reintentos:** `app:notifications:deliver` corre cada minuto en el
  crontab. Toma las entregas `pending` vencidas y reintenta con backoff
  exponencial (1, 2, 4, 8 min), hasta **5 intentos**; después queda `failed`.
  Sin scheduler queda solo el camino rápido, y lo que falla se ve como `failed`.
  *Por qué:* si el proveedor falla justo en el primer intento, sin barrido el
  mail no sale nunca. En `auto_timeout`, el receptor podría perder el plazo para
  objetar, que es justo lo que este aviso quiere evitar.
- **Sin duplicados:** cada envío primero *reclama* la fila con un `UPDATE`
  condicional (`WHERE status = 'pending' AND next_attempt_at <= now`). Si el
  camino rápido y el barrido compiten, gana uno solo. Una fila que quedó en
  `sending` más de 10 min (proceso muerto a mitad del envío) se vuelve a tomar.
- **Descartado: Messenger/RabbitMQ.** Necesita un worker que no tiene definición
  de producción (deuda de Oliva) y suma un servicio para resolver algo que la
  tabla y el cron ya cubren.

### 2.4 Telegram: vinculación sin webhook

Envío con `symfony/notifier` y el bridge `symfony/telegram-notifier`. Datos en
`members`: `telegram_chat_id`, `telegram_link_token_hash` y
`telegram_link_expires_at`.

1. "Mi cuenta" → **Vincular Telegram** → `POST /api/me/telegram/link` genera un
   token de un solo uso (32 bytes aleatorios; en la DB se guarda solo su
   SHA-256; vence en 15 min) y devuelve el deep link `https://t.me/<bot>?start=<token>`.
2. El socio abre el link y aprieta *Start*. Telegram le manda al bot `/start <token>`.
3. El frontend llama a `POST /api/me/telegram/check`. El backend consulta
   `getUpdates` y procesa **todos** los `/start` pendientes (también los de
   otros socios), los cruza con los hashes vigentes y guarda el `chat_id`.
   Después confirma el offset para no volver a leerlos.

No necesita URL pública, así que funciona igual en dev. Se descartó el webhook:
Telegram no permite combinarlo con `getUpdates`, y para vincular una vez no
hace falta.

**Desvincular:** `DELETE /api/me/telegram`, o automático si Telegram responde
403 al enviar (el socio bloqueó el bot); en ese caso la entrega queda `skipped`.

### 2.5 API y frontend

| Endpoint | Qué hace |
|---|---|
| `GET /api/instance/capabilities` | suma `email` y `telegram` |
| `GET /api/me/notification-channels` | mail de destino, si Telegram está vinculado y si la instancia ofrece cada canal |
| `POST /api/me/telegram/link` | genera el token y devuelve el deep link |
| `POST /api/me/telegram/check` | procesa los `/start` pendientes; devuelve si este socio quedó vinculado |
| `DELETE /api/me/telegram` | desvincula |

Vista nueva **"Mi cuenta"** (`#/account`, con acceso desde la barra superior): muestra
el mail (sin opción de apagarlo), el estado de Telegram con
vincular/desvincular y una leyenda cuando la instancia no ofrece un canal.

### 2.6 Entornos

- **Dev:** contenedor **Mailpit** en `docker-compose.override.yml` (UI en
  `http://localhost:8025`). `MAILER_DSN=smtp://mailpit:1025`. Nada sale a
  internet.
- **Staging:** Mailpit o `null://null`. Los fixtures tienen mails falsos, así
  que un SMTP real rebotaría.
- **Prod:** SMTP real en `MAILER_DSN`. Para Telegram: crear el bot con
  @BotFather y setear `TELEGRAM_DSN=telegram://<token>@default` +
  `TELEGRAM_BOT_USERNAME`.
- El scheduler no monta `vendor`: hay que rebuildear su imagen al sumar los
  paquetes nuevos.

## 3. Verificado en dev

- **Mail de punta a punta:** pago `auto_timeout` real (proyecto 44, socios 95 → 94) y
  retiro posterior. Llegaron los dos mails a Mailpit, `sent` al primer intento,
  sin montos y con el link al proyecto.
- **Proveedor caído:** con Mailpit apagado, el pago respondió 201 en 82 ms y la entrega
  quedó `pending` con el error. Al volver Mailpit, `app:notifications:deliver` la
  mandó en el segundo intento.
- **Telegram con la API simulada** (no hay bot en dev): el `/start` con token
  válido vincula el `chat_id`. El token es de un solo uso. Un token falso recibe
  la respuesta "link vencido". Los chats de grupo se ignoran. Se confirma el
  offset. La entrega con 200 queda `sent`; con 403 queda `skipped` y desvincula
  al socio.
- **"Mi cuenta" en Chrome headless** (viewport de celular): estado real de dev
  (mail sí, Telegram no) y flujo simulado vincular → link → autodetección →
  desvincular, sin errores de JS.
- **Falta probar:** Telegram con un bot real. Requiere crear el bot con
  @BotFather y setear `TELEGRAM_DSN` + `TELEGRAM_BOT_USERNAME`.

## 4. Pendientes encontrados durante la implementación

- **Mercure en modo moderno.** La imagen base usa el tag flotante
  `dunglas/frankenphp:1-php8.3`; al rebuildear entró FrankenPHP 1.13.1, cuyo Mercure
  rechaza `publisher_jwt`/`subscriber_jwt` fuera del modo de compatibilidad.
  Se activó `protocol_version_compatibility 8`, que mantiene el comportamiento
  anterior. Migrar al modo moderno requiere bloques `issuer` en el Caddyfile y
  que MercureBundle firme los tokens con el claim `iss`.
- **Tag flotante de la imagen base** (deuda de Oliva): conviene fijar una
  versión exacta de FrankenPHP para que un rebuild no cambie el runtime sin aviso.
- **Oliva tiene el mismo Caddyfile** (sin `subscriber_jwt` y sin el modo de compatibilidad).

## 5. Fuera de alcance

- Opt-out del mail (ver §2.1, hito futuro).
- Elegir por socio *qué* eventos recibe por cada canal: los 5 eventos van por
  todos los canales.
- Vista del audit log.
