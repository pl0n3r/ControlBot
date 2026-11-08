# Proyectos web owner-only — ControlBot #1478

## Rutas y autorización
`GET /proyectos` y `GET /proyectos/{project_id}` sirven HTML solo tras `OwnerSessionService` con **OAuth+TOTP** verificado. `REMOTE_USER`, `Authorization`, sesión local de contraseña y entradas de cliente no otorgan permisos. Anónimo, TOTP ausente/expirado: **403**. Método distinto de GET: **405**; identificador inexistente: **404**. Renderer `ProjectUi` y handlers `ProjectRoutes.php` de #1447 se reutilizan sin modificaciones. `projects.read` es exclusivamente de lectura; no existe mutación ni backfill en las vistas.

## Origen privado y validación
El servidor debe configurar `CONTROLBOT_PROJECTS_SNAPSHOT_PATH` a un JSON **absoluto fuera de DOCUMENT_ROOT**, sin symlinks, con permisos **0600**. No se incluye snapshot productivo en el repositorio ni se aceptan rutas, contenidos o autenticación desde el navegador. El lector comprueba ruta real, identidad de archivo, tamaño máximo 256 KiB, JSON con claves exactas y `observed_at` dentro de **300 segundos**. También exige observaciones frescas en repositorios, entornos y referencias agregadas.

Formato: `{"version":1,"observed_at":14000,"projects":[...]}`. Los registros del array son los DTO `ProjectModel` de #1447, con `version,project_id,slug,title,phase,priority,repositories,environments,aggregate_refs,history_refs`. Se exigen exactamente cuatro identidades con su repositorio canónico: `project-controlbot` → `pl0n3r/ControlBot`, `project-condor` → `pl0n3r/Condor`, `project-brvtal` → `pl0n3r/brvtal` y `project-grindflow` → `pl0n3r/GrindFlow`. Son **IDs de contrato**, no un origen ficticio de datos. Una fuente incompleta, caducada o incoherente devuelve **503**; nunca se inventan proyectos, entornos, informes, estados de salud ni readiness.

## Respuestas, seguridad y QA
HTML semántico, responsive y con escape de entidades de `ProjectUi`; `Cache-Control:no-store`, CSP sin scripts, `nosniff`, `X-Frame-Options:DENY` y `Referrer-Policy:no-referrer`. Sin entornos acreditados se muestra «Sin entornos» y sin referencia de salud aparece **UNKNOWN**, nunca GREEN. La lectura privada no se filtra en páginas de error.

`tests/test_web_projects_http.py`: AC-01 lista/detalle de cuatro proyectos con sesión OAuth+TOTP, backend sintético y snapshot de archivo temporal privado 0600. AC-02 casos negativos de sesión/TOTP, expiración, login local, REMOTE_USER forjado, métodos, ID, fuente ausente, symlink, archivo expuesto al DOCUMENT_ROOT, permisos 0644, vencimiento, repositorio falso, XSS y UNKNOWN. `tests/test_web_projects.py` conserva los controles JSON/backfill anteriores. El documento nuevo se registra en `docs/matriz-cobertura.md` y `tests/test_matriz_cobertura.py` como `NO_VERIFICABLE / NO ENSAYADO`, sin alterar los 1.194 requisitos históricos.

## Fronteras
Cambio de **construcción offline**: sin datos reales, secretos, proveedores, tráfico de producción, migraciones, backups, restauraciones, deploy, publicación ni go-live. No acredita D-043/D-059, revisión jurídica, `VALIDATED_IN_PRODUCTION` ni restore verificado. Reversión: revert del squash.
