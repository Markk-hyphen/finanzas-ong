# Manifiesto — Sistema de Finanzas ONG (sobre Oliva)

> Documento de especificación para Claude Code (CLI). Define **qué** construir, las **reglas inviolables**, y el **orden** de construcción. No es un tutorial: es un contrato. Cuando algo de aquí choque con un patrón ya existente en el repo, **gana el patrón del repo** y se reporta el conflicto en vez de inventar una convención nueva.

---

## 0. Antes de escribir una sola línea: descubrir convenciones

Esta app se construye **sobre el framework Oliva** (Symfony 7 · FrankenPHP worker mode · Caddy · JWT · Vite · Vanilla JS · Bootstrap 5 · SweetAlert2 · PostgreSQL 15 · Docker Compose). El frontend proxea `/api/*` al backend por la red interna.

**Tarea obligatoria #0 — leer y espejar el repo.** Antes de generar código, inspeccionar y replicar los patrones existentes:

- `backend/src/` → cómo se define una **entidad Doctrine** (atributos, tipos, naming), cómo se estructura un **controller de API**, cómo se serializa el JSON de respuesta, cómo funciona el **JWT auth** (endpoint de login, guard, cómo se obtiene el usuario actual), cómo se corren las **migraciones**.
- `backend/` → cómo se ejecutan **tests** (PHPUnit u otro), comandos dentro del contenedor.
- `frontend/src/` → cómo el **JS vanilla consume la API** (wrapper de fetch, manejo del token JWT, manejo de errores), cómo se usan **Bootstrap 5** y **SweetAlert2**, cómo se organizan las vistas/páginas.

Replicar esos patrones. **No introducir librerías, ORMs, ni convenciones nuevas sin justificación explícita.** Si falta un patrón para algo, proponerlo y esperar confirmación antes de implementarlo a gran escala.

---

## 1. Qué es esto

Sistema de autogestión financiera **socios-para-socios** de una ONG, a baja escala. N socios construyen N proyectos (ej.: una sala de cultivo). Cada socio registra **gastos** (frontea plata para comprar algo) y **pagos** (transfiere plata a otro socio para saldar lo que debe). El sistema lleva, por proyecto, el **balance neto de cada socio**: cuánto aportó, cuánto debe, cuánto se le debe.

- Usuarios: un puñado de socios, lo usan **desde el celular** (web app mobile-first responsive).
- Es una app **para mantener por años**. Prioridad: **correctitud financiera y trazabilidad** por encima de features.
- Hosting final: VPS, con Docker Compose de producción + dominio + HTTPS.

---

## 2. Reglas inviolables (NON-NEGOTIABLE)

Estas reglas mandan sobre cualquier conveniencia de implementación. Violarlas es un bug crítico, no una decisión de diseño.

1. **Dominio puro y aislado.** Toda la lógica financiera (cálculo de balances, allocator, validaciones, invariantes) vive en servicios **PHP puros, sin dependencias de Symfony/Doctrine/HTTP**. El framework es solo capa de entrega. Objetivo: testear la plata sin levantar un servidor, y sobrevivir a un cambio de framework.

2. **Plata en `NUMERIC`, jamás `float`.** Montos como `numeric(18,2)` en DB y como tipo decimal exacto en PHP (BCMath / objeto Money). Nunca aritmética de punto flotante sobre dinero.

3. **Balances DERIVADOS, nunca almacenados.** No existe un campo "balance" en ninguna tabla. El balance se calcula como un *fold* sobre la tabla `movimientos`. Una sola fuente de verdad.

4. **Ledger append-only.** **Nunca** `UPDATE` ni `DELETE` sobre un movimiento financiero. Corregir = registrar una **anulación** (fila nueva que apunta al original) + insertar el movimiento correcto. La fila errada queda en el log para siempre.

5. **Invariante de conservación.** Para todo proyecto: la suma de los balances de sus socios es **exactamente cero**. Esto se testea como propiedad (ver §7).

6. **Asignación de pagos vía estrategia, validada en la salida.** El reparto de un pago entre acreedores pasa por una interfaz `AllocationStrategy`. Cualquier reparto (automático o, a futuro, manual) debe validarse en su **resultado**, no en su entrada.

7. **Confirmación bilateral de pagos.** Un pago nace `pendiente` y es **invisible al balance** hasta que el **receptor** lo confirma. Solo pagos confirmados entran al cálculo. *(Enmendada por E1: es el modo por defecto, no el único — ver §11.)*

---

## 3. Modelo de datos

Esquema objetivo (traducir a entidades Doctrine siguiendo el patrón del repo; los nombres de dominio van en **español**).

