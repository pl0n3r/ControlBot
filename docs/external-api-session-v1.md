# External API Session v1

## Límite de confianza
Contrato puro de metadatos nominales ya verificados por un autenticador externo. No emite/firma tokens ni implementa OAuth/JWT. Autenticación no implica autorización: `ExternalApiAccess` conserva capability, authority y policy server-side.

## Device y session
Device: ref opaca, identidad, `registered_at`, estado `active|revoked` y revocación verificable. Session: ref opaca, device, identidad, scope, `issued_at/expires_at`, estado y revocación.
- sesión máxima: 3600 s;
- expired/revoked falla cerrado;
- device revocado invalida sus sesiones;
- identity/device/scope deben coincidir;
- una sesión active no significa operación autorizada.
No se almacenan claves, biometría, identificadores publicitarios ni secretos.

## Step-up
Evidencia `passkey|mfa` ligada a la misma identity/session/device:
- sesión y device activos;
- `verified_at` no antecede la sesión;
- vida máxima 300 s;
- estado `active|revoked` con timestamp/reason verificables;
- revocación propia invalida el step-up aunque sesión/device sigan activos;
- expired/revoked/mismatched falla cerrado.
Step-up demuestra recencia de autenticación reforzada; no concede L4 ni capabilities.

## Inventario y fuera de alcance
`safeInventory()` proyecta devices/sessions sin material criptográfico y rechaza campos extra como credenciales, cookies, OTPs o claves. Fuera de alcance: persistencia, emisión/refresh de tokens, OAuth/JWT signing, Keychain/Secure Enclave, push, código iOS, provider calls, Factory/FactoryRunner y decisiones de autorización.
