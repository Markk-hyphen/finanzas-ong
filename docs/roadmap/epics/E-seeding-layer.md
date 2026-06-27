# Epic E — Seeding layer (staging)

> **Estado:** ✅ implementado · **Versión destino:** v1.1 · **Origen:**
> necesidad surgida en finanzas-ong (poblar staging con datos representativos sin SQL crudo).

## Problema

Oliva no tenía seeding de primera clase. Poblar la base en staging requería SQL
crudo a mano o scripts ad-hoc (`seed.sh` local). Sin una capa de factories,
los forks no pueden generar datos de prueba reproducibles ni testear flujos
post-deploy de forma consistente.

## Visión

Equivalente Symfony del par `seeders + factories` de Laravel: datos falsos pero
representativos, reproducibles, segregados por grupo (`staging` / `dev`), con
**guard estructural** contra seedear producción accidentalmente.

El guard no es una convención — es estructural: la imagen prod se compila con
`--no-dev`, así que el comando `doctrine:fixtures:load` directamente no existe en
la imagen prod.

## Stack agregado

| Paquete | Rol |
|---|---|
| `zenstruck/foundry` | Factories tipadas con estados y relaciones fluidas (trae FakerPHP) |
| `doctrine/doctrine-fixtures-bundle` | Runner (`doctrine:fixtures:load --group=`) |

Ambos como `require-dev` — no entran en la imagen prod.

## Target Docker `frankenphp_staging`

Multi-stage build: `frankenphp_staging` hereda de `frankenphp_prod` (no dev).
Staging = prod + tooling de seeding. Mantiene representatividad del entorno; si
divergiera de prod, dejaría de testear lo que realmente corre.

```
frankenphp_base
  ├── frankenphp_dev   (dev)
  ├── frankenphp_prod  (producción)
  └── frankenphp_staging  ← hereda de prod, suma --dev deps
```

## Slices entregados

- [x] `composer require --dev zenstruck/foundry doctrine/doctrine-fixtures-bundle`
- [x] `AppFixtures` con grupos `staging` / `dev` y comentarios para forks
- [x] `AppStory` como punto de entrada de escenarios Foundry
- [x] Target `frankenphp_staging` en el Dockerfile
- [x] Paso opt-in en el runbook de deploy del `CLAUDE.md`
- [x] Comando documentado en `.env.example`
- [x] Sección Foundry en `docs/concepts.md`

## Cómo extiende un fork

1. `bin/console make:factory` para cada entidad (o a mano en `src/Factory/`).
2. Llamar las factories en `AppFixtures::load()` o en `AppStory::build()`.
3. El target `frankenphp_staging` ya existe: solo necesita `docker compose build --target frankenphp_staging`.

## Por qué es un hito de framework

El target `frankenphp_staging` introduce **multi-stage build con herencia semántica**
en Oliva: cada stage tiene un propósito explícito (dev / prod / staging) y la imagen
de staging reutiliza prod sin divergir. Es el patrón Docker recomendado para tooling
de testing post-deploy y escala para sumar más tools (ej. API collection) sin tocar prod.
