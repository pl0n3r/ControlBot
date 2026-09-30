# SecurityEvent v1

Contrato puro para normalizar señales de seguridad antes de persistencia, UI o alerting. No consulta providers y no ejecuta acciones.

## Esquema y confianza

Solo acepta `version`, `provider`, `event_id`, `observed_at`, `occurred_at`, `event_type`, `severity`, `account_scope`, `confidence`, `device` y `actor`; campos extra fallan cerrado.

`severity` (`info|warning|critical`) expresa impacto. `confidence` (`confirmed|provider_reported|correlated|unknown`) expresa calidad de evidencia. `confirmed` exige `event_id` y `occurred_at`. Sin `event_id`, `occurred_at` es obligatorio para construir identidad estable.

## Deduplicación

Con `event_id`, `event_identity` deriva de `provider + event_id` sin exponer el ID crudo. Sin ID, deriva del fingerprint canónico de proveedor, tipo, alcance, momento del evento y contexto minimizado. `observed_at`, severidad y confianza pueden evolucionar sin cambiar la identidad del mismo hecho. Los mapas se ordenan antes del hash.

## Minimización

`device` es opcional y solo admite tipo, plataforma, navegador y ubicación aproximada `{country, region, city}`. Coordenadas, GPS, dirección exacta y campos no declarados se rechazan.

Acciones sensibles de ControlBot conservan solo `actor_ref` y `context_ref` opacos con prefijo `controlbot:`. Passwords, tokens, cookies, OTP, recovery codes, claves privadas, emails y payloads libres no forman parte del contrato.

Tipos iniciales: `new_login`, `new_device`, `privileged_session`, `passkey_added`, `passkey_removed`, `totp_changed`, `recovery_method_changed`, `credential_rotated`, `production_authority_changed`, `billing_security_changed`, `security_alert_acknowledged`, `repository_visibility_changed`.

## Fuera de alcance

DB, red, provider/email calls, filesystem writes, UI, acknowledge, retención, revocación de sesiones y canal independiente pertenecen a slices posteriores de #17.
