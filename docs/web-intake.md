# ControlBot #1448 — intake de ideas (preview, sin efectos externos)

## Estado y autoridad

La interfaz del registro owner-v1 admite ideas sin repositorio asignado.
Solo se invoca mediante ControlBotWebEntrypoint::handleAuthenticated
con sesión OAuth/TOTP verificada y rutas /api/v1/intake.
Sin sesión del dueño o con CSRF inválido en POST, responde 403. Una fuente
de almacenamiento no configurada responde 503. Las respuestas son JSON
con Cache-Control: no-store, sin autoridad de cabeceras HTTP ni REMOTE_USER.

**Límite comprobado:** el acceso literal a una página HTML /intake **no**
está cableado en el front controller. El archivo imprescindible
src/ControlBotWebEntrypoint.php está reclamado por otra reserva V3
(ControlBot #1429), y NO se invadió. Este módulo aporta el contrato
seguro del formulario y la decisión, pero **no equivale a la pantalla
web de aceptación integral**. La integración HTML requiere liberar
ese claim y ampliar la reserva antes de modificar el front controller.
No afirmar AC-01 completo hasta que la URL y su vista real estén probadas.

## Endpoints del módulo

| Ruta owner-v1 | Acción |
| --- | --- |
| GET /api/v1/intake | Devuelve esquema del formulario (content), listado de ideas y enlace a decisiones |
| POST /api/v1/intake | Normaliza texto y redacta secretos; propone match y registra borrador en backend confiable |
| GET /api/v1/intake/{intake_id}/decision | Vista y diff de decisión canónicos, sin ejecución |
| POST /api/v1/intake/{intake_id}/decision | Selección de proyecto new/existing, proyección preview_only |

POST idea: JSON objeto con exactamente una clave 'content', con texto
estructurado por Problem, User y Objective; se aceptan ideas libres.
El servidor asigna source_ref, captured_at e identificador idea-<40 hex>.
El cliente no aporta esos metadatos.
POST decisión: JSON objeto con exactamente 'project_mode' y 'project_ref'.
En modo new, project_ref es null; en existing debe ser el project_ref
idéntico al match independiente calculado con candidatos del servidor.
Una propuesta ambigua o un proyecto diferente se rechazan.

## Backend y límites

El adaptador $ownerApiBackend está ligado al servidor, nunca al payload:
- intake.candidates: devolver {projects:[...]} con catálogo de fuente
  confiable y de solo lectura;
- intake.record: persistir {intake_id,draft,candidates} saneados y
  confirmar {stored:true,intake_id:<id>};
- intake.list: entregar {entries:[{intake_id:<id>},...]} (máximo 50);
- intake.read: recuperar {draft,candidates} por ID.

Una fuente faltante, incompatible o sin recibo válido falla cerrado; no
se inventa persistencia. RequirementIntake y RequirementIntakeDecisionUi
validan integridad, fuente, matching, redacción y decisiones. El módulo
NO invoca RequirementMaterializer, GitHub, Hostinger, red, ni datos reales.
Todas las decisiones llevan execution=false; las elecciones incorporan
committed=false, repositories_created=0 e issues_created=0. No hay
creación real de proyectos, repositorios, epics o issues.

## Verificación y reversión

Pruebas: python3 -m unittest -v tests.test_web_intake
Syntax: php -l src/Routing/Routes/IntakeRoutes.php

La prueba nominal registra una idea, recupera el índice y previsualiza
proyecto nuevo y enlace existente; la negativa cubre sesión, CSRF,
payload corrupto, campos extra, ID forjado, proyecto incompatible, backend
ausente y secretos saneados. Los tests emplean exclusivamente un backend
sintético en memoria, sin efecto persistente o externo. Revertir el squash
elimina estos tres archivos; no afecta datos de producción ni despliegues.
