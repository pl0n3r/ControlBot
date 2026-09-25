# Núcleo de aprobaciones del dueño: contrato de construcción

Issue padre: #3; primer corte ejecutable: #25. `OwnerApprovalService.php` es
PHP puro y no expone rutas, credenciales, servicio web ni acciones reales de
GitHub. La infraestructura existente sigue en fase `construccion`.

## Límites de confianza

El controlador futuro debe suministrar `trustedSession` **desde una sesión
autenticada del servidor**, no desde un POST, cookie autocontenida o booleano
del navegador. Debe incluir un actor igual al dueño y el instante verificable
de la última reautenticación fuerte, de 0 a 300 segundos en el pasado.
La verificación real de passkey/TOTP y CSRF no pertenece a este corte.

`ApprovalPort::readGate` obtiene cuerpo y asociación de autor desde la API
GitHub autenticada, nunca acepta texto suministrado por el navegador. El
servicio acepta únicamente una puerta `factory-human-gate` válida, con
opciones A–D únicas, categoría `factory-release` o `money` y default
existente. El adapter del servidor debe aportar `approval_option` verificada por política
confiable. No se deduce de recomendación o default: elegir otra opción solo
registra rechazo. `go-live` y cualquier otra categoría fallan cerrado en esta fase.

## Secuencia de release

1. Verificar sesión/reautenticación y puerta confiable.
2. Comparar SHA exacto de `main`; leer identidad exacta del canal actual.
3. Registrar intención, opción seleccionada y SHA en bitácora append-only **antes de mutaciones**.
4. Registrar `factory-release-approval` del dueño en la puerta.
5. Comprobar otra vez que `main` mantiene el SHA.
6. `compareAndMoveChannel(previousSha, targetSha)` debe usar una operación
   remota condicional/CAS real, no un `git push --force` incondicional.
7. Despachar el workflow de release y registrar `dispatched`.

Si falla cualquier paso luego del registro de aprobación, se devuelve
`reconcile-required` sin retransmitir la excepción del proveedor. Un
resultado incierto **no autoriza reintentar ciegamente** el tag o el workflow.
El adaptador real debe aplicar idempotencia por Issue/SHA, consultar estado
antes de reanudar y conservar evidencia enlazable en bitácora.

Una puerta `money` solo registra la opción seleccionada; no cambia tags,
no despacha workflows y **nunca realiza pagos**.

## Cómo comprobar el corte

```sh
php -l src/Approval/OwnerApprovalService.php
php -l tests/fixtures/approval_core.php
python3 -m unittest tests.test_owner_approval_core
```

`tests/fixtures/approval_core.php` usa exclusivamente un `FakePort` y
sesiones de prueba, sin conexión de red ni tokens. La nueva suite se añade
al job `Contrato ControlBot`.

## Aún pendiente para cerrar #3

Adapter GitHub autenticado, comprobación de firma/autoría de aprobación,
idempotencia distribuida, bitácora persistente, passkey o contraseña+TOTP,
CSRF, bandeja móvil y tests E2E. La puerta legal ControlBot#20 mantiene
no-go-live y ningún dato real en producción.
