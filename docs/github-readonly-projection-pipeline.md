# Pipeline de proyección GitHub sintética OFF — ControlBot #943

**Autoridad:** #939, `/decidir A` del propietario y journal bot v2/A. Dependencias #685/#930/#934 completadas; la hoja #943 dispone de V3 propia. Este pipeline es **OFF por defecto** y no está conectado al runtime, cron, red ni Hostinger.

## Contrato de entrada y resultado

`GitHubReadOnlyProjectionPipeline` recibe tres componentes inyectados: `GitHubReadOnlyInstallationSource` (GET en fixture HMAC), `GitHubReadOnlyHttpsTransport` (GET envelope con sender simulado) y `GitHubReadOnlyProjectionWriter` (escritura atómica de `projection.json` exclusivamente en directorio temporal privado del sistema). El constructor del pipeline y los componentes están desactivados de manera predeterminada; `enabled=true` es **solo para harness de pruebas**. No se incluye un cliente de red, URL arbitraria, clave real ni registro de servicio PHP.

El harness aporta un snapshot v1 sintético ya tipado, manifiesto exacto de proyecto, repositorio, SHA y hora, y testigo que se verifica antes y después del staging. Los dos adaptadores GET comprueban identidad/scope, frescura y completitud con conteo **cero** de ítems; no se derivan atributos del snapshot de los contadores. **Un conteo coincidente no demuestra equivalencia de esquemas.** Esta primera entrega solo permite un repositorio por proyecto, checks con nombre fijo, listas de Issues/PRs vacías, sin releases ni workflows. No deben circular PII ni textos libres.

`CURRENT` significa únicamente que el harness sintético escribió atómicamente una proyección dentro de un directorio `cb-projection-fixture-*`. 403, 429, timeout, respuesta parcial/truncada, SHA/identidad desalineados, expiración, evidencia contradictoria, secreto, PII, grants, race del testigo o destino no-fixture → `UNKNOWN` saneado **sin sobrescribir** la proyección anterior.

## Checklist de los cuatro roles

- **Ingeniería:** composición mínima de tres interfaces existentes; no modifica runtime ni contratos de los tres módulos previos.
- **QA:** cuatro pruebas Python→PHP: OFF sin llamadas, éxito sintético, fallos conservando estado y entradas hostiles.
- **Seguridad:** ninguna operación mutante, lectura de env, cURL o red. Las closures inyectadas NO prueban por sí solas que el callback carezca de red; por eso está terminantemente prohibido inyectar un sender de producción o registrar este módulo en runtime.
- **Legal y privacidad:** fixtures sin identificadores reales, minimización explícita, sin nuevas transferencias/retenciones ni promesa de aprobación jurídica.

En todas las salidas: `runtime_authorized=false`, `production_verified=false`, `execute_actions=false`, `authority=unchanged`. Un resultado local no acredita HEALTH, D-043/D-059 ni go-live. **Reversión:** revertir squash de #943; no existen cambios de esquema, credenciales, deploy, DNS ni proveedores.
