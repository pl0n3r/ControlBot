# ControlBot

Centro de control web **privado** de la fábrica de software de pl0n3r. Es una herramienta separada de Condor, GrindFlow y BRVTAL, y consume la gobernanza de `pl0n3r/factory@v1`.

## Estado actual

- Fase: **construcción**.
- Versión actual: **0.1.15** (candidato #46: Capability Registry + policy/grants fail-closed para Production Authority).
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


## Runtime privado de decisiones

El runtime de #39 vive en `src/DecisionRuntime.php`: carga el inbox real desde GitHub con allowlist configurada en servidor, inyecta CSRF en las acciones, delega aprobaciones al endpoint seguro existente y conserva en la sesión server-side la correlación del release. No crea `public/`, no habilita deploy y no expone una cabina pública. El seguimiento devuelve evidencia terminal cuando existe una única corrida compatible y bloquea correlaciones ambiguas sin atribuir una ejecución arbitraria.

## Historial de decisiones

El corte #43 reutiliza la bitácora append-only existente para exponer un historial privado consolidado por decisión, con filtros server-side por repositorio y categoría. La salida omite actor y campos no allowlisted, solo conserva evidencia HTTPS de GitHub y falla cerrado ante filtros o entradas inválidas. El inbox mantiene prioridad para decisiones que bloquean trabajo y, dentro de esa clase, ordena por antigüedad.
