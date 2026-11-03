# ControlBot #1371 — informe de simulacro DR local (RPO/RTO)

## Alcance y autoridad

**SOLO DATOS SINTÉTICOS. NO SE HA ENSAYADO LA RECUPERACIÓN REAL, EL SERVIDOR, HOSTINGER, LOS RESPALDOS OPERATIVOS NI EL LIVE.** El informe documenta la ejecución local descartable; no cambia la autoridad de recuperación real descrita en `docs/dr-drill-plan.md` ni en `docs/disaster-recovery-drill.md`.

La cadena pedida históricamente por el issue, `docs/dr-drill-report.md::Dr-drill-reportTests::test_criterio_principal`, es una referencia documental literal, no una clase Python válida. El test ejecutable equivalente es `tests/test_dr_drill_rpo_rto.py::DrDrillRpoRtoTests::test_criterio_principal`.

## Objetivos y criterios

| Indicador | Objetivo acordado | Observación del simulacro |
| --- | --- | --- |
| RPO (intervalo de datos potencialmente perdidos) | `[COMPLETAR POR EL DUEÑO]` | Segundos entre hora UTC de última copia íntegra y hora UTC de fallo simulado |
| RTO (tiempo desde el fallo hasta restauración verificada) | `[COMPLETAR POR EL DUEÑO]` | Segundos medidos con reloj monotónico durante la restauración y verificación de integridad |

El RPO también se comprueba mediante pérdida intencionada de **un registro ficticio** creado tras la última copia. Las métricas no se comparan contra objetivos no aprobados y no acreditan un SLA. El plan anterior de recuperación incluye propuestas no aprobadas; **no** deben adoptarse automáticamente como objetivos aquí.

## Ejecución local reproducible

Desde la raíz del repositorio, con Python 3 (stdlib solamente):

```bash
python3 -m unittest -v tests/test_dr_drill_rpo_rto.py
python3 -m unittest discover -s tests -p 'test_dr_drill_rpo_rto.py' -v
```

El test prepara en `TemporaryDirectory` dos eventos sintéticos ficticios, toma una copia local con SHA-256 y UTC, registra un tercer evento después de esa copia, simula la pérdida del archivo de trabajo, valida la huella de la copia, restaura un archivo de pruebas y mide los dos intervalos. Verifica huella SHA-256 restaurada, cantidad de registros recuperados y uno perdido. Una copia modificada falla cerrada antes de restaurar. El directorio y los archivos de prueba se eliminan al terminar. No hay red, secretos, bases de datos, datos reales, proveedores, jobs, DNS ni acciones productivas.

La prueba se descubrirá por el runner de pruebas de ControlBot `scripts/generate_coverage.py` al ejecutar las suites de Python en CI. El éxito del comando local **no acredita** CI: para #1371 se requiere además comprobar `CI Factory v1 / Validar` terminal `success` sobre el HEAD exacto del PR.

## Informe medido (ejecución local)

Ejecución local real con Python 3 / fixtures de este cambio, **no** CI ni entorno real:

```json
{
  "scope": "disposable_synthetic_local_only",
  "status": "PASSED_SYNTHETIC_ONLY",
  "backup_at_utc": "2026-10-31T16:07:45.686897Z",
  "failure_at_utc": "2026-10-31T16:07:45.727864Z",
  "restored_at_utc": "2026-10-31T16:07:45.728453Z",
  "rpo_observed_seconds": 0.040967,
  "rto_observed_seconds": 0.000552,
  "rpo_objective": "[COMPLETAR POR EL DUEÑO]",
  "rto_objective": "[COMPLETAR POR EL DUEÑO]",
  "events_at_last_backup": 2,
  "events_at_failure": 3,
  "events_recovered": 2,
  "events_lost_in_simulation": 1,
  "backup_sha256": "93be39bc9896758d9cf7c99bfbaf9545748312030c12786e732992a3bca468d0",
  "restored_sha256": "93be39bc9896758d9cf7c99bfbaf9545748312030c12786e732992a3bca468d0",
  "integrity_verified": true,
  "real_environment_verified": false
}
```

Los valores son observaciones en este contenedor y variarán al repetir la prueba. No se extrapolan a producción, ni sustituyen la medición independiente requerida en live.

## Límites y siguiente validación

El paso a una recuperación real depende de la autorización explícita del propietario, datos y cuentas permitidos, copia genuina, entorno descartable bajo control operativo, comprobaciones independientes y aceptación de objetivos por el dueño. No publicar una simulación como respaldo de producción, recuperación garantizada, certificación de HEALTH o autorización para restauraciones irreversibles.
