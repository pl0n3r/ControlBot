# Proyectos del dueño — ControlBot #1447

**Fase:** construccion; solo lectura, fixtures sinteticos. El registro de OwnerAuthenticatedRouter de #1445 exige OAuth+TOTP y fuente servidor. No autoriza por REMOTE_USER, Authorization, cookies arbitrarias ni datos reales.

## Rutas del modulo y contrato

src/Routing/Routes/ProjectRoutes.php registra tres rutas GET autenticadas:

- /api/v1/proyectos: JSON con mode=read_only, persisted=false, path=/proyectos, proyectos normalizados y HTML de ProjectUi::renderList.
- /api/v1/proyectos/{project_id}: vista de ProjectUi::renderDetail con repositorios, entornos y referencias agregadas; un ID inexistente devuelve found=false sin fabricar registros. El router generico actual devuelve HTTP 200 para un handler valido, incluso si found=false.
- /api/v1/proyectos/backfill: preview de ProjectBackfill::apply, sin almacenamiento, workflows, cambios de ambiente, ni llamadas de red. Ejecutar el mismo preview dos veces no cambia los registros.

El backend es una funcion confiable y solo ofrece la operacion projects.read, que devuelve un objeto con version=1 y projects como lista. Ausencia de fuente, tipo invalido, proyectos duplicados o repositorio asignado a multiples proyectos producen HTTP 503. El router impone Cache-Control: no-store y X-Frame-Options: DENY.

## Cuatro proyectos y procedencia

Las definiciones canonicas son:

- project-controlbot — pl0n3r/ControlBot
- project-condor — pl0n3r/Condor
- project-brvtal — pl0n3r/brvtal
- project-grindflow — pl0n3r/GrindFlow

El backfill agrega en modo preview los registros ausentes, pero **no inventa entornos ni HEALTH**: solo un backend autentico puede aportar las relaciones de entorno y referencias agregadas. Las tarjetas de ProjectUi muestran cantidades, el detalle muestra repositorios/entornos y referencias; los agregados ausentes permanecen UNKNOWN. Los valores se escapan como HTML.

## Limite explicito del cableado web

Los campos path=/proyectos y path=/proyectos/{id} indican la intencion de URL web, **no rutas HTML conectadas en este PR**. OwnerAuthenticatedRouter solamente admite /api/v1/* y ControlBotWebEntrypoint actualmente no sirve /proyectos. Las tres rutas reclamadas por #1447 NO incluyen el front controller. Para exponer las URL HTML de AC-01 hace falta un issue/lease V3 disjunto para el cableado, despues de los cambios solapados de #1449/#1452. No atribuir servicio web HTTPS de las URL publicas ni pretender que JSON equivale a una pagina ya enrutada.

## Pruebas y negativos

Ejecutar: python3 -m unittest -v tests/test_web_projects.py

- ProjectsTests.test_criterio_principal: cuatro proyectos, repositorios y entornos sinteticos del fixture, vista HTML escapada, salud ausente, orden estable, entrada owner autenticada, backfill duplicado identico.
- ProjectsTests.test_criterio_seguridad_y_bordes: sin sesion, sesion caducada, verbos indebidos, path codificado, sin backend, origen malformado, proyectos duplicados, colision de repositorio, proyecto ausente y entornos ausentes. Snapshot de entrada intacto y llamadas solo a projects.read.
- El fixture nunca usa credenciales reales, migraciones ni IO externo. La presencia del archivo de pruebas no demuestra su ejecucion: se requiere CI Factory v1 / Validar y Validar en success terminal sobre HEAD exacto.

Fuera de alcance: despliegue, datos de clientes, proveedores, gastos, secrets, aprobaciones del owner, live o Factory@v1. Reversión: revertir los tres archivos del squash.
