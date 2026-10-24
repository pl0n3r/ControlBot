# Verificación de evidencias de grants GitHub — offline (#856)

Puerta de dirección: ControlBot #855, journal `factory-human-decision` v2 opción A. **Solo se autorizó construir y probar un candado; no emitir ninguna llave**. Relacionado con #55 y #821/#823/#824.

## Contrato
`GitHubIntentGrantVerification::inspect(requirements, proof, trustedContext, seenNonces, trustedNow, verificationKey)` es una función pura (sin I/O ni DB). `requirements` procede del contrato `GitHubIntentAuthorityRequirements::describe`, no de una decisión del cliente. `trustedContext`, el reloj, el almacén acotado de nonces ya vistos y la clave de verificación **deben** proceder exclusivamente de componentes de servidor autenticados. Esta implementación no obtiene credenciales ni modifica la política.

El verificador exige un tipo GitHub soportado para *verificación de evidencia* (no capacidad habilitada), flags `authorized/grant_issued/approval_issued/approval_verified/execution=false`, coincidencia exacta de owner, scope y ref con el contexto confiable, issuer conocido y prueba HMAC-SHA256 sobre el JSON canónico de ocho campos, con comparación en tiempo constante. Emisión de hace más de 300 segundos, expiración, nonces repetidos, firma inválida, claves inferiores a 32 bytes, campos adicionales o procedencia `client` fallan cerrado. Sin almacén auténtico anti-replay el resultado no constituye una validación reutilizable en producción.

**Importante:** incluso si una firma de prueba es verificable, la única decisión es `deny`, razón `offline_only`, `proof_valid=true`, todos los flags de autoridad falsos y ninguna ejecución. `CapabilityPolicy::classify(github.*)` continúa `forbidden` y `GitHubIntentPolicy::evaluateTrusted` continúa `deny/grant_required`. Este módulo no se conecta a ninguna ruta de ejecución, no usa tokens reales, no transmite a GitHub, no crea grants, no habilita Hostinger ni despliega.

## Verificación y reversión
`python3 -m unittest -v tests.test_github_intent_grant_verification` requiere PHP CLI 8.4+ y usa únicamente una clave sintética local construida dinámicamente. Cuatro tests cubren capabilities extrañas, firma/procedencia falsa, owner/scope/caducidad/replay, y el resultado permanentemente sin efecto. Reversión: revert del PR #856. Una futura autorización de capacidades GitHub necesita **otra** decisión de dirección/seguridad, contrato y pruebas propios; el journal #855 no basta.
