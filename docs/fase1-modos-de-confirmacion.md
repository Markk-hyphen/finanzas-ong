# Fase 1 — Modos de confirmación y anulación por el usuario

> **Rama:** `feat/confirmation-modes`. Diseño acordado con Marcos el 2026-10-21 a
> partir de sus ideas (modos de confirmación por proyecto, plazo corto para
> corregir errores propios, scheduler opcional por instancia). Lo marcado
> **[default, revisable]** es una elección del agente que Marcos no cerró
> explícitamente: cambiarla es tocar un valor, no el diseño.
>
> Prerequisito ya hecho en la Fase 0: el **balance proyectado** (pagos
> pendientes reservados al validar). Sin eso ningún modo es seguro.

## 1. Problema

La confirmación bilateral (regla #7 del MANIFIESTO) es útil pero incómoda: todo
pago queda invisible al balance hasta que el receptor entra y confirma. Y el
único que puede corregir un error es el admin, aunque el error sea un cero de
más tipeado hace 30 segundos.

## 2. Modos de confirmación (por proyecto)

| Modo | Al registrar el pago | Qué puede hacer el receptor | Requiere |
|---|---|---|---|
| `required` (default, = hoy) | Nace `pending`, invisible al balance | Confirmar o **rechazar** | — |
| `optimistic` | Nace `confirmed` (impacta ya) | **Rechazar** dentro del plazo de objeción | — |
| `auto_timeout` | Nace `pending` con fecha de autoconfirmación | Confirmar o rechazar antes de esa fecha; si calla, se confirma solo | **Scheduler en la instancia** |

- **El modo se aplica al momento de escribir y queda congelado en el pago**
  (misma filosofía que el reparto: se calcula una vez, al escribir). Cambiar el
  modo de un proyecto afecta solo a pagos nuevos. En `auto_timeout` cada pago
  guarda su propio `auto_confirm_at`.
- **Plazo de objeción** (`objection_window_hours`, **[default 48 h, revisable]**,
  rango 1–720): en `optimistic` es cuánto tiempo puede rechazar el receptor; en
  `auto_timeout` es cuánto tarda en confirmarse solo. Es el mismo concepto ("cuánto
  tiempo tiene el receptor para objetar") y por eso es un solo parámetro.
- **Rechazar** (en cualquier modo) anula solo la parte del receptor que rechaza
  (`cancellation_kind = rejection`): las demás partes del mismo pago siguen
  vigentes y la deuda rechazada vuelve a quedar pagable.
- Solo el admin cambia el modo (`PATCH /api/projects/{id}/settings`).

### 2.1 `auto_timeout` es una capacidad de la instancia, no del proyecto

Una instancia sin el profile `cron` no corre el scheduler y no gasta recursos.
Ahí `auto_timeout` **no se ofrece**: el endpoint de settings lo rechaza (409) y la
UI lo muestra deshabilitado con la leyenda "No es posible configurar auto timeout
en esta instancia". Una instancia con scheduler sí lo permite, por proyecto.

**Cómo lo reconoce el sistema: heartbeat, no variable de entorno.** El job
`app:payments:auto-confirm` (cada 5 min en el crontab) escribe un timestamp en la
tabla `instance_heartbeats`. El backend considera que hay scheduler si el último
latido tiene menos de 15 min. Una variable de entorno puede mentir: si el
scheduler se cae, el heartbeat envejece y la UI avisa ("la autoconfirmación está
detenida") mientras el receptor sigue pudiendo confirmar a mano.

## 3. Anulación por el usuario

Sigue siendo **append-only**: toda corrección es una fila `cancellation`. Cambia
**quién** puede crearla y se registra **por qué** (`cancellation_kind`):

| Tipo | Quién | Sobre qué | Cuándo |
|---|---|---|---|
| `admin` | admin | cualquier movimiento vigente (pagos: el batch entero) | siempre (= hoy) |
| `withdrawal` | el pagador | sus pagos **pendientes** | siempre, antes de que los confirmen |
| `self_correction` | el autor | su **gasto**, o su pago ya confirmado en modo `optimistic` | dentro de `self_cancel_minutes` |
| `rejection` | el receptor | su parte de un pago pendiente, o de un pago `optimistic` | pendiente: siempre; optimistic: dentro del plazo de objeción |

- `self_cancel_minutes`: **[default 15 min, revisable]**, rango 0–1440, `0` lo apaga.
- No se limita a "el último movimiento": la ventana alcanza, y "el último" suma
  casos borde (¿y si hay dos gastos en 10 segundos?).
- Las reglas viven en un servicio de dominio puro (`CancellationPolicy`). La API
  le dice al frontend qué acciones tiene cada movimiento (`actions`), así las
  reglas no se duplican en JS.

## 4. Trazabilidad que se agrega

- Pagos: `confirmed_at`, `confirmed_by_id`, `confirmed_via` (`receiver` /
  `optimistic` / `timeout`). Hasta ahora confirmar era un UPDATE de estado sin
  rastro de quién ni cuándo. Los pagos confirmados antes de esta migración quedan
  con `confirmed_via = receiver` y sin fecha (no se puede reconstruir).
- Anulaciones: `cancellation_kind`. Las existentes se marcan `admin`, que es
  lo único que existía.

Esto es la base del audit log y de las notificaciones de la Fase 2.

## 5. API

| Método + ruta | Quién | Qué hace |
|---|---|---|
| `PATCH /api/projects/{id}/settings` | admin | `confirmationMode`, `objectionWindowHours`, `selfCancelMinutes` |
| `GET /api/instance/capabilities` | autenticado | `{ "autoTimeout": bool }` |
| `POST /api/payments/{batchId}/withdraw` | pagador | Retira sus partes pendientes (o corrige su pago optimistic dentro de la ventana) |
| `POST /api/payments/{batchId}/reject` | receptor | Rechaza su parte. `reason` opcional |
| `POST /api/expenses/{id}/undo` | autor | Deshace su gasto dentro de la ventana |
| `POST /api/movements/{id}/cancel` | admin | Igual que hoy, ahora con `cancellation_kind = admin` |

El dashboard suma `settings` del proyecto, `actions` por movimiento y
`autoConfirmAt` en los pendientes.

## 6. Enmienda al MANIFIESTO

La regla #7 ("confirmación bilateral") pasa a ser el **modo por defecto**, no la
única opción, y §4.6 ("solo admin anula") se amplía con los tipos de §3. Queda
escrito como enmienda E1 en `MANIFIESTO.md`: el documento original no se
reescribe, se enmienda.

## 7. Fuera de alcance (Fase 2+)

Notificaciones (in-app, Mercure, mail, Telegram) y la vista de audit log. Mientras
tanto, la visibilidad en `optimistic` es la del dashboard: los pagos recibidos
muestran hasta cuándo se pueden rechazar.
