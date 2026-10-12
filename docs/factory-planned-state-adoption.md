# Planificados en el Orquestador — ControlBot #752

## Alcance y fuente

El Orquestador es una vista privada, **solo lectura**, derivada de GitHub sobre los siete repositorios canónicos. El estado `estado: planificado` (`status: planned` para BRVTAL/inglés) significa planificación no despachable; **no es** `estado: disponible` ni `estado: bloqueado`. Que la etiqueta exista en el catálogo o en GitHub no prueba que un plan tenga reserva V3.

La captura utiliza GET autenticado por el servidor con credenciales externas al repositorio, cuota de solicitudes y bytes acotada, una consulta de Issues por repositorio paginada y frescura UTC verificable. El lector no acepta endpoints aportados por el navegador, no escribe en GitHub y no copia cuerpos de Issues/PRs al HTML.

## Semántica verificada por los tests

| Etiquetas del Issue | Clasificación | Señal de trabajo | Bloqueo |
| --- | --- | --- | --- |
| `estado: planificado` o `status: planned` | `planned` | No | No |
| `estado: disponible` | `available` | Sí (observación) | No |
| `estado: reservado` / `en revisión` | `reserved` / `in_review` | Sí (observación) | No |
| `estado: bloqueado` | `blocked` | No | Sí |
| Alias ES + EN equivalentes | Un único estado | Según su significado | Según su significado |
| Dos estados incompatibles | `UNKNOWN` por proyecto | Nunca autoriza `available` | Preserva un `blocked` explícito |
| Paginación, labels o timestamps no demostrables | `UNKNOWN` / rechazo | Nunca habilita despacho | No inventa ceros |

La vista distingue contadores `planned` y `blocked` cuando el inventario es completo y actual. Los valores no demostrables aparecen como `UNKNOWN`, sin proyectar texto no confiable de GitHub al HTML.

**Importante:** `planned_unlockable` representa otro contrato heredado, independiente de la cantidad de Issues `planned`; el colector no dispone de evidencia de desbloqueabilidad y lo mantiene `null`. El indicador de cola vacía no interpreta la etiqueta `planned` como trabajo `ready`.

## Verificación y reversión

- `python3 -m unittest tests.test_planned_state_adoption`
- `python3 -m unittest tests.test_factory_agent_activity tests.test_orchestrator_evidence_collector`
- CI, aceptación y `Validar` deben terminar `success` sobre el SHA exacto del PR.

Fixtures deterministas con PHP y transporte GET falso, sin red real ni datos personales. Reversión por squash/revert del PR #752 si fuera necesaria.

Este cambio **no** modifica las etiquetas remotas, Factory #767, la política de reservas, los checks de release ni el despliegue. No declara producción GREEN, live, presupuesto ni disponibilidad de agentes.
