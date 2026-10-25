# BackupReceipt: SHA-256 y freshness offline (#898 / #182)

En esta hoja, el constructor mantiene compatibilidad con recibos históricos sintéticos de formato flexible. La **verificación de integridad** exige `algorithm=sha256` y exactamente 64 caracteres hexadecimales; los legacy digests jamás producen MATCH y retornan UNKNOWN/digest_invalid. `safeEvidence()` sigue omitiendo el digest y el contenido. Un endurecimiento del constructor y migración de fixtures requieren una hoja distinta con claims de tests históricos.

El método puro `verifyPayloadChecksumBefore(payload, scope, checkedAt)` verifica identidad exacta de proyecto/entorno/recurso/run/issue, causalidad, expiración y una antigüedad máxima de 24 horas desde la verificación nominal. Tras rechazar estructuralmente cualquier digest no canónico, calcula SHA-256 de los bytes recibidos **en memoria** y usa `hash_equals`. Solo retorna `MATCH|MISMATCH|UNKNOWN` y motivo enumerado; no emite digest, payload ni trazas sensibles.

**Límite de autoridad:** esta prueba compara entradas sintéticas locales y **no** autentica el recibo, su emisor, la copia externa, la inmutabilidad, la retención real, una restauración o la salud operacional. Los ocho criterios operativos pendientes del epic #182 siguen sin acreditarse. No hay IO, APIs de proveedor, secretos, PII, costes, datos reales ni go-live; #858/A no se amplía y la puerta #894 queda intacta.

Pruebas: `python -m unittest discover -s tests -p test_backup_integrity.py`, además de `tests/test_backup_gate.py` y la suite general CI. Para objetos grandes reales se requerirá un futuro adaptador streaming autorizado, con evidencia proveniente del proveedor y un restore drill auténtico. Revertir este squash no toca infraestructura, datos ni configuración.
