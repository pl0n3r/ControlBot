# ControlBot #1448 — intake de ideas (preview, sin efectos externos)

## Estado y autoridad

La interfaz del registro owner-v1 admite ideas sin repositorio asignado.
Solo se invoca mediante ControlBotWebEntrypoint::handleAuthenticated
con sesión OAuth/TOTP verificada y rutas /api/v1/intake.
Sin sesión del dueño o con CSRF inválido en POST, responde 403. Una fuente
de almacenamiento no configurada responde 503. Las respuestas son JSON
con Cache-Control: no-store, sin autoridad de cabeceras HTTP ni REMOTE_USER.

**Vista HTML preparada dentro del claim:** 'IntakeRoutes.php' aporta
'controlbot_intake_html_page($csrf, $server)', renderer GET aislado para
'/intake': formulario para registrar idea, índice de pendientes y selector de
proyecto existente o nuevo, con vista previa sin efectos. CSP nonce, CSRF
recibido exclusivamente de 'OwnerSessionService', sin HTML dinámico sin
escapar (DOM 'textContent'), no-store y prohibición de iframe/form externo.
La función NO confiere autorización por sí misma; el front controller debe
invocarla solo después de verificar OAuth+TOTP. La API sigue exigiendo
autenticación y CSRF independientemente, y no hay creación de repo/Issue.

**Estado de integración (7-nov-2026):** el acceso HTML literal
`GET /intake` aún no está enlazado a `ControlBotWebEntrypoint`.
#1449 / PR #1495 están **fusionados** y su V3 quedó liberada.
Ahora `src/ControlBotWebEntrypoint.php` está reclamado por la V3 activa
de #1478 / PR #1499 (UUID `a071d3d4-7920-5770-8b3e-791fdcdfd881`);
**no pertenece a los tres claims propios de #1448**. Este módulo presenta
API y renderer aislado; eso **no acredita la ruta HTML real ni AC-01 completo**.

El PR anterior #1467 fue **cerrado sin fusión** con autorización exclusiva
del OWNER en #1496/A (journal BOT v2/A). El PR sucesor #1500 restaura
exclusivamente los tres archivos bajo reserva V3 propia
`47de566e-172b-4133-8772-c45be64758fc`; seguirá DRAFT
mientras exista la colisión. Tras fusionar #1478 y liberarse esa reserva,
renovar el contrato y V3 de #1448 para reclamar el controlador, enlazar
`controlbot_intake_html_page($ownerAuth->csrfToken($ownerSession), $server)`
**solo** en `handleAuthenticated` después de verificar OAuth+TOTP;
probar owner GET 200, no-owner 403, POST no permitido y que
`handleLocalOwner` no confiera acceso. Conservar CSP, `no-store`
y preview sin crear repos ni issues.

La petición manual CodeRabbit de #1467 fue consumida una vez en SHA anterior
y **no se repite** en ese PR. #1496/A autoriza una única revisión FINAL
**del sucesor #1500** cuando HEAD definitivo, `Validar`,
SonarCloud y CodeQL estén completos en verde; antes no solicitarla.

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


## Verificación de riesgos en HEAD sucesor (8-nov-2026)

El seguimiento de CodeRabbit sobre el HEAD inicial del PR #1500 identificó
respuestas tardías de vista previa, discrepancias entre el límite UTF-8
del formulario y el backend, y fallos del adaptador confiable reportados como
400 de cliente. Se añadieron invalidaciones por selección y modo, validación
de 8000 bytes UTF-8 en navegador y 503 para contratos backend incorrectos;
las entradas cliente inválidas siguen siendo 400. Las pruebas propias incluyen
casos negativos explícitos. Los datos se simulan en memoria, sin acciones externas.

**Dependencia nueva verificada:** #1478 / PR #1499 se fusionó,
pero #1451 / PR #1503 reclama actualmente
`src/ControlBotWebEntrypoint.php` con reserva V3 vigente. Aún falta
registrar la ruta literal `GET /intake` bajo `handleAuthenticated` después
de OAuth+TOTP. No se editó el controlador ni se amplió el contrato durante
esta preparación: debe fusionarse #1451, liberarse su claim y renovar la V3
de #1448 antes de integrarlo. Un renderer aislado no satisface AC-01.

La revisión CodeRabbit del PR sucesor #1500 ya fue solicitada una vez sobre
un HEAD anterior. No repetir `@coderabbitai review` en este PR en commits
intermedios; un check omitido no es evidencia exact-HEAD de seguridad.
