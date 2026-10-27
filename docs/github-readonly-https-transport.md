# Transporte HTTPS GET-only desconectado (ControlBot #930)

Autorización: puerta product-direction [#929](https://github.com/pl0n3r/ControlBot/issues/929), OWNER `/decidir A` y journal bot v2/A; depende de #927 fusionada. Esta entrega añade exclusivamente una **política PHP de envelope HTTPS** y un sender `Closure` inyectado por tests, sin wiring con el runtime de ControlBot y sin cliente de red. **La implementación no ejecuta cURL, sockets, ni peticiones HTTP reales.**

## Frontera y límites

`new GitHubReadOnlyHttpsTransport($fakeSender)` queda inactivo por defecto. Solo un harness explícito puede construir `new GitHubReadOnlyHttpsTransport($fakeSender, true)` y llamar a `read()` con `method=GET`, `host=api.github.com`, `repository=owner/name`, `resource=issues|pulls`, `page_size` entre 1 y 20 y tiempos enteros `observed_at`, `expires_at` y `now` (ventana máxima 300 s).

El sender recibe un **único envelope de modo `offline_test_only` por página**, con URL HTTPS fija "/repos/{owner}/{repo}/issues|pulls", GET, verificación de peer y hostname TLS, cero redirecciones, timeouts connect=2s/total=5s, límite de 32768 bytes por página. Límite agregado de 65536 bytes, tres páginas y 60 IDs. El fake devuelve status=200, content_type=application/json, redirected=false y body JSON de `{page,items,complete,next_page}`. Solo IDs positivos y estado `open|closed`; ninguna respuesta real ni PII debe entrar al harness.

Todo método mutante, host o ruta arbitraria, TTL vencido, fallo del sender, respuesta 403/429, redirección, error de TLS/timeout (modelados como excepción de fake), contenido HTML/JSON inválido, exceder bytes/páginas, estado parcial, identidades o items extra ⇒ `UNKNOWN`, `projection=null`. El log `audit()` conserva únicamente `status` y razón controlada, nunca URL, repo, ID, respuesta ni tokens. En éxito se devuelve solo recuento y páginas (`offline_metadata_only`), sin contenido del fixture.

## Invariantes de autoridad

Siempre `runtime_authorized=false`, `production_verified=false`, `execute_actions=false` y `authority=unchanged`. La activación productiva exige otra puerta: instalación/credenciales reales, autorización de scopes y tratamiento de datos, adapter TLS genuino, observación y condiciones D-043/D-059. El envelope tiene parámetros seguros, **pero no es evidencia de una conexión TLS real**.

## Tests y reversión

`php -l src/GitHubReadOnlyHttpsTransport.php` y `python3 -m unittest -v tests.test_github_readonly_https_transport`. Los cuatro AC ejecutan PHP con un fake determinista, no red. Reversión: revertir el commit/squash; no hay tablas, cron, secretos, puertos, deploy ni configuración productiva que revertir.
