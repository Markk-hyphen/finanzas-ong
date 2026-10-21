# Fase 2 — Eventos de dominio, audit log y notificaciones in-app

> Rama `main` (sin rama propia: cambio aditivo, sin tocar nada de la Fase 1).
> Diseño acordado con Marcos el 2026-10-21, a partir de la propuesta mostrada
> en el chat antes de implementar.

## 1. Problema

El modo `optimistic` (Fase 1) nace confirmado: si el receptor no mira el
dashboard a tiempo, el plazo de objeción (48 h default) vence en silencio y
pierde la chance de rechazar un pago que no le llegó. `auto_timeout` tiene el
mismo punto ciego al revés: si nadie avisa que se autoconfirmó, cualquiera de
los dos puede creer que todavía está a tiempo de objetar.

## 2. Diseño

### 2.1 Vocabulario de eventos (`ActivityEvent`)

Un solo lugar (`App\Domain\Model\ActivityEvent`) define los tipos de evento:
`expense_created`, `expense_undone`, `payment_created`,
`payment_created_optimistic`, `payment_confirmed`, `payment_auto_confirmed`,
`payment_withdrawn`, `payment_rejected`, `payment_self_corrected`,
`movement_cancelled_admin`. Cada escritura al ledger pasa por
`ActivityRecorder::record()` con uno de estos tipos.

### 2.2 Audit log (`audit_log`, todo evento)

Tabla append-only: `project_id, event_type, movement_id, actor_id, payload, created_at`.
Se escribe **siempre**, para los 10 tipos de evento — es el historial completo
del proyecto. No tiene endpoint propio en esta fase (no lo pidió Marcos); queda
como la base para un futuro "ver actividad del proyecto" si hace falta.

### 2.3 Notificaciones in-app (`notification`, solo donde el silencio importa)

`App\Domain\Service\NotificationTargets` (pura, testeada en
`NotificationTargetsTest`) decide a quién le importa cada evento —
deliberadamente angosto, solo 4 de los 10 tipos generan notificación:

| Evento | A quién | Por qué |
|---|---|---|
| `payment_created_optimistic` | receptor | nace confirmado; necesita saber que existe y que puede rechazarlo |
| `payment_auto_confirmed` | pagador + receptor | el plazo venció en silencio; ambos deben saber que ya es definitivo |
| `payment_withdrawn` | receptor | el pagador retiró una parte pendiente que esperaba |
| `payment_rejected` | pagador | el receptor rechazó su parte; hay que rehacer el pago |

Los otros 6 eventos (alta de gasto, confirmación manual del receptor,
autocorrección, anulación de admin) quedan solo en el audit log — son la
propia acción del actor o no tienen una contraparte a la que avisar.

Cada notificación apunta a un `movement_id` y queda con `read_at = null` hasta
que el destinatario la marca vista. La DB es la fuente de verdad.

### 2.4 Mercure (push en vivo, no fuente de verdad)

Al crear una `Notification`, `NotificationPublisher` publica un **update
privado** (`private: true`) al tópico `https://finanzas-ong.app/members/{id}/notifications`
usando el hub "default" ya configurado (`config/packages/mercure.yaml`) — no
se agregó infraestructura nueva. El frontend pide su propio JWT de suscriptor
vía `GET /api/notifications/subscription-token` (scopeado a su tópico, nunca
el secret del hub) y abre un `EventSource`.

**Si Mercure está caído no se pierde nada:** el polling de
`GET /api/notifications` sigue sirviendo los mismos datos desde la DB. El push
es solo para no tener que refrescar a mano.

### 2.5 Endpoints nuevos

| Endpoint | Quién | Qué hace |
|---|---|---|
| `GET /api/notifications` | autenticado | últimas 50 + `unreadCount` |
| `POST /api/notifications/{id}/read` | autenticado (propia) | marca leída |
| `GET /api/notifications/subscription-token` | autenticado | JWT de Mercure scopeado a su tópico |

## 3. Fuera de alcance (a propósito)

Mail obligatorio y Telegram opt-in quedan para la Fase 3. Con `ActivityEvent` +
`ActivityRecorder` ya armados, Fase 3 es agregar listeners nuevos sobre los
mismos eventos — no rediseñar nada.

## 4. Verificado en dev

Pago `optimistic` real (proyecto `Modes28077`, id 36) → notificación creada
para el receptor (`GET /api/notifications` la devuelve con `unreadCount: 1`),
`POST .../read` la marca leída, `GET /api/notifications/subscription-token`
emite un JWT válido para el tópico propio, y la suscripción a ese tópico en el
hub Mercure responde `200 text/event-stream` (confirma que el hub acepta el
JWT privado). La entrega en vivo por SSE no se pudo confirmar con `curl` en
este entorno — ni siquiera el demo público preexistente (`live.js`) la recibe
por ese medio aquí — así que no es indicio de un problema del código nuevo;
falta confirmar con un browser real.
