# MOMENTUM → Factory Work Origin

#429 cierra el handoff de MOMENTUM hacia **Factory Work Origin Contract v1**. Es un bridge puro: MOMENTUM no crea cola, ranking, readiness, scheduler ni execution plane.

## Fronteras

MOMENTUM entrega intención tipada a partir de snapshots normalizados de Campaign, CreativeVariant, Email, Experiment, Paid Media y Performance del mismo Venture/Campaign. Creative debe estar aprobado, Email elegible, Experiment debe tener `status=completed` con resultado conocido y `freshness=current`, y Performance debe permanecer current.

La evidencia E2E de este contrato se construye pasando por las **APIs públicas** canónicas de Campaign, Creative, Email, Experiment, Paid Media y Performance. No se copian arrays con shape supuesto: un drift incompatible upstream debe romper el escenario antes del handoff.

Paid Media conserva la decisión canónica de authority y **CAPITAL**. `deny`, `owner_decision_required`, unknown o stale producen `status=blocked`, `work_item=null` y un gate referenciable; nunca se convierten en permiso implícito.

**Factory** conserva el contrato WorkItem, idempotencia, readiness, ranking, deduplicación, DAG/claims y dispatch. **FactoryRunner** ejecuta únicamente trabajo ya despachado. **AEGIS** conserva seguridad, secretos, riesgo y compliance. **CAPITAL** conserva presupuesto y autoridad económica.

## WorkItem v1

Cuando el handoff está listo, el resultado usa `origin_mode=automatic`, `origin_system=momentum` y uno de `marketing_growth|content|data_analytics|sales_support`. Claims, evidence, scopes, roles, capabilities, `observed_at` e idempotency se preservan de forma determinista.

La frontera de autoridad es **server-side**: `authority_level` y `budget_ref` se derivan del resultado gobernado de Paid Media; `policy_ref` queda fijado al policy canónico y `producer_ref` a MOMENTUM. El caller no puede autocertificar authority, budget, policy, producer ni approval. Freshness permanece en el wrapper de handoff para decidir materialización y no se inventa como campo adicional del WorkItem de Factory.

Los campos opcionales de Factory siguen opcionales; trabajo no-code no necesita inventar repositorio. Estar materializado tampoco concede ejecución: Factory todavía debe aplicar readiness y Dispatcher.

## Seguridad y reversión

La señal rechaza campos extra, PII y material con forma de secreto, incluso si llegan desde evidencia upstream. No existe provider execution, envío, persistencia, scheduler/queue paralelo ni credenciales. Toda salida mantiene `execution=false`.

Reversión: retirar el hardening test/docs de #441; no existe estado externo ni migración.
