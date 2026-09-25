# ControlBot

Centro de control web **privado** de la fábrica de software de pl0n3r. Es una herramienta separada de Condor, GrindFlow y BRVTAL, y consume la gobernanza de `pl0n3r/factory@v1`.

## Estado actual

- Fase: **construcción**.
- Versión actual: **0.1.7** (candidato #38: inbox paginado y fail-closed).
- Stack objetivo: PHP 8.5 + MariaDB.
- Hosting objetivo: Hostinger, junto a Condor pero con app, base y deploy independientes.
- Deploy y observer: instalados en modo **fail-closed**; no actúan sin variables/configuración explícita.
- AutoFactory: puente externo estable. ControlBot lo integra cuando una función lo necesite, pero no se modifica como parte del trabajo actual.

## Orden de implementación

1. #2 — adopción del kit Factory v1.
2. #3 — aprobaciones con un clic.
3. #4 — centro de decisiones.
4. Dashboard básico con la dirección visual de #18.
5. Resto de Issues por prioridad y dependencias.

## Módulos previstos

- Dashboard de producción, trabajo, seguridad y costos.
- Agentes y estado de latido.
- Chat y despacho.
- Decisiones del dueño.
- Nuevo proyecto.
- Cuentas y perfiles.
- Bitácora.

Especificación completa: #1. Puente del navegador: `pl0n3r/AutoFactory#1`.

## Desarrollo

Todo agente debe leer primero `pl0n3r/factory/PLAN-AGENTES.md` y luego `AGENTES.md`. Desde #3 en adelante, cada Issue se reserva con `/tomar`, se trabaja en `trabajo/issue-N` y se valida con Factory v1.

## Inbox de decisiones

El inbox descubre puertas humanas abiertas y confiables en los repos configurados, recorre Issues abiertos con paginación acotada y falla cerrado si no puede completar la lectura. En `factory-release` conserva el SHA exacto de `main`, estado de checks y evidencia disponible. El seguimiento de release correlaciona la corrida por workflow, SHA y momento del dispatch; estados ausentes permanecen pendientes en vez de inventarse.
