# Productor de proyección GitHub, OFF por defecto

Esta pieza corresponde a ControlBot #934 y autorización específica #932/A. Es un módulo PHP offline, sin integración de cron, web, red, GitHub App, tokens ni ejecución de acciones. Reutiliza los contratos GitHubProjectSnapshot v1 y GitHubProjectView, pero recibe snapshots ya construidos por pruebas y nunca los consulta en GitHub.

GitHubReadOnlyProjectionWriter permanece OFF por defecto. Incluso en pruebas habilitadas, write(snapshots, expected, fixtureDirectory, now, maxAgeSeconds, witness) solo acepta un directorio inmediato bajo sys_get_temp_dir() con prefijo cb-projection-fixture- y testigo inyectado que confirma dos veces un manifiesto idéntico de proyecto, repositorio, SHA y reloj. La proyección JSON version=1/projects se escribe mediante archivo staged privado y rename() atómico al projection.json temporal, nunca al var/github-readonly.json real.

Se rechazan datos parciales o truncados, timestamps futuros o caducados, duplicados de proyecto/repositorio, fuentes incoherentes, SHA inválido, cardinalidad/tamaño excesivos, valores con indicios de PII o tokens y carreras del testigo. Una denegación no altera la versión anterior ni revela datos en el motivo. Manifiesto, reloj y directorio son fixtures de pruebas, no credenciales ni evidencia productiva.

El retorno preserva runtime_authorized=false, production_verified=false y execute_actions=false. Un CURRENT local no es GREEN productivo ni prueba D-043/D-059. No existe transporte HTTP, acceso GitHub real, secretos, PII ni despliegue. Integrar un productor de red o escribir el path de runtime exige hoja y autorización independientes.
