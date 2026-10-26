# Owner-session approval bridge V1 — solo offline

Hoja [#912](https://github.com/pl0n3r/ControlBot/issues/912) de [#55](https://github.com/pl0n3r/ControlBot/issues/55). Puerta OWNER [#911/A](https://github.com/pl0n3r/ControlBot/issues/911) y journal auténtico v2/A. **Esta entrega no autoriza GitHub mutante, ni activa un endpoint.**

## Modelo de confianza

- El único origen de una sesión confiable es el `trustedSessionSource` inyectado **desde código del servidor**. Nunca proviene de campos HTTP, JSON del cliente, cookies no verificadas o flags del navegador. Para este tramo, el origen es un **fixture sintético** (`source=trusted_fixture`, `origin=server`); **no hay enlace a sesiones reales**.
- Cada intento comprueba identidad exacta del propietario, verificación server-side, reautenticación fresca (máximo 120 s), identidad de sesión, acción/ref, nonce vinculado, emisión y expiración acotadas (máximo 120 s), y revocación.
- El nonce se consume **antes** de invocar el fake. Un fallo ambiguo también consume el nonce; los replays no repiten efectos. La revocación se consulta en la fuente al evaluar un intento nuevo.
- El único sender aceptable para este contrato es un **fake in-memory** que recibe `fixture_digest` y `mode=offline_test_only`; no se incorporan transportes HTTP ni tokens.
- El recibo conserva `decision=deny`, `execution=false`, `runtime_authorized=false`, `authorized=false`, `grant_issued=false`, `approval_issued=false`, `approval_verified=false` incluso cuando se devuelve `fixture_status=simulated`.
- Auditoría limitada a 64 eventos, con estado/motivo y hash del nonce. No guarda valores de sesión, owner, credenciales, cookies, CSRF ni detalles de intención.

## Aceptación reproducible

Ejecutar `python -m unittest tests.test_github_owner_session_approval_bridge -v` en un árbol con PHP CLI. Los cuatro métodos AC-01 a AC-04 corren PHP real en un proceso hijo aislado, sin red. No se usan tokens de GitHub ni datos reales.

## Reversión, dependencias y restricciones

Revertir el squash del PR elimina el contrato y sus pruebas. El gateway offline de #907 sigue intacto. Ningún `fake sender`, fixture o check verde acredita aprobación productiva, HEALTH/GREEN, D-043/D-059, go-live, autoridad de red o permiso de escritura remota. Para usar sesiones reales o emitir operaciones GitHub, abrir una puerta de propietario **separada**, verificar permisos mínimos y aplicar todas las guardas operativas correspondientes.
