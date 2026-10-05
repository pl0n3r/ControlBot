# Hostinger Public API Runtime

## Propósito

`HostingerPublicApiRuntime` conecta Production Authority con el adapter Hostinger Public API sin permitir que callers o agentes resuelvan credenciales por su cuenta. Recibe una `SecretReference`, delega la resolución a `SecretsBroker` y ejecuta el adapter dentro del callback efímero del broker.

No crea otra autoridad. `CapabilityPolicy`, `CapabilityGrant`, `SecretReference` y `SecretsBroker` siguen siendo los contratos canónicos.

## Límite de confianza

Flujo read-only:

```text
caller
  -> HostingerPublicApiRuntime
  -> valida metadata pública: provider=hostinger, secret_kind=api_token, capability exacta
  -> SecretsBroker(executor_id=hostinger-public-api, scope exacto)
  -> callback efímero con credencial
  -> HostingerPublicApiAdapter
  -> transporte inyectado
  -> resultado saneado por SecretsBroker
```

La credencial no sale del callback del broker. Scope incorrecto, referencia revocada/desconocida, generación distinta o metadata incompatible fallan cerrado antes del transporte.

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

Una activación futura debe aportar una `SecretReference` server-side válida, registrar la credencial por canal seguro y continuar usando el mismo policy/broker. La activación live sigue sujeta a la autoridad explícita de #625/#45.
