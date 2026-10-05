# Hostinger Public API Runtime

## Propósito

`HostingerPublicApiRuntime` conecta Production Authority con el adapter Hostinger Public API sin permitir que callers o agentes resuelvan credenciales por su cuenta. Recibe un handle `SecretReference`, delega la resolución a `SecretsBroker` y ejecuta el adapter dentro del callback efímero del broker.

No crea otra autoridad. `CapabilityPolicy`, `CapabilityGrant`, `SecretReference` y `SecretsBroker` siguen siendo los contratos canónicos.

## Límite de confianza

Flujo read-only:

```text
caller
  -> HostingerPublicApiRuntime
  -> usa del handle solo reference_id + generation
  -> SecretsBroker(executor_id=hostinger-public-api, scope exacto)
  -> callback con metadata de la referencia registrada + credencial efímera
  -> valida metadata autoritativa: provider=hostinger, secret_kind=api_token, capability exacta
  -> HostingerPublicApiAdapter
  -> transporte inyectado
  -> resultado saneado por SecretsBroker
```

El handle del caller no puede promoverar una referencia registrada de otro provider/kind: la metadata autoritativa es la que conserva el broker. La credencial no sale del callback. Scope incorrecto, referencia revocada/desconocida, generación distinta o metadata incompatible fallan cerrado antes del transporte.

## Operaciones

- `websites()`: usa `hostinger.read`.
- `cronSnapshot()` y `cronOutput()`: usan `cron.snapshot`.
- `planCronWrite()`: delega el contrato existente de `cron.write`; exige el grant/backup receipt exactos y continúa con `dry_run=true` y `execution=false`. No resuelve credenciales ni hace POST/DELETE.

## Red y activación live

El constructor exige un transporte callable. **No existe transporte por defecto ni fallback de red.** Los tests usan fakes deterministas.

Este slice no autoriza ni configura:

- credencial Hostinger real;
- registro de secretos productivos;
- cron productivo;
- `DOMAIN` / `DEPLOY_ENABLED`;
- DNS, deploy, billing o go-live.

Una activación futura debe registrar la referencia/credencial por canal seguro y continuar usando el mismo policy/broker. La activación live sigue sujeta a la autoridad explícita de #625/#45.
