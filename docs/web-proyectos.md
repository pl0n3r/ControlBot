# Proyectos web: conexión owner y evidencia privada (#1478)

## Alcance y autoridad

Las vistas HTML `GET /proyectos` y `GET /proyectos/{project_id}` requieren una sesión **OAuth+TOTP** verificada por `OwnerSessionService`. El `REMOTE_USER`, la cabecera `Authorization`, parámetros URL y el login local solo con contraseña **no** autorizan estas vistas. Las peticiones anónimas y sesiones con 2FA ausente/expirada reciben **403**. Solo se permite `GET` (otros métodos **405**).

El contrato hereda el modelo y renderer `ProjectUi` de #1447 sin modificar `ProjectRoutes.php`, `ProjectBackfill` ni las cuatro definiciones canónicas. El API owner `/api/v1/proyectos`, la vista HTML y el detalle usan `projects.read` **solo lectura**. Ningún request del navegador puede suministrar el contenido del adaptador.

## Fuente real, no inferida

El operador del servidor configura `CONTROLBOT_PROJECTS_SNAPSHOT_PATH` con una ruta absoluta a un **JSON de solo lectura y no público**, creada fuera del `DOCUMENT_ROOT` con permisos **0600**. No se entrega un snapshot de ejemplo en producción ni se añade uno al repositorio. El archivo no debe ser symlink; el lector comprueba ruta real, permisos, tamaño, identidad de archivo y estructura antes de aceptarlo. No lee `.env`, no conecta a base de datos ni llama redes externas.

Formato del adaptador privado:

```json
{
  "version": 1,
  "observed_at": 14000,
  "projects": [
    {
      "version": 1,
      "project_id": "project-controlbot",
      "slug": "controlbot",
      "title": "ControlBot",
      "phase": "building",
      "priority": "medium",
      "repositories": [
        {
          "repository_id": "repo-controlbot",
          "repository": "pl0n3r/ControlBot",
          "source_ref": "https://github.com/pl0n3r/ControlBot",
          "observed_at": 13999
        }
      ],
      "environments": [],
      "aggregate_refs": {
        "roadmap": null,
        "agents": null,
        "decisions": null,
        "health": null,
        "incidents": null,
        "costs": null
      },
      "history_refs": []
    }
  ]
}
```

**Los valores de arriba son únicamente una muestra estructural sintética e incompleta**, nunca datos desplegados. Un snapshot válido contiene las cuatro identidades `project-controlbot`, `project-condor`, `project-brvtal` y `project-grindflow` con repositorios congruentes `pl0n3r/ControlBot`, `pl0n3r/Condor`, `pl0n3r/brvtal` y `pl0n3r/GrindFlow`. No se añaden registros ficticios si falta alguno.

El archivo y cada referencia de repositorio, entorno o agregado **deben tener `observed_at` en la ventana de 300 segundos** respecto del servidor; fuentes ausentes, inválidas, incompletas o caducadas producen **503** sin exponer errores privados. Si no hay entornos observados, el detalle dice «Sin entornos»; si no hay fuente de salud, la vista marca **UNKNOWN**, nunca GREEN. Un ID desconocido devuelve **404**. No se fabrican observaciones, eventos ni estados productivos.

## Seguridad, QA y límites operativos

- HTML generado por `ProjectUi` con escape de entidades, `Cache-Control: no-store`, CSP, `nosniff`, `DENY` y política no-referrer. Nunca mostrar secretos ni el contenido íntegro del snapshot en una página de error.
- `tests/test_web_projects_http.py` prueba AC-01: cuatro proyectos con OAuth+TOTP, backend inyectado y archivo real privado, lista/detalle y solo lectura. AC-02: no-owner, no-MFA, expiración, login local, 405/404, fuente faltante/permiso público/symlink/STALE, repositorio alterado, escape XSS y salud UNKNOWN.
- `tests/test_web_projects.py` de #1447 sigue cubriendo el API JSON y el backfill idempotente; no es prueba de autorización HTML.
- La referencia documental se registra como fuente posterior al corte de 1.194 IDs, con estado `NO_VERIFICABLE / NO ENSAYADO`, no como certificación de producción.
- Merge de preparación **no** despliega, enciende live, usa datos reales, crea respaldos ni acredita D-043/D-059. Hostinger, identidad de producción, backup/restore y revisión jurídica conservan sus propias puertas. Reversión técnica: revert del squash.
