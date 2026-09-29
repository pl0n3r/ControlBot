# External API Session v1

## Límite de confianza

Este contrato modela **metadatos nominales** de dispositivo, sesión y step-up que ya fueron verificados por un autenticador externo. No emite tokens, no firma credenciales y no implementa OAuth/JWT. La autenticación aquí representada tampoco concede autorización: `ExternalApiAccess` conserva capability, authority y policy server-side.

## Device

Un device contiene únicamente una referencia opaca, identidad humana, fecha de registro y estado `active|revoked`. Si está revocado, debe existir timestamp y reason code. No se almacenan claves, biometría, identificadores publicitarios ni secretos del dispositivo.

## Session

Una sesión contiene referencia opaca, device, identidad, scope, timestamps y revocación. En v1:

- `expires_at > issued_at`;
- la vida máxima es 3600 segundos;
- una sesión expirada o revocada no es utilizable;
- un device revocado invalida sus sesiones;
- identity, device y scope deben coincidir con el contexto esperado.

Una sesión active solo representa autenticación nominal vigente. **No significa que una operación esté autorizada.**

## Step-up

Step-up conserva una referencia opaca y evidencia temporal de `passkey|mfa`:

- debe pertenecer a la misma identity/session/device;
- la sesión y el device deben estar activos;
- `verified_at` no puede anteceder la sesión;
- dura como máximo 300 segundos;
- conserva estado `active|revoked` con timestamp/reason verificables;
- una revocación propia invalida el step-up aunque la sesión y el device sigan activos;
- expirado, revocado o mismatched falla cerrado.

Step-up demuestra recencia de autenticación reforzada. No concede L4 ni ninguna capability.

## Inventario y revocación

`safeInventory()` sirve para proyectar devices/sessions con sus estados de revocación sin material criptográfico. Las entradas son cerradas: campos extras como credenciales, cookies, OTPs o claves son rechazados por el contrato.

## Fuera de alcance

Persistencia, emisión/refresh de tokens, OAuth/JWT signing, Keychain/Secure Enclave, push notifications, código iOS, provider calls, Factory/FactoryRunner y decisiones de autorización.
