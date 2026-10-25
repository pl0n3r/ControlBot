# BackupReceipt: SHA-256 y freshness offline (#898 / #182)

El constructor `BackupReceipt::fromRecord` exige `algorithm=sha256` y un digest de **exactamente 64 caracteres hexadecimales** (sin límite de formato heredado). Los digests cortos, largos y no hexadecimales se rechazan con `InvalidArgumentException` antes de admitir el recibo. El fixture sintético histórico de `tests/recovery_evidence_scenarios.php` también utiliza SHA-256 canónico. `safeEvidence()` sigue omitiendo el digest y el contenido.

El método puro `verifyPayloadChecksumBefore(payload, scope, checkedAt)` verifica identidad exacta de proyecto/entorno/recurso/run/issue, causalidad, expiración y una antigüedad máxima de 24 horas desde la verificación nominal. Tras rechazar estructuralmente cualquier digest no canónico, calcula SHA-256 de los bytes recibidos **en memoria** y usa `hash_equals`. Solo retorna `MATCH|MISMATCH|UNKNOWN` y motivo enumerado; no emite digest, payload ni trazas sensibles.

**Límite de autoridad:** esta prueba compara entradas sintéticas locales y **no** autentica el recibo, su emisor, la copia externa, la inmutabilidad, la retención real, una restauración o la salud operacional. Los ocho criterios operativos pendientes del epic #182 siguen sin acreditarse. No hay IO, APIs de proveedor, secretos, PII, costes, datos reales ni go-live; #858/A no se amplía y la puerta #894 queda intacta.

Pruebas: `python -m unittest discover -s tests -p test_backup_integrity.py`, además de `tests/test_backup_gate.py` y la suite general CI. Para objetos grandes reales se requerirá un futuro adaptador streaming autorizado, con evidencia proveniente del proveedor y un restore drill auténtico. Revertir este squash no toca infraestructura, datos ni configuración.

## Guardia adicional de escritura protegida (#900)

`BackupGate::evaluate` utiliza `BackupReceipt::isUsableForProtectedWrite`: requiere
una expiración explícita y que `verified_at` preceda a la escritura en **no
más de 24 horas** (límite incluido), además de las comprobaciones de scope,
tipo y causalidad existentes. Un recibo sin `expires_at`, vencido o stale
falla cerrado con `backup_not_usable_for_write`, incluso si su campo
`status` dice `ready`. Los recibos sin expiración siguen siendo parseables
para inspección sin permiso de escritura.

El texto histórico `backup_verified` del resultado permitido se conserva
por compatibilidad con consumidores existentes: **solo describe la validación
estructural local del recibo, NO certifica proveedor, bytes, offsite, backup
restaurable ni HEALTHY**. Antes de operaciones reales faltan evidencia
auténtica de copia, verificación de contenido/proveedor, autoridad D-059 y
restore drill. Las pruebas sintéticas `test_backup_gate.py` cubren ausencia
de expiry, 24h exactas, 24h+1s y guardas previas sin red ni datos reales.
Revertir este parche restaura el contrato anterior sin tocar infraestructura.
