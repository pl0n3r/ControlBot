# Observabilidad pública en ControlBot — #755

## Autoridad y alcance

Observaciones **exclusivamente de lectura** en la vista autenticada del Orquestador. Un HTTP 200 verifica alcanzabilidad, NO confirma versión/SHA productivo, esquema, smoke, seguridad ni autorización de live. Ningún resultado de esta vista habilita go-live, deploy, publicación, dinero, borrado o datos reales.

La funcionalidad se divide en tres piezas: FactoryPublicHealthObserver (lógica de observación y guardas), FactoryPublicHealthUi (tarjetas privadas) y FactoryOrchestratorLiveUi (integración opcional del campo public_health). No se crea endpoint público, tarea programada, base de datos ni operación mutadora. **El productor del snapshot todavía no pasa observaciones reales**: mientras la configuración no exista, la vista muestra los cuatro repositorios como UNKNOWN y no efectúa ninguna solicitud.

## Configuración estricta

El llamador con autoridad de servidor proporciona dos mapas independientes: approved (URLs HTTPS explícitamente autorizadas por el dueño) y configured (subconjunto habilitado). Las claves únicas son Condor, brvtal, GrindFlow y ControlBot. Las URLs se comparan por igualdad estricta; jamás admitir URLs de Issues, navegador, query, formularios ni datos de GitHub como allowlist. Sin URL configurada, se devuelve unknown:no_public_endpoint y no se consulta DNS o HTTP.

Los tests usan dominios reservados example.org y DNS/transporte sintéticos. **No hay endpoints productivos predeterminados**; configurar los cuatro dominios reales y conectar la captura al productor requiere una implementación y autorización separadas para esos paths, que no están incluidos en la presente reserva V3. No se debe asumir que los dominios mostrados en tests son destinos reales.

## Guardas de transporte y seguridad

- Solamente HTTPS con dominio ASCII público, ruta simple, sin credenciales, IP literal, puerto personalizado, query, fragmento ni caracteres de escape.
- Comprobar DNS y rechazar direcciones IPv4 no públicas. IPv6 permanece denegado hasta que tenga pin seguro; cualquier DNS sospechoso falla cerrado.
- GET con TLS verificado, resolución de IP pública fijada en cURL, sin proxy ni redirecciones, timeout de 2000 ms y body máximo 4096 bytes. El body se descarta, no se persiste.
- No transportar Authorization, Cookie, secretos, cabeceras sensibles ni datos personales.
- 403/404/5xx, timeout, DNS inválido, redirect, body grande y transporte inconsistente nunca se traducen a reachable. Registros de más de 300 segundos son stale, no current.

## Contratos de pruebas AC-01..04

Ejecutar localmente:

    python3 -m unittest tests.test_orchestrator_public_health -v
    php tests/factory_public_health_scenarios.php normal
    php tests/factory_public_health_scenarios.php unconfigured

Se validan cuatro repositorios con HTTP, latencia, fecha y frescura; configuración ausente con cero solicitudes; errores y expiración; protección SSRF/redirect/bytes/credenciales y HTML escapado. Todas las sondas de las pruebas son fakes deterministas, sin red.

## Invariantes de privacidad y reversión

No persistir response body, DNS/IP, tokens, cookies ni cabeceras. El panel privado no tiene botones ni mutaciones y su estado HTTP nunca se etiqueta producción VERDE. No cambiar el control de Factory@v1 ni sus autorizaciones humanas. El squash-revert del PR asociado a #755 elimina únicamente las dos clases, wiring UI, fixtures, tests y este documento; no afecta producción ni configuración de hosting.
