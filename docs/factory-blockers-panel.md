# Bloqueos explicados — ControlBot #753

## Semántica y límites

ControlBot es una vista **privada y exclusivamente de lectura**. El panel solo incluye señales de bloqueo `blocked` con procedencia GitHub y sin etiquetas simultáneas incompatibles; `planned` es trabajo planificado no despachable, no un bloqueo. Una señal `UNKNOWN` o `STALE` no justifica afirmar cero bloqueos o un estado GREEN.

El cuerpo de un Issue **puede declarar** información estructurada para explicar una causa, sin que eso demuestre el hecho físico ni quién editó por última vez. El marcador opcional, único y limitado se escribe en el Issue, no en el navegador:

```html
<!-- factory-blocker-evidence {"version":1,"cause":"Pruebas CI sin terminar","actor":"agent","blocked_at":"2026-10-11T08:00:00Z","next_action":"Reparar verificacion exacta","mvp_impact":true} -->
```

Campos exactos obligatorios: `version=1`, causa de 5–160 caracteres no sensibles, `actor` en `owner|agent|external`, timestamp UTC canónico no futuro, acción declarada de 5–160 caracteres no sensibles y booleano `mvp_impact`. El colector exige **un único encabezado** y JSON cerrado. Cualquier ausente, duplicado, marcador inválido o PII/secretos produce `UNKNOWN` en todos los campos declarativos. Nunca extrae responsable o causa de título, autor del PR o etiqueta.

El panel siempre indica `DECLARED` (declarado en GitHub), no `VERIFIED_IN_PRODUCTION`. Los campos faltantes muestran `UNKNOWN` sin edad inventada. Los registros declarados `mvp_impact=true` se ordenan primero; después, antigüedad **declarada** descendente y referencia GitHub estable. No inventar impacto MVP ni presentar el marcador como autorización de acción.

## Seguridad operativa

El colector solo usa GET HTTPS de la allowlist existente y presupuesto de API, sin descargar credenciales ni información personal. El snapshot y UI validan referencia e identidad, normalizan entradas, limitan 50 filas y escapan HTML. No hay botones mutadores, nuevas rutas públicas, cambios en GitHub, deploy, release, producción ni live.

## Pruebas y reversión

`python3 -m unittest tests.test_factory_blockers_panel`

`python3 -m unittest tests.test_orchestrator_evidence_collector tests.test_factory_orchestrator_live_ui`

Los cuatro tests AC abarcan orden determinista, siete repos, exclusión planificada/contradictoria, evidencia declarada, timestamps inválidos, duplicados y regresión de privacidad. Ante un fallo se revierte el PR sin tocar reserva ajena. CI, Sonar, coordinación V3, roles y `Validar` exact-HEAD gobiernan la fusión.

## Selección de bloqueos con límite de 50 (#791)

El **colector** conserva los 50 mejores registros mientras recorre las páginas
GitHub, en lugar de conservar los primeros 50 que devolvió la API. Compara:
(1) `mvp_impact=true` con marcador `factory-blocker-evidence` válido;
(2) `blocked_at` declarado, UTC válido y más antiguo primero;
(3) `issue_ref` canónica para un desempate determinista. Una evidencia
`UNKNOWN`, futura, contradictoria o inválida no recibe impacto MVP
implícito ni una edad inventada. El snapshot reordena con la misma regla.

En memoria se mantienen a lo sumo 51 registros durante una inserción y **50**
al publicar. `signal_summary.omitted.blockers` cuenta las señales que no
caben en ese top-K, tanto si fueron descartadas al llegar como si fueron
desplazadas después. `truncated` sigue representando pérdida de señales
por presupuesto y se muestra explícitamente en la UI; **no** implica que
el panel pueda presentar todos los Issues ni que los marcadores acrediten
salud de producción. No se incrementa el número de consultas API ni se
cambian permisos, acciones, tokens o límites de evidencia.

La señal sintética `blocker:factory-767` tiene prioridad de seguridad sobre
el top-K, únicamente cuando el colector no verifica un kill switch RUNNING.
Se proyecta como bloqueo `UNKNOWN` —sin inventar causa o impacto MVP— y
se contabiliza el elemento ordinario desplazado. Con el guard RUNNING, la
señal no aparece. La regresión utiliza un transporte GitHub estrictamente
sintético y no modifica Factory #767.
