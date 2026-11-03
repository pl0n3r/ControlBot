# ControlBot #1364 — pruebas negativas de autenticación (offline)

## Alcance y contrato

Este slice ejecuta las funciones PHP de autenticación ya existentes con un usuario ficticio, contraseña sintética, IP reservada para documentación y reloj simulado. La prueba canónica es tests/test_auth_negative.py::AuthNegativeTests::test_criterio_principal. El identificador histórico solicitado en el issue es **docs/auth-negative-tests.md::Auth-negative-testsTests::test_criterio_principal** (Auth-negative-testsTests::test_criterio_principal); se conserva literalmente para trazabilidad, no se presenta como una clase Python válida ni como test ejecutable de Markdown.

**SPEC / matriz de denegaciones:** login sin sesión → 302 /login; POST sin CSRF y CSRF incorrecto → 403; contraseña falsa → 401; TOTP ausente, incorrecto, vencido o repetido → 401; rutas codificadas, traversal o doble separador → 403; sesión inactiva a partir de 900 segundos → 302; revocación global con contraseña válida invalida sesiones antiguas → 302; logout sin CSRF → 403. Se comprueba no-store en respuestas denegadas y la auditoría local para login, revocación y uso TOTP. El throttling IP mantiene el registro de intentos fallidos; los fallos de CSRF se evidencian mediante respuestas de prueba, sin afirmar que exista un evento de auditoría persistente para cada CSRF rechazado.

## Ejecución y evidencia

Ejecutar desde la raíz: python3 -m unittest -v tests/test_auth_negative.py o python3 -m unittest discover -s tests -p 'test_auth_negative.py' -v. Se necesita PHP CLI con Argon2id y libsodium. Cada escenario usa TemporaryDirectory distinto fuera del repositorio, no una sesión de usuario real. Se comprueba el contrato de CI Factory v1 / Validar únicamente sobre el HEAD exacto en GitHub; el resultado local no demuestra éxito de CI.

## Límites

No hay acceso a Hostinger, credenciales, cuentas, datos personales, despliegue, escritura productiva, habilitación de rutas ni tráfico real. El GET /health y los assets públicos siguen siendo excepciones explícitas del gate. El comportamiento en servidor real requiere otra decisión de live. Para revertir, revertir el squash del PR #1364 y verificar que desaparezcan únicamente esta documentación y tests/test_auth_negative.py.
