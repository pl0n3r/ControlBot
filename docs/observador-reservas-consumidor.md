# Observador de reservas locales — Fase A (ControlBot)

Este caller corresponde al despliegue distribuido aprobado en Factory #1382. Usa exactamente el reusable público `pl0n3r/Factory/.github/workflows/observador-reservas-consumidor.yml@v1`, con `GITHUB_TOKEN` del **repositorio ControlBot** y permisos únicamente de lectura. No existe token compartido entre repositorios ni código del recolector duplicado.

El cron de GitHub Actions solicita observaciones GET-only cada diez minutos y permite `workflow_dispatch` para diagnóstico. La exclusión concurrente serializa los runs; el reusable mantiene `FACTORY_LEASE_AUTO_RELEASE_ENABLED='false'`. Las lecturas incompletas, cuota baja, cambio de HEAD y señales UNKNOWN son evidencia insuficiente y **no autorizan liberar**. Los resúmenes y artefactos del run (retención siete días) son el único destino del manifiesto; no hay comentarios automáticos ni cambios en Issues, PR, reservas o ramas.

El éxito de un job no significa que se haya acreditado actividad/inactividad, GREEN, producción o Fase B. Antes de proponer liberación se exigen **24 horas**, **100 ciclos terminales y válidos** de cada repositorio y revisión individual QA de candidatos `release`, cero falsos positivos, evidencias CAS/lock y prueba negativa de actividad. `UNKNOWN`, `deferred_low_quota` y un inventario vacío no cuentan como aceptación. La activación sigue **OFF** y requeriría una hoja/PR aparte y los gates de Factory.

Reversión: revertir el PR que añadió `.github/workflows/observador-reservas-consumidor.yml` (con su test y este runbook) sin modificar `Factory@v1`, secretos, cron de despliegue, panel, Base de Datos ni Hostinger. La incorporación de este caller no modifica el go-live ni las decisiones jurídicas.
