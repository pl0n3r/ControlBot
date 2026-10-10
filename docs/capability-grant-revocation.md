# Capability grants: revocación al concluir (build-ahead)

`ControlBot\Production\CapabilityGrantRevoker` es un guard de ciclo de vida **local, en memoria**, destinado exclusivamente a simulaciones con ejecutores inyectados (fake). No implementa operaciones reales, transporte HTTP, credenciales, persistencia ni despliegue. No vuelve VERDE a ControlBot ni satisface el gate de producción #45.

## Contrato de uso

1. Emitir un `CapabilityGrant` válido mediante `CapabilityGrant::issue()`, sin modificar políticas existentes.
2. Instanciar un `CapabilityGrantRevoker` para la simulación y llamar `authorize($grant,$scope,$now)` para comprobar el permiso. El scope debe coincidir exactamente y el tiempo es epoch UTC entero. El cuarto argumento opcional `restrictions` preserva las restricciones de política del llamador.
3. Usar `execute($grant,$scope,$now,$fakeExecutor)` únicamente con callback sintético. **Primero** verifica autorización y política, incluidas las restricciones dinámicas que el llamador pasa como quinto argumento opcional. Solo una respuesta exactamente `true` acredita `executed`; `false`/`null`/respuesta ambigua retorna `ambiguous` y no acredita ejecución. En éxito, respuesta ambigua o excepción, guarda el grant revocado en memoria y devuelve un recibo saneado. Si deniega, no llama al callback.
4. Para verificar autorización después, usar **el mismo revoker** y el `grant` revocado del recibo: el grant consumido se rechaza; un mismo `idempotency_key`, `grant_id`, registro, scope y restricciones devuelve el recibo anterior sin repetir el efecto; un replay con restricciones distintas se rechaza. La reentrada mientras el callback está activo también se rechaza.

La auditoría solo contiene código de evento (`grant_consumed`/`grant_rejected`), estado (`executed`, `ambiguous`, `failed` o `rejected`) y `at` UTC. No almacena datos de transporte, secretos, payloads, scope, respuesta ni texto de excepciones. Los fallos del fake usan `fake_execution_failed`, y respuestas sin confirmación usan `fake_execution_unconfirmed`.

## Limitación deliberada y gate antes de live

El mapa de revocación vive únicamente **en memoria PHP**. Dos instancias, procesos o solicitudes independientes no comparten consumo/revocación; por eso **no se puede conectar a un ejecutor real**, afirmar garantía global de un solo uso, ni autorizar transporte productivo. El consumidor real deberá integrar un ledger durable y atómico, exigir exclusión/idempotencia entre procesos y verificar que el grant revocado persista y no pueda reinyectarse; esa integración requiere un Issue/claims y pruebas nuevos. `CapabilityGrant` permanece inmutable por compatibilidad. No modificarlo ni activar una operación real desde esta hoja.

**Reversión:** retirar los cuatro archivos propios del Issue #765; no hay migraciones, cambios de protocolo ni datos a recuperar. Tests: `python3 -m unittest tests.test_capability_grant_revoker` (llama escenarios PHP sin red). Revisión requerida: ingeniería, seguridad, QA; CI exact-HEAD y revisión independiente antes de merge.