```sql
socios (
  id        bigserial primary key,
  nombre    text not null,
  activo    boolean not null default true,
  -- credenciales de auth según el patrón JWT existente de Oliva
)

proyectos (
  id        bigserial primary key,
  nombre    text not null,
  creado_en timestamptz not null default now()
)

-- el % vive en la relación, NO en el socio
socio_proyecto (
  socio_id    bigint not null references socios,
  proyecto_id bigint not null references proyectos,
  porcentaje  numeric(5,2) not null check (porcentaje > 0 and porcentaje <= 100),
  primary key (socio_id, proyecto_id)
)
-- INVARIANTE (nivel app): Σ porcentaje = 100 por proyecto

materiales (
  id                 bigserial primary key,
  nombre             text not null,
  nombre_normalizado text not null   -- lower + sin acentos; punto de enganche para pg_trgm a futuro
)

-- ledger append-only: gasto, pago y anulacion en una sola tabla
movimientos (
  id                  bigserial primary key,
  proyecto_id         bigint not null references proyectos,
  tipo                text   not null check (tipo in ('gasto','pago','anulacion')),
  from_socio_id       bigint references socios,   -- gasto: quien frontea | pago: quien paga
  to_socio_id         bigint references socios,   -- solo pago: quien cobra
  monto               numeric(18,2) check (monto > 0),
  estado              text not null default 'confirmado'
                        check (estado in ('pendiente','confirmado')),
  batch_id            uuid,                        -- agrupa las N filas de un mismo pago multi-acreedor
  anula_movimiento_id bigint references movimientos,
  motivo              text,                        -- razón de la anulación
  material_id         bigint references materiales, -- solo gasto, opcional
  cantidad            numeric(18,3),               -- solo gasto, opcional (analytics futura)
  valor_referencia    numeric(18,4),               -- hook moneda/valor, nullable, NO se puebla en MVP
  creado_por          bigint not null references socios,
  creado_en           timestamptz not null default now(),

  -- integridad de forma por tipo:
  check (tipo <> 'gasto'     or (from_socio_id is not null and monto is not null and to_socio_id is null)),
  check (tipo <> 'pago'      or (from_socio_id is not null and to_socio_id is not null
                                 and monto is not null and material_id is null)),
  check (tipo <> 'anulacion' or anula_movimiento_id is not null),
  -- solo los pagos pueden quedar pendientes; gastos nacen confirmados:
  check (tipo = 'pago' or estado = 'confirmado')
)
```

**Movimiento vigente** = movimiento de tipo `gasto`/`pago` cuyo `id` **no** está referenciado por ninguna fila `tipo='anulacion'`. Los balances se calculan solo sobre vigentes.

---

## 4. Lógica de dominio (el corazón)

### 4.1 Balance del proyecto
```
costo_total(proyecto) = Σ monto de los gastos VIGENTES del proyecto
```
Los pagos **no** afectan el costo del proyecto (son redistribución interna).

### 4.2 Balance de un socio en un proyecto
```
balance(socio i) =  Σ(monto de gastos vigentes con from_socio = i)
                  + Σ(monto de pagos vigentes CONFIRMADOS con from_socio = i)
                  − Σ(monto de pagos vigentes CONFIRMADOS con to_socio   = i)
                  − porcentaje_i × costo_total(proyecto)
```
- `balance > 0` → al socio se le debe (acreedor).
- `balance < 0` → el socio debe (deudor); ese negativo **es** la "deuda".

Implementar como servicio puro que recibe el conjunto de movimientos vigentes + porcentajes y devuelve los balances. (En la capa de entrega puede materializarse como vista SQL o query agregada, pero la lógica de referencia y los tests viven en el dominio puro.)

### 4.3 Registro de un GASTO
1. Validar: `monto > 0`.
2. **Warning de anomalía (no bloqueo):** si `monto > 5 × mediana(gastos del proyecto)`, devolver una advertencia para que el usuario confirme el input (posible cero de más). Es un *warning*, nunca un bloqueo: un gasto grande legítimo debe poder registrarse. Usar **mediana**, no media (un gasto grande corre la media).
3. Insertar fila `tipo='gasto'`, `from_socio_id = autor`, `to_socio_id = NULL`, material/cantidad opcionales, `estado='confirmado'`.

### 4.4 Registro de un PAGO
El pagador ingresa **un solo monto**. El sistema reparte ese monto entre los acreedores actuales y persiste **N filas** `tipo='pago'` (una por acreedor), todas con el mismo `batch_id`. El reparto se calcula **una vez, al escribir, y queda congelado** — no se recalcula en lectura.

