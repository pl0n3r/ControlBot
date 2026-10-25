# Emisor de evidencia GitHub offline, no de permisos — ControlBot #869

Aprobación del dueño: [ControlBot #867](https://github.com/pl0n3r/ControlBot/issues/867), opción A con journal autenticado v2. Alcance **exclusivo de tests** dentro del camino a #762. No autoriza acciones reales de GitHub.

La función **GitHubIntentOfflineIssuer::issue(requirements, approval, trusted, seenNonces, revokedNonces, now, fixtureKey)** es pura: no depende de red, almacenamiento, endpoints, secretos del servidor ni transportes. No se conecta a GitHubIntentPolicy o CapabilityPolicy. El parámetro approval es un **fixture tipado de aprobación del propietario**, no una aprobación productiva. El caller de test inyecta contexto servidor, hora, nonce y clave HMAC de 32+ bytes; esos valores nunca deben proceder del cliente en producción.

Exige tipo de acción y capability permitidos solo para evidencia, owner, project/repository scope exacto, recibo sintético de prefijo fixture:, owner_verified de prueba, approved=true, test_only=true, modo offline_test_only, aprobación reciente (hasta 300 s), nonce no visto ni revocado, TTL 1–300 s y clave efímera. La firma HMAC-SHA256 usa los mismos ocho campos posicionales que el verificador #856. Cualquier anomalía devuelve proof=null y decision=deny, sin eco de entradas sensibles.

Incluso con firma correcta: reason=offline_only, authorized=false, grant_issued=false, approval_issued=false, approval_verified=false y execution=false. GitHubIntentGrantVerification::inspect confirma la firma offline, pero también devuelve deny/offline_only. No se registran github.* en CapabilityPolicy ni se genera CapabilityGrant válido. La revocación/replay recibe listas inyectadas de test, nunca pretende constituir almacenamiento productivo.

## Pruebas, amenazas y reversión

Comando: **python3 -m unittest -v tests.test_github_intent_offline_issuer** (PHP CLI 8.4+). Cuatro tests cubren owner/procedencia/scope, interoperabilidad HMAC, catálogo/TTL/replay/revocación y ausencia de ejecución. No aceptar estas pruebas como autorización real, integración GitHub, gate de merge de #762 o aprobación de release. Reversión: revert de las tres rutas del PR #869.