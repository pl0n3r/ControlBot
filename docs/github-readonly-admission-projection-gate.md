# Composición de admisión y proyección GitHub: solo OFFLINE

**Estado:** OFFLINE / SIMULACIÓN, aprobación #1002/A, issue #1003, sin credenciales, acceso GitHub real ni producción.
## Alcance y cadena de confianza
La clase `GitHubReadOnlyAdmissionProjectionGate` exige la evaluación previa de `GitHubReadOnlyInstallationAdmission::inspect()`: owner, repo `pl0n3r/ControlBot`, `installation_id`, revisión, grant sintético y permiso mínimo `metadata:read` + `issues:read`.
Solamente la ruta sintética `GET issues` puede continuar a `GitHubReadOnlyProjectionPipeline::project()` y su writer existente; las diferencias de identidad, scopes, reloj, snapshot y witness retornan UNKNOWN.
El writer usa directorio temporal privado desechable: valida el witness antes y después de staging y aplica `rename` para sustituir `projection.json`. Conserva el archivo anterior si detecta cambios antes del último witness.
**Riesgo residual:** no hay atomicidad demostrada contra revocación externa que ocurra **después** del último witness y antes de `rename`. `CURRENT` se refiere exclusivamente a la proyección de fixtures, nunca a GitHub real.
## Autoridad y reversión
`runtime_authorized=false`, `execute_actions=false`, `production_verified=false`; no tráfico, cuentas reales, tokens, proveedores, PII, pagos, despliegues ni go-live.
La finalidad del fixture es verificar la unión de dos controles read-only; retención efímera en `TemporaryDirectory` y eliminación por teardown, sin transferencias a terceros ni almacenamiento de datos reales.
Revertir el squash retira solo las tres rutas de #1003. Roles: ingeniería, QA, seguridad y privacidad; no sustituye la decisión del dueño ni la revisión de producción.