Flujo:
1. Validar: `monto > 0` y `monto ≤ |balance del pagador|` (no se puede pagar más que la deuda neta).
2. Llamar a `AllocationStrategy.allocate(pagador, monto, balances_actuales)` → lista de `(to_socio, monto_parcial)`.
3. **Validar el resultado del reparto** (no la entrada):
   - cada `monto_parcial ≤ crédito (balance positivo) del receptor`,
   - `Σ monto_parcial == monto`,
   - `Σ monto_parcial ≤ |balance del pagador|`.
4. Persistir N filas `tipo='pago'`, `estado='pendiente'`, `batch_id` común.
5. Cada receptor confirma **su** fila (pasa a `confirmado`). Recién ahí impacta el balance.

> **Recomendación UX:** mostrar el reparto propuesto antes de confirmar ("esto salda 333k a A y 333k a B"). El pagador acepta o —a futuro— ajusta. Ver §6.

### 4.5 AllocationStrategy
Interfaz. Implementación MVP única:

- **`ProporcionalAlCredito`**: reparte el monto entre los socios con balance positivo, **proporcional a su crédito** (NO a su porcentaje del proyecto — eso sería doble conteo). Resolver el residuo de centavos con **mayor resto (largest remainder)** para que `Σ partes == monto` exacto, sin perder ni crear un centavo.

Dejar el seam listo para enchufar a futuro una estrategia `DirigidoPorUsuario` sin tocar nada aguas abajo (mismas filas de salida).

### 4.6 Anulación (corrección) *(ampliada por E1 — ver §11)*
- Solo rol **admin** puede anular.
- Insertar fila `tipo='anulacion'` con `anula_movimiento_id` → original y `motivo`.
- El conjunto vigente excluye el original (y la anulación misma no entra al fold).
- Corregir = anular + insertar el movimiento correcto. **Nunca** editar/borrar in-place.
- Anular un pago multi-acreedor anula **todo el `batch_id`**.

---

## 5. API

REST sobre `/api`, protegida con el JWT existente de Oliva. Roles: **`admin`** (el dueño) y **`socio`**.

Endpoints mínimos (ajustar forma al patrón de controllers del repo):

- `POST /api/login` — auth (patrón Oliva existente).
- `GET  /api/proyectos` — proyectos del socio autenticado.
- `GET  /api/proyectos/{id}/dashboard` — costo total, balances por socio, movimientos del socio (gastos/pagos con fecha), pagos pendientes de su confirmación.
- `POST /api/proyectos/{id}/gastos` — registrar gasto (devuelve warning de anomalía si aplica).
- `POST /api/proyectos/{id}/pagos/preview` — calcular y devolver el reparto propuesto **sin** persistir.
- `POST /api/proyectos/{id}/pagos` — registrar el pago (persiste las N filas pendientes).
- `POST /api/pagos/{batch_id}/confirmar` — el **receptor** confirma su parte.
- `POST /api/movimientos/{id}/anular` — **admin only**, con motivo.
- `GET  /api/materiales?q=...` — búsqueda para autocomplete (detrás de la interfaz `MaterialMatcher`).

Reglas de autorización: un socio crea sus propios gastos/pagos y confirma pagos donde es `to_socio`. Solo admin anula.

---

## 6. Frontend (mobile-first)

Vanilla JS + Bootstrap 5 + SweetAlert2, consumiendo `/api` con el wrapper de fetch existente. **Mobile-first responsive.** Opcional (fase posterior): manifest PWA para "agregar a pantalla de inicio". **No** offline-first.

Pantallas MVP:
1. **Login.**
2. **Dashboard de proyecto:** balance propio destacado (debo / me deben / saldado), balances del resto, lista de mis movimientos.
3. **Registrar gasto:** monto (obligatorio), material + cantidad (opcionales, con **autocomplete** sobre materiales existentes para evitar duplicados). Si la API devuelve warning de anomalía, confirmar con SweetAlert2.
4. **Registrar pago:** ingresar monto → llamar a `/pagos/preview` → mostrar el reparto propuesto → confirmar para persistir.
5. **Pagos pendientes de confirmar:** lista donde el socio es receptor; botón confirmar.
6. **Corrección (admin):** anular un movimiento con motivo.

"Saldado": cuando `|balance| < umbral` se puede mostrar como saldado en la UI — **solo display, jamás muta el ledger**.

---

## 7. Testing (obligatorio, sobre el dominio puro)

PHPUnit sobre los servicios de dominio. Una fase no se considera terminada sin sus tests.

