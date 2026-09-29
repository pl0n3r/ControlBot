# LEX E2E

#173 compone los contratos legales ya existentes sin crear otro motor:

`Market Scope → Jurisdiction Packs → LexCore → LexGate → evidencia → LexCore reevaluado`.

El runtime es puro y determinista. Opera **sin providers reales**, red, producción ni datos de cliente. No ejecuta acciones y devuelve explícitamente `authority=unchanged` mediante `factory_authority=unchanged` y `authority_effect=none`.

## Market y jurisdicción

`global` es un modo comercial, no una jurisdicción vacía. Todo market debe declarar al menos una jurisdicción concreta y el runtime selecciona packs compatibles. Si falta un pack current, `jurisdiction_state` y la reevaluación fallan cerrado a `unknown`.

## Evidencia y gates

LEX conserva cuatro estados legales del core: compliant, gap, unknown y not_applicable. Un resultado compliant continúa requiriendo evidencia explícita; stale/unknown nunca se promociona.

Una incertidumbre material produce la puerta humana legal ya definida por LexGate. Un gap ejecutable produce el WorkItem canónico; ese trabajo sigue entrando por **Factory Queue**, y la ejecución eventual corresponde a **FactoryRunner** bajo la autoridad existente.

LEX no amplía permisos, no decide asuntos jurídicos humanos y no convierte una recomendación en aprobación.

## Límites

- ControlBot compone y muestra evidencia/decisiones.
- Factory conserva gobierno, acceptance, Queue y autoridad.
- FactoryRunner es execution plane; este E2E no lo invoca.
- LexWatch solo aporta señales trazables; rumores, stale o source unknown fallan cerrado.
- No hay provider directo, credenciales, secretos, scheduler ni cola paralela.
- `UNKNOWN` nunca equivale a PASS/compliant.
