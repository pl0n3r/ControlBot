# Instalación GitHub read-only: admisión sintética

## Autoridad y alcance

Aprobado únicamente como preparación offline por el dueño en ControlBot #997 (/decidir A y recibo bot factory-human-decision v2/A). Este módulo implementa un preflight puro que nunca habilita un cliente, invoca HTTP, emite tokens o instala una GitHub App. No altera la decisión #708/B ni cumple los seis criterios operativos de #55.

## Contrato de prueba

GitHubReadOnlyInstallationAdmission::inspect($fixture, $grant, $request, $now) acepta exclusivamente estructuras sintéticas con identidad pl0n3r/ControlBot, instalación positiva, OWNER exacto, revisión positiva y ventana de validez de hasta cinco minutos. El grant de prueba es del servidor y verifica dueño, instalación, revisión, repositorio y tiempo. La solicitud es exclusivamente GET de issues o pulls. Los permisos requieren solo metadata:read y el permiso issues:read o pull_requests:read correspondiente; cualquier scope adicional, write, admin o unknown queda denegado.

El estado de prueba OFFLINE_VALIDATED no representa autorización real: las banderas runtime_authorized, production_verified y execute_actions son false, y authority es unchanged. Un rechazo devuelve UNKNOWN con motivo genérico y sin ids/PII, logs de tokens o proyección real; la auditoría se limita a 16 pares de estado/motivo. No existe sender ni API de expedición de credenciales.

## Amenazas y regresiones

| Riesgo | Mitigación | Prueba |
| --- | --- | --- |
| Suplantar owner, instalación o repositorio | Igualdad estricta entre fixture, grant y solicitud | AC-01 |
| Revocación, expiración o sustitución de versión | Rechazo antes de cualquier transporte; sin transporte definido | AC-02 |
| Scope mutante/extra, acción POST o dueño sin verificar | Lista cerrada, GET exacto, test-only, owner verificado | AC-03 |
| Confundir fixture con autorización productiva | Flags invariantes y sin HTTP, secretos ni persistencia | AC-04 |

Verificación: php -l src/GitHubReadOnlyInstallationAdmission.php; python3 -m unittest tests.test_github_readonly_installation_admission. Las pruebas invocan PHP CLI sobre datos inertes, sin red y sin secretos.

Reversión: revertir el squash de #998 elimina los tres archivos y no afecta al runtime, datos ni despliegue. No pasar datos.yml a live, no configurar Hostinger, DNS, tokens o B2. Go-live, backup real, restore, rollback y consentimiento siguen bajo #625/#760 y D-043/D-059.