- **Propiedad de conservación:** para cualquier conjunto de gastos y pagos confirmados vigentes, `Σ balances del proyecto == 0`. Idealmente **property-based** (generar conjuntos aleatorios). Caza bugs de signo y redondeo que los casos puntuales no ven.
- **Allocator:** `Σ partes == monto` exacto incluso con divisiones no exactas (ej.: 1.000.000 / 3); el mayor resto no pierde ni inventa centavos.
- **Validación de reparto:** rechazar repartos que excedan el crédito de un receptor o la deuda del pagador.
- **Anulación:** un movimiento anulado no afecta balances; original + anulación dejan el sistema como si no hubiera existido.
- **Sanity checks:** `gasto.monto > 0`; warning de anomalía dispara pero no bloquea; `pago.monto ≤ |deuda|`.

---

## 8. Orden de construcción (fases con criterio de aceptación)

- **Fase 0 — Reconocimiento.** Leer y documentar las convenciones del repo (§0). Confirmar que `docker compose up` levanta back/front/DB. *Criterio: convenciones documentadas, entorno corriendo.*
- **Fase 1 — Esquema.** Entidades Doctrine + migraciones para `socios`, `proyectos`, `socio_proyecto`, `materiales`, `movimientos` con todos los CHECK. *Criterio: migración aplica limpia; CHECKs presentes.*
- **Fase 2 — Dominio puro + tests.** `AllocationStrategy` + `ProporcionalAlCredito` (mayor resto), servicio de balances, validaciones, mecánica de vigencia/anulación. **Test-first.** *Criterio: todos los tests de §7 en verde. Esta fase es el corazón; no avanzar sin ella sólida.*
- **Fase 3 — API + auth/roles.** Endpoints de §5 sobre el dominio de fase 2, con JWT y autorización admin/socio. *Criterio: flujo completo gasto→pago→preview→confirmar→anular vía HTTP, con balances correctos.*
- **Fase 4 — Frontend mobile.** Pantallas de §6. *Criterio: un socio puede operar todo desde el celular.*
- **Fase 5 — Deploy.** Compose de producción en VPS, dominio, HTTPS. *Criterio: accesible y seguro desde el celular de un socio.*

---

## 9. Fuera de alcance del MVP (NO construir)

No implementar nada de esto salvo orden explícita posterior. Dejar solo los *hooks* indicados:

- Aportes recurrentes / cuotas (sin implementación; el modelo no lo impide a futuro).
- Feature de moneda/inflación: solo el campo `valor_referencia` (nullable, sin poblar). El `creado_en` preciso ya permite backfillear cotizaciones históricas.
- Mercure / confirmación en tiempo real (la capacidad existe en Oliva; queda para fase posterior — MVP usa lista de pendientes).
- Búsqueda fonética / `pg_trgm` (solo dejar `MaterialMatcher` abstraído y `nombre_normalizado` listo).
- Link pago→gasto puntual (columna nullable aditiva si algún día se quiere; no ahora).
- Capa de analytics sobre material/cantidad (los campos quedan inertes, listos).
- PWA offline-first.

---

## 10. Glosario

- **Socio:** persona miembro de uno o más proyectos.
- **Proyecto:** emprendimiento concreto (ej.: sala de cultivo) donde los socios aportan.
- **Gasto:** un socio frontea plata para comprar algo para el proyecto.
- **Pago:** un socio deudor transfiere plata a uno o más socios acreedores para saldar.
- **Movimiento:** cualquier asiento del ledger (gasto, pago o anulación).
- **Balance:** posición neta de un socio en un proyecto (deriva, no se almacena).
- **Vigente:** movimiento no anulado.
- **Batch:** conjunto de filas de pago generadas por una sola acción de pago.

---

## 11. Enmiendas

Las reglas de arriba no se reescriben: se enmiendan acá, con fecha y motivo.

### E1 — Modos de confirmación y anulación por el usuario (2026-10-21)

**Motivo:** en el uso real la confirmación bilateral obligatoria resultó incómoda,
y que solo el admin pueda corregir convierte cualquier error de tipeo en una
gestión. Diseño completo en `docs/fase1-modos-de-confirmacion.md`.

- **Regla #7:** pasa a ser el modo `required` (el default). Cada proyecto puede
  elegir además `optimistic` (el pago nace confirmado y el receptor puede
  rechazarlo dentro de un plazo) o `auto_timeout` (pendiente que se confirma solo
  si el receptor no objeta en el plazo; solo en instancias con scheduler). El modo
  se congela en cada pago al escribirlo.
- **§4.6:** además del admin, pueden anular el pagador (retirar un pago pendiente),
  el receptor (rechazar su parte) y el autor (deshacer lo propio dentro de una
  ventana corta). Toda anulación sigue siendo una fila nueva (regla #4 intacta) y
  registra su tipo.
- **Invariantes que NO cambian:** append-only (#4), balances derivados (#3),
  conservación (#5), validación del reparto en la salida (#6). Para validar pagos
  nuevos se usa el balance proyectado (pendientes reservados), no solo el
  confirmado.
