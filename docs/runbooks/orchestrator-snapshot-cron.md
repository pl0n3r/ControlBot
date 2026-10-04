# Refresco cron-ready del snapshot del Orquestador

Este runbook describe únicamente el **punto de integración offline** de `ORCH_SNAPSHOT_CRON_V1`. No instala una tarea programada, no configura Hostinger y no activa ControlBot en producción.

## Estado por defecto

`scripts/orchestrator-snapshot-cron.php` permanece apagado salvo que el servidor entregue explícitamente la habilitación esperada. En el estado por defecto termina con éxito y responde `{"executed":false,"state":"disabled"}` **antes de leer evidencia o escribir el snapshot**.

El wrapper es exclusivo de CLI. No se invoca desde `public/index.php`, no participa en requests web y no contiene cliente HTTP/GitHub.

## Entradas server-side

Cuando una activación separada sea autorizada, el entorno del proceso deberá entregar:

- la señal explícita de habilitación del wrapper;
- una ruta absoluta a un archivo de evidencia JSON ya recolectada y sanitizada;
- una ruta absoluta al snapshot local que consume el entrypoint.

Los **valores concretos** se administran fuera del repositorio. El archivo de evidencia debe provenir de un colector read-only separado y no debe contener credenciales ni material sensible.

El wrapper valida la evidencia y delega en `FactoryOrchestratorSnapshotRefresh`, que limita el snapshot a 2 MB y hace el reemplazo mediante archivo temporal + `rename()`.

## Credenciales y transporte GitHub

Este repositorio no define ni almacena el token del proveedor, su valor, el mecanismo de secret storage ni un cliente GitHub para este wrapper. Cualquier credencial necesaria por un colector futuro permanece en la configuración privada del servidor o del proveedor de ejecución y se entrega únicamente a ese colector externo.

El archivo de evidencia es el boundary de inyección. El wrapper no recibe encabezados de requests web, no usa `curl` y no llama APIs remotas.

## Programación y hosting

La frecuencia, zona horaria, comando real de scheduler y configuración del panel de hosting se definen fuera del repositorio durante una activación operativa separada. Este runbook no contiene una expresión de cron instalable ni modifica hPanel.

La preparación aquí solo garantiza que el comando es CLI-only, está apagado por defecto y puede consumir evidencia inyectada cuando exista una autorización posterior.

## Activación live

Esta hoja no cambia `DOMAIN`, `DEPLOY_ENABLED`, DNS, Basic Auth ni el estado de go-live. ControlBot #625 conserva la autoridad de producción y su evidencia terminal sigue siendo obligatoria antes de declarar el sistema validado en producción.

## Reversión

Si una futura activación detecta errores, se deshabilita la señal server-side del wrapper. Sin esa señal, el comando vuelve al estado `disabled` y no lee ni escribe archivos. El snapshot anterior permanece gobernado por el fail-closed del consumidor.
