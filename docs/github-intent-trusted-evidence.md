# GitHub Intent — Trusted Evidence V1 (offline)

Hoja de ControlBot #821, aprobada como **preparación offline** por la decisión humana
A de #820. No habilita GitHub real, grants, aprobaciones, ejecución o despliegue.

## Frontera de confianza

`GitHubIntentTrustedEvidence::derive($canonicalSnapshot, $repositoryRef, $trustedNow)`
es una proyección pura sobre un **snapshot ya obtenido y autenticado** por
el almacén/observador canónico. La aplicación que la integre **debe** entregar
el snapshot desde un canal de confianza y el reloj desde el servidor; no puede
permitir que el caller construya, sustituya o firme por sí mismo esos valores.
Validar la estructura de un array no demuestra su autenticidad criptográfica.
El componente no invoca GitHub, no lee red, configuración, globals o secretos.

Reutiliza `GitHubProjectView::project`, que valida versión, scope, fuentes,
timestamps, duplicados y colecciones. El TTL de seguridad está **fijado en 300
segundos**, no expuesto como argumento. La proyección acepta solo un repositorio
con nombre exacto y estado/frescura `current` dentro de un proyecto `current`,
sin colecciones truncadas ni evidencia ambigua.

Cuando esa entrada es válida, retorna únicamente:
`version`, `state=current`, `repository_ref`, `source_ref`,
`evidence_ref` derivado de la URL fuente y del `main_sha` observado,
`observed_at` del snapshot, `freshness=current`, `age_seconds` calculado,
y `execution=false`. La referencia de commit documenta la procedencia
estructural de esa observación: **no es una prueba independiente de que la
operación esté autorizada ni de que un commit esté desplegado**.

Cualquier fuente desconocida, duplicada, parcial, stale, con timestamps futuros,
campos extra de caller como `evidence_observed_at` o TTL, scope mismatch o
reloj inválido devuelve solo `state=unknown` y una razón genérica sin eco de
datos; jamás `allow`, grants o `execution=true`.

## Límites y continuación

`GitHubIntentPolicy` V1 conserva `deny/untrusted_evidence_freshness`.
La hoja de requisitos de autoridad y Policy V2 se implementan separadamente,
bajo sus propias reservas y revisión, después del cierre legítimo de esta hoja.
Un `evidence_ref` derivado por sí solo nunca habilita ejecución. La protección
D-069 de `main`, la aprobación humana pertinente, CI exact-HEAD, seguridad y
las puertas de producción siguen siendo obligatorias. No reemplazar snapshots
autenticados por payloads enviados por agentes o desde HTTP.

Pruebas reproducibles:
`python3 -m unittest tests.test_github_intent_trusted_evidence`
(requiere PHP CLI). Los escenarios PHP usan solo fixtures sintéticos.
