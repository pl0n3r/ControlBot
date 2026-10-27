# ControlBot #964 · Guion de carga de más de 400 señales

**Estado: PROPUESTA / NO ENSAYADO en producción.** Esta preparación offline no constituye ejecución en el servidor, atestación de health ni permiso de go-live. Fuentes: [#964](https://github.com/pl0n3r/ControlBot/issues/964), [#760 AC-04](https://github.com/pl0n3r/ControlBot/issues/760), [#625](https://github.com/pl0n3r/ControlBot/issues/625).

## Alcance, autoridad y fuentes

- El escenario **over-400** ya existente recorre un colector sintético e inspecciona truncamiento y conteos omitidos. La prueba actual cuenta 560 señales, no 450. Fuentes: [fixture PHP](../tests/factory_orchestrator_evidence_collector_scenarios.php), [prueba de regresión](../tests/test_factory_orchestrator_evidence_collector.py).
- El contrato a observar es **collector → cron → snapshot → UI**, con freshness, stale y último éxito; el manejo de fallos no convierte un snapshot antiguo en actual. Fuentes: [runbook del cron](runbooks/orchestrator-snapshot-cron.md), [pruebas de refresco](../tests/test_factory_orchestrator_snapshot_refresh.py), [#760](https://github.com/pl0n3r/ControlBot/issues/760).
- La prueba operativa está **DEL_DUEÑO**. Un fixture o Validar verde nunca acreditan AC-04 ni D-043. Fuentes: [docs/lista-salida-produccion.md](lista-salida-produccion.md), [#760](https://github.com/pl0n3r/ControlBot/issues/760), [#625](https://github.com/pl0n3r/ControlBot/issues/625).

## Generación local de 450 señales sintéticas

**PROPUESTA: NO ENSAYADO en servidor.** Desde la raíz del repositorio, ejecutar el bloque siguiente en una máquina de pruebas: no hay datos reales, sin credenciales, sin red y sin operaciones de infraestructura. Solo crea un archivo temporal con 450 identificadores ficticios y lo elimina. **No es un payload compatible acreditado del collector ni debe enviarse a un endpoint real.** Fuente de alcance: [#964](https://github.com/pl0n3r/ControlBot/issues/964).

~~~bash
set -eu
fixture="$(mktemp /tmp/controlbot-450-XXXXXX)"
chmod 600 "$fixture"
python3 - "$fixture" <<'PY'
import json
import sys
from pathlib import Path

signals = [
    {"synthetic": True, "id": f"sim-{n:03d}", "kind": "blocker" if n % 2 else "work", "state": "pending"}
    for n in range(1, 451)
]
assert len(signals) == 450
assert len({record["id"] for record in signals}) == 450
Path(sys.argv[1]).write_text(
    json.dumps({"fixture_only": True, "signals": signals}, ensure_ascii=False),
    encoding="utf-8",
)
print("PROPUESTA: 450 señales ficticias; no hay evidencia de producción")
PY
python3 - "$fixture" <<'PY'
import json
import sys
from pathlib import Path
data = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
assert data["fixture_only"] is True
assert len(data["signals"]) == 450
print("NO ENSAYADO: catálogo sintético local validado")
PY
rm -f -- "$fixture"
~~~

## Ensayo offline reproducible del contrato existente

**PROPUESTA, NO ENSAYADO por esta documentación:** el escenario del repositorio que sí reproduce el exceso de presupuesto utiliza 560 señales simuladas y comprueba reason=bounded_signal_budget, truncated=true, omitted por categoría, sin abortar la escritura. Ejecutar en un checkout exacto con PHP y Python 3:

~~~bash
php tests/factory_orchestrator_evidence_collector_scenarios.php over-400
python3 -m unittest -v tests.test_factory_orchestrator_evidence_collector tests.test_factory_orchestrator_snapshot_refresh tests.test_carga_senales_doc
~~~

Son resultados **de fixture**, no son métricas productivas. El JSON de 450 del apartado anterior es independiente y **no ha recorrido collector → cron → snapshot → UI**. Fuentes: [tests/test_factory_orchestrator_evidence_collector.py](../tests/test_factory_orchestrator_evidence_collector.py), [tests/factory_orchestrator_evidence_collector_scenarios.php](../tests/factory_orchestrator_evidence_collector_scenarios.php), [#760](https://github.com/pl0n3r/ControlBot/issues/760).

## Qué observar: collector, cron, snapshot y UI

**Estado de cada observación: UNKNOWN / NO ENSAYADO.** Solo un operador legitimado para cada acción bajo #625 y D-059 puede obtener recibos reales de la identidad desplegada. No recolectar chats, alias, cuentas, IP privadas, cookies, tokens o datos identificables. Fuente: [#760 AC-04](https://github.com/pl0n3r/ControlBot/issues/760), [#625](https://github.com/pl0n3r/ControlBot/issues/625).

| Etapa | Evidencia futura requerida | Estado |
| --- | --- | --- |
| collector | Total mayor que 400; truncated y omitted por categoría; verificar ausencia de signal_budget_exceeded durante la ventana | UNKNOWN / NO ENSAYADO |
| cron | Momento de ciclo, resultado y último éxito | UNKNOWN / NO ENSAYADO |
| snapshot | Identidad de SHA/version; freshness, stale y last_success_at | UNKNOWN / NO ENSAYADO |
| UI | Vista censurada de truncated, omitted, freshness y estado stale | UNKNOWN / NO ENSAYADO |

Sin secuencia completa de ciclos no se afirma ausencia de **signal_budget_exceeded**. Si cron, collector o snapshot fallan, preservar el último éxito sin representarlo como fresco; marcar **stale** o UNKNOWN. Fuente: [docs/runbooks/orchestrator-snapshot-cron.md](runbooks/orchestrator-snapshot-cron.md), [tests/test_factory_orchestrator_snapshot_refresh.py](../tests/test_factory_orchestrator_snapshot_refresh.py).

## Plantilla de captura minimizada

**Plantilla vacía; NO ENSAYADO.** El operador deberá incluir referencias opacas verificables, no secretos, correo personal, capturas de chats ni URLs firmadas. Fuente: [#625](https://github.com/pl0n3r/ControlBot/issues/625), [#760](https://github.com/pl0n3r/ControlBot/issues/760).

| Campo | Valor |
| --- | --- |
| SHA exacto y versión realmente desplegados | UNKNOWN |
| Referencia de autorización por operación y operador | UNKNOWN |
| Ventana UTC de observación y cantidad de ciclos | UNKNOWN |
| Total observado mayor que 400 | UNKNOWN |
| truncated / omitted por categoría | UNKNOWN |
| freshness / stale / last_success_at | UNKNOWN |
| signal_budget_exceeded observado o ausencia probada | UNKNOWN |
| Referencia censurada collector / cron / snapshot / UI | UNKNOWN |
| Revisión D-043, D-059 y #625 | UNKNOWN |
| Cierre de AC-04 productiva | NO ENSAYADO · DEL_DUEÑO |

## Relación con la lista de salida y reversión

La fila exacta **«Cola de más de 400 señales en collector, cron, snapshot y UI»** de [docs/lista-salida-produccion.md](lista-salida-produccion.md) se mantiene **DEL_DUEÑO** (Issue #760). Esta preparación no declara LISTO, GREEN ni VALIDATED_IN_PRODUCTION; no cambia lista, servidor, hosting, DNS, secrets, cron, registros personales, migraciones, gastos, deploy ni live. Fuente: [lista de salida](lista-salida-produccion.md), [#964](https://github.com/pl0n3r/ControlBot/issues/964), [#625](https://github.com/pl0n3r/ControlBot/issues/625).

Reversión offline: revertir el squash del documento y la prueba, sin efectos remotos. Fuente: [#964](https://github.com/pl0n3r/ControlBot/issues/964).
