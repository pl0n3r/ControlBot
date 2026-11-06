# ControlBot · módulo legal (ControlBot #1429)

## Contrato y límite de autoridad
Vista privada `GET /legal` integrada al shell ControlBot, solo después de la autenticación real del dueño. La rama OAuth+TOTP verifica `OwnerAuthenticatedRouter::verifiedOwner` **en `public/index.php`**; la rama de contraseña local valida `OwnerLoginGate::requireOwner` antes de atender la ruta. El bootstrap público **deniega** `/legal` incluso si el cliente envía `REMOTE_USER`. Otros métodos obtienen `405`. `Cache-Control: no-store`, `X-Frame-Options: DENY`.

La vista **no redacta, legaliza, aprueba, publica ni altera documentos**, repos, credenciales o datos reales. Consume un JSON de proyección confiable, preparado fuera de HTTP, desde el fichero local **opcional** `CONTROLBOT_LEGAL_SNAPSHOT_PATH`, ruta absoluta configurada solo por el servidor, fuera de `public/`. La ruta o el contenido no se aceptan de parámetros de petición. Un fichero ausente, inválido, vencido/no autenticado por el productor o un productor todavía no implementado **no constituye evidencia**: mostrar los cinco proyectos en `UNKNOWN`, no declarar un estado de cumplimiento. El productor debe validar previamente autenticidad, permiso read-only y frescura de sus fuentes; este slice no configura credenciales ni adaptadores de otros repos.

### JSON de evidencia (ejemplo sintético, no aprobación real)

```json
{
  "version": 1,
  "products": [
    {
      "product": "Condor",
      "documents": [
        {
          "path": "docs/privacidad/politica.md",
          "source_ref": "https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/politica.md",
          "review_state": "draft",
          "approval": null
        }
      ],
      "controller": {
        "name": "[COMPLETAR POR EL DUEÑO]",
        "identifier": "[COMPLETAR POR EL DUEÑO]",
        "address": "[COMPLETAR POR EL DUEÑO]",
        "rights_email": "[COMPLETAR POR EL DUEÑO]"
      },
      "gates": [{"issue_ref": "https://github.com/pl0n3r/Condor/issues/974", "open": true}]
    }
  ]
}
```

Proyectos admitidos: Condor, GrindFlow, BRVTAL (repositorio `brvtal`), ControlBot y AutoFactory. Las rutas de documento deben apuntar a `docs/privacidad/` o `revision-juridica/` del **mismo repositorio**. Los campos `controller` se convierten exclusivamente en los nombres de los campos pendientes; jamás se muestran nombres, números, direcciones ni emails reales. Las puertas jurídicas abiertas son URLs exactas del issue respectivo, sin escrituras GitHub. Fuentes ausentes son `UNKNOWN`.

### Estados
- `draft` → `BORRADOR`; `in_review` → `EN REVISIÓN`, ambos muestran «BORRADOR pendiente de aprobación».
- `approved` **sin** prueba completa → `BORRADOR`, nunca aprobado por inferencia.
- `approved` con `approval.by`, `approval.at` (UTC ISO 8601) y `approval.evidence_ref` como comentario de aprobación del mismo repo → `APROBADO` con quién/cuándo/enlace. **El productor confiable debe autenticar previamente el origen/autoridad real de ese journal; un enlace con sintaxis correcta no prueba por sí mismo aprobación jurídica.** Por seguridad no se debe publicar un snapshot aprobado hasta la verificación jurídica efectiva.

### Seguridad, regresiones y despliegue
Sin formularios ni POST; salida HTML escapada, cabeceras no-store y cinco tarjetas adaptables. `tests/test_legal_status_panel.py` cubre AC-01..03 con fixtures sintéticos, estados faltantes, rechazo de rutas cruzadas y accesos anónimos/pre-TOTP/caducados por el bootstrap real. AC-04 exige CI `Validar` exact-HEAD y gates D-069/CodeRabbit/CodeQL/Sonar antes del squash. No habilitar fuentes productivas/PII sin aprobación legal del responsable. Reversión por revert del squash de #1429.
