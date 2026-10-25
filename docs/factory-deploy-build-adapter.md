# Build reproducible y offline para Factory@v1 — ControlBot #888

Este adaptador implementa **únicamente** ops/factory/build (ejecutable 100755) para la primera etapa del pipeline reusable de Factory@v1, sin autoridad de despliegue. **No despliega** en Hostinger, no abre red, no accede a secretos ni acredita funcionamiento productivo.

La ejecución exige PHASE=construccion, un GITHUB_SHA de 40 caracteres hexadecimal que coincida exactamente con el checkout Git y las entradas index.php, .htaccess, config/version.json, public/index.php, public/health.php y src/HealthIdentityHttpEndpoint.php rastreadas. Publica el candidato **solo local** en var/factory/build.tar.gz (permisos 0600 y ruta ignorada por Git). La entrada se forma exclusivamente a partir de rutas seguidas por Git en el índice: PHP de src/, PHP/assets estáticos de public/, y los tres archivos raíz/config enumerados. Nunca empaqueta .env, datos.yml, configuración de credenciales, tests, materiales no rastreados, symlinks, archivos demasiado grandes ni rutas fuera de la allowlist.

El TAR/GZIP es reproducible (mtime y metadatos normalizados; miembros ordenados). factory-build-manifest.json contiene source_sha, versión semver y hashes SHA256 de cada miembro. La coincidencia exacta con Git evita atestaciones basadas solamente en un SHA declarado sin checkout coincidente. Este manifest **no** es una firma HMAC, recibo D-059 ni prueba de versión realmente desplegada.

## Gates que permanecen cerrados

- La cadena Factory@v1 necesita todavía ops/factory/backup, ops/factory/deploy y ops/factory/rollback ejecutables, probado el fail-closed de restauración y el alcance de decisión D-059. El presente artefacto no sustituye un **backup** sano ni el **rollback** real.
- No configurar DEPLOY_ENABLED, DEPLOY_TRANSPORT=hostinger-ssh, DOMAIN, SSH ni HOSTINGER_KNOWN_HOSTS hasta concesión y verificación del operador. No emitir credenciales en código, chats o logs.
- El smoke HTTPS del deploy y el observer de Factory@v1 todavía no resuelven autenticación **owner-only** para /health.php: no abrir el panel para que el probe devuelva 200. Se requiere seguridad fail-closed, evidencia exact-SHA y HEALTH real antes de activar.
- Este build empaqueta un **candidato mínimo no publicado**. La revisión de completitud de dependencias de runtime, adapters restantes, gates legales, despliegue y AC-04 de ControlBot #760 siguen pendientes del épico #625. CI verde no equivale a validación en producción.

Pruebas offline: python3 -m unittest discover -s tests -p test_factory_deploy_build_adapter.py -v. Reversión mediante revert de #888; el directorio var/ continúa excluido del repositorio.
