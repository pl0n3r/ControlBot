# Composición de admisión y proyección GitHub — únicamente OFFLINE

Estado: **OFFLINE / SIMULACIÓN**, alcance aprobado en ControlBot #1002/A y materializado en #1003. Esta hoja no convierte el Control Plane en un cliente real de GitHub, no instala una aplicación y no conecta cuentas.

## Cadena de confianza y contrato

1. `GitHubReadOnlyInstallationAdmission::inspect()` valida una instalación ficticia del dueño: `pl0n3r/ControlBot`, `metadata:read` + `issues:read`, `GET issues`, ID y revisión exactos, vigencia de la instalación y grant sintético.
2. `GitHubReadOnlyAdmissionProjectionGate::project()` compara el mismo owner, instalación, revisión, recurso `issues`, repositorio, SHA y reloj de los fixtures de origen, transporte y snapshot. Una fuente `pulls` jamás autoriza `issues`. El gate está deshabilitado por defecto.
3. Solo después de `OFFLINE_VALIDATED` el gate permite la invocación de la **clase ya existente** `GitHubReadOnlyProjectionPipeline` con dos callbacks de transporte ficticios y `GitHubReadOnlyProjectionWriter` escribiendo exclusivamente en un directorio temporal privado. No se introduce ningún sender de red.
4. Se combinan testigo de binding (instalación/revisión/manifest/tiempo) y testigo de proyección en una closure fail-closed entregada al pipeline. El writer comprueba el testigo antes de staging y una segunda vez antes de `rename` de `projection.json`.
5. Si la instalación/grant está revocada, vencida, sustituida, mal tipada o tiene otros permisos, o si falta un testigo o el manifest cambia, el resultado es `UNKNOWN`. La admisión inválida bloquea **antes** de los callbacks de lectura y de cualquier escritura. No se exponen grants, tokens ni correos en las respuestas.

## Frontera de atomicidad real

El writer actual hace su última lectura del witness y **después** ejecuta `rename`. El reemplazo del archivo es atómico, pero la verificación del estado externo y `rename` **NO son una transacción única**. Una revocación que suceda después del último witness aún podría preceder a `rename`. No se asegura seguridad ante esa carrera externa; requiere otro diseño con sincronización/commit bajo su propia autorización y claims antes de runtime. Los tests distinguen revocación previa, durante staging y esta ventana no garantizada.

`CURRENT` designa exclusivamente frescura de **fixture local** dentro del pipeline. El gate devuelve `OFFLINE_VALIDATED` y conserva siempre estas tres invariantes:

- `runtime_authorized=false`
- `execute_actions=false`
- `production_verified=false`

No constituye `VALIDATED_IN_PRODUCTION`. D-063 sigue limitado a fase `construccion`, `nothing_live=true`, `no_real_customer_data=true`. Las puertas #625, #760, #708/B y D-043/D-059 permanecen intactas.

## Pruebas y reversibilidad

Ejecutar `python3 -m unittest discover -s tests -p 'test_github_readonly_admission_projection_gate.py' -v` y `php -l src/GitHubReadOnlyAdmissionProjectionGate.php`; después exigir `Validar`, SonarCloud y CodeQL exact-HEAD. Los cuatro métodos `GitHubReadOnlyAdmissionProjectionGateTests` se corresponden con AC-01..04 de #1003. Los fixtures usan un directorio temporal descartable, sin redes ni datos reales; la lectura de callbacks está acotada. La prueba de carreras comprueba preservación de la proyección anterior. La entrega añade solo tres archivos y puede revertirse por squash revert.

Roles: ingeniería (composición reversible), QA (test positivo y negativos deterministas), seguridad (scopes mínimos y trust boundaries), legal-privacidad (solo metadatos falsos, sin finalidad/retención productiva ni terceros). No se toman decisiones jurídicas ni de go-live en esta hoja.
