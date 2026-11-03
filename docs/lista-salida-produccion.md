# ControlBot · Lista de salida a producción

**Corte documental:** ControlBot `main@fb90e4cddf7e55cd79747c2b613cc872f36a4d10` · 27-oct-2026 · [#941](https://github.com/pl0n3r/ControlBot/issues/941).

**Decisión vigente: NO-GO.** Esta es una auditoría **offline de preparación**, no una autorización de despliegue, una revisión legal final ni una observación de servicio. `datos.yml.phase=construccion`. El [PR #766](https://github.com/pl0n3r/ControlBot/pull/766) ya corrigió #760 en código, pero AC-04 exige señales reales; [Observar producción #40454554886](https://github.com/pl0n3r/ControlBot/actions/runs/40454554886) acabó `skipped`.

## Política de clasificación

- `LISTO` solo con evidencia productiva autenticada, fresca y de SHA/identidad coincidentes; una prueba offline, un PR fusionado o CI verde no bastan.
- `EN_CURSO` solo con **hoja ejecutable y reserva V3** o PR abierto, nunca solo con épico/roadmap.
- `SIN_COBERTURA` requiere **nueva hoja canónica real** enlazada; está prohibido esconder una brecha tras un épico contenedor.
- `DEL_DUEÑO` identifica **qué decisión, operador autorizado, cuenta, consentimiento, gasto o comprobación presencial** falta; no concede ese permiso.
- `NO_APLICA` exige motivo concreto. En esta foto no se han omitido gates por conveniencia.

## Matriz

| Área | Requisito | Estado | Evidencia | Issue |
| --- | --- | --- | --- | --- |
| Respaldos y restauración | Copia real antes de modificar Hostinger según D-059 | DEL_DUEÑO | OWNER y operador: solo plan offline en docs/hostinger-d059-backup.md; falta recibo verificable de backup anterior al cambio | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Respaldos y restauración | Restore aislado de respaldo auténtico con integridad demostrada | DEL_DUEÑO | Operador y OWNER: tests de Recovery son sintéticos; falta restore real y resultado con SHA | [#182](https://github.com/pl0n3r/ControlBot/issues/182) |
| Respaldos y restauración | Offsite, versionado, cifrado y control de retención 3-2-1-1-0 | DEL_DUEÑO | Cuenta y operador: no hay atestación real de B2 ni Object Lock; Factory#305 exige prueba operacional | [#182](https://github.com/pl0n3r/ControlBot/issues/182) |
| Monitoreo y alertas | Alertas de disponibilidad, cron y errores con recepción verificada | DEL_DUEÑO | Operador: señalización offline no equivale a alertas productivas ni incidentes observados | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Monitoreo y alertas | Métricas de frescura de colector, snapshot y UI | DEL_DUEÑO | Operador: #760 tiene fixture >400; no hay serie real ni freshness observable | [#760](https://github.com/pl0n3r/ControlBot/issues/760) |
| Dominios, DNS y TLS | DNS real de control.condorapp.com.co y HTTPS certificado | DEL_DUEÑO | OWNER y operador Hostinger: no cambiar DNS ni TLS sin permiso por operación y rollback | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Dominios, DNS y TLS | HTTPS privado y acceso owner-only probado desde cliente externo | DEL_DUEÑO | Operador: public/health.php y #817 son contratos offline; falta respuesta exact-SHA protegida | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Secretos y accesos | Provisión de secretos y referencias por canal protegido | DEL_DUEÑO | OWNER y operador: el SecretsBroker offline no constituye registro real de credenciales | [#45](https://github.com/pl0n3r/ControlBot/issues/45) |
| Secretos y accesos | Acceso mínimo, revocación y auditoría del operador Hostinger | DEL_DUEÑO | OWNER y operador: falta identidad comprobada, autorización de cada operación y revocación | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Costos y límites | Presupuesto de hosting, almacenaje y servicios asociados | DEL_DUEÑO | OWNER: gasto, compras, renovación y aceptación de términos requieren decisión explícita | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Costos y límites | Cuotas de API GitHub, Hostinger, observador y cron | DEL_DUEÑO | Operador: existen límites de adapters offline, no medición autentificada de cuotas reales | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Privacidad y legal | Responsable, finalidad y avisos para tratamiento real | DEL_DUEÑO | OWNER: datos.yml aún contiene campos [COMPLETAR POR EL DUEÑO]; no hay validación legal de live | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Privacidad y legal | Retención, borrado, terceros y ejercicio de derechos | DEL_DUEÑO | OWNER y asesor legal: requieren base válida, avisos y flujo real de derechos; no simular consentimiento | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Soporte y operación diaria | Guardias, contacto de incidencias y horario de atención | DEL_DUEÑO | OWNER: requiere responsables reales y prueba de recepción de incidentes | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Soporte y operación diaria | Runbook operador de arranque, fallos y mantenimiento | DEL_DUEÑO | Operador y OWNER: docs/hostinger-go-live-plan.md es preparación; falta ensayo en infraestructura autorizada | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Migración de datos | Inventario y base legítima para perfiles y chats | DEL_DUEÑO | OWNER: migrar chats o cuentas reales necesita autorización, consentimiento y minimización | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Migración de datos | Plan reversible, reconciliación y validación de datos migrados | DEL_DUEÑO | OWNER y operador: no existe migración real aprobada ni recibo de reconciliación | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Reversión y apagado | Rollback al artefacto anterior con restore y tiempo medido | DEL_DUEÑO | Operador: ops/factory/rollback es preflight SYNTHETIC_ONLY; falta drill real | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Reversión y apagado | Apagado de emergencia del runtime y revocación de accesos | DEL_DUEÑO | OWNER y operador: Factory#767 RUNNING gobierna agentes, no demuestra apagado del servicio desplegado | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Seguridad, revisión y pruebas | Prueba de autenticación, CSRF, TOTP y accesos restringidos | DEL_DUEÑO | Operador: tests offline y Sonar/CodeQL no sustituyen prueba de servicio instalado owner-only | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Seguridad, revisión y pruebas | Revisión de hallazgos graves sobre artefacto exacto desplegado | DEL_DUEÑO | OWNER y operador: falta artefacto desplegado, revisión de severidad y evidencia terminal en live | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Accesibilidad y rendimiento | Accesibilidad y navegación de interfaz con autenticación real | DEL_DUEÑO | Operador: no hay sesión owner-only desplegada que permita auditar teclado, foco o lector | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Accesibilidad y rendimiento | Mediciones de carga y rendimiento en entorno de destino | DEL_DUEÑO | Operador: no hay métricas reales de hosting; los fixtures no prueban presupuestos de producción | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Smoke de producción | Servicio HTTPS, login owner-only y health version/SHA | DEL_DUEÑO | Operador: public/health.php integrado mediante #817, pero observador #40454554886 terminó skipped | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Smoke de producción | Cola de más de 400 señales en collector, cron, snapshot y UI | DEL_DUEÑO | Operador: AC-04 de #760 exige captura real de truncated/omitted, frescura y ausencia de signal_budget_exceeded | [#760](https://github.com/pl0n3r/ControlBot/issues/760) |
| Smoke de producción | Atestación D-043 VALIDATED_IN_PRODUCTION sin pendientes | DEL_DUEÑO | OWNER y operador: no hay recibo de deploy observado exact-SHA con pending=[] | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Autenticación del propietario | Login owner-only con prueba real de sesión y revocación | DEL_DUEÑO | OWNER y operador: Basic Auth y la seguridad offline del panel no acreditan el login instalado | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Autenticación del propietario | 2FA y permisos de perfil efectivos en hosting | DEL_DUEÑO | OWNER: falta prueba independiente de TOTP, rutas prohibidas y caducidad de sesiones reales | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Autenticación del propietario | Crear la clave del propietario, guardar su hash en el servidor y activar la página de login (retirar la ventana de Basic Auth) | DEL_DUEÑO | OWNER: la pantalla de login existe pero no está conectada; faltan `CONTROLBOT_VAULT_KEY` y el hash del dueño como archivos privados en Hostinger (ver `docs/secretos-en-archivo-hostinger.md`), copia previa según D-059 y el despliegue; el código lo preparan [#1346](https://github.com/pl0n3r/ControlBot/issues/1346) y [#1349](https://github.com/pl0n3r/ControlBot/issues/1349) | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Transporte GitHub | Acceso GitHub real read-only limitado y autenticado | DEL_DUEÑO | OWNER: productor GitHubReadOnlyProjectionWriter #934 está OFF por defecto; faltan llaves autorizadas | [#55](https://github.com/pl0n3r/ControlBot/issues/55) |
| Transporte GitHub | Proveniencia temporal de datos reales y revocación de token | DEL_DUEÑO | OWNER y operador: no hay snapshot GitHub productivo, validación de TTL o evidencia de rotación | [#55](https://github.com/pl0n3r/ControlBot/issues/55) |
| Transporte Hostinger | Canal real host/SSH o API con identidad de operador | DEL_DUEÑO | OWNER y operador: docs/hostinger-public-api-runtime.md exige SecretsBroker, transporte y permisos; no hay sesión productiva | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Transporte Hostinger | Backup antes de deploy y prohibición de borrar sin permiso | DEL_DUEÑO | OWNER y operador: D-059 y docs/hostinger-d059-backup.md exigen recibos reales | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Persistencia y sesión | Almacenamiento real, integridad y migraciones verificadas | DEL_DUEÑO | OWNER y operador: las pruebas MariaDB offline no son un restore ni migración de producción | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Persistencia y sesión | Cookies, CSRF, expiración y sesiones reales en HTTPS | DEL_DUEÑO | OWNER y operador: controles de código no prueban transporte/sesión en dominio real | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Monitoreo del propio ControlBot | Health real y alertas de caída del panel | DEL_DUEÑO | Operador: health.php y HealthIdentityPayload son código, pero readiness real del observador permanece omitido | [#625](https://github.com/pl0n3r/ControlBot/issues/625) |
| Recuperación de desastres | RPO/RTO medidos y simulacro de recuperación independiente | DEL_DUEÑO | OWNER y operador: #182 es épico bloqueado; falta drill autenticado de infraestructura | [#182](https://github.com/pl0n3r/ControlBot/issues/182) |
| Auditoría de salida | Matriz requisito por requisito en hoja ejecutable separada | EN_CURSO | Hoja ejecutable #940 con V3, claims docs/matriz-cobertura.md y tests/test_matriz_cobertura.py | [#940](https://github.com/pl0n3r/ControlBot/issues/940) |
| Auditoría de salida | Checklist de go-live con test y enlace de evidencias | EN_CURSO | Hoja ejecutable #941 con V3, claims docs/lista-salida-produccion.md y tests/test_lista_salida_produccion.py | [#941](https://github.com/pl0n3r/ControlBot/issues/941) |

## Resumen final

| Estado | Filas |
| --- | ---: |
| LISTO | 0 |
| EN_CURSO | 2 |
| SIN_COBERTURA | 0 |
| DEL_DUEÑO | 37 |
| NO_APLICA | 0 |
| TOTAL | 39 |

**Hojas nuevas creadas por esta auditoría:** 0. No hay filas `SIN_COBERTURA` en este corte: las faltas productivas enumeradas están bloqueadas por operación/autoridad del OWNER y el operador, no se convierten en hojas sin scope autorizado. Cualquier brecha puramente técnica nueva debe identificarse con `SIN_COBERTURA` y materializar su hoja ejecutable antes de aceptar el checklist.

**Bloquean el go-live:** operador Hostinger y permiso por operación; fuente de backup auténtico anterior al deploy y restore aislado; rollback ensayado; responsable legal/consentimientos; secretos/costos y acceso restringido; HTTPS con identidad de artefacto exact-SHA; conexión GitHub/Hostinger autorizada; persistencia real; smoke owner-only; observación de más de 400 señales reales para #760 AC-04; resultado canónico `VALIDATED_IN_PRODUCTION` con `pending=[]`. No habilitar `DOMAIN`/`DEPLOY_ENABLED` por inferencia.

**Ruta segura:** completar primero requisitos `DEL_DUEÑO` mediante decisión humana específica y operador autorizado, conservar recibos opacos/no sensibles por SHA y hora, verificar gates en el destino y solo entonces considerar la transición bajo D-043/D-059. En ninguna etapa esta tabla autoriza pagos, DNS, secretos, deploy, backup real, tráfico GitHub, tratamiento personal ni migración de chats.

**Fuentes:** `decisiones.yml` (D-054 a D-059); `datos.yml`; `docs/hostinger-go-live-plan.md`; `docs/hostinger-d059-backup.md`; `docs/hostinger-public-api-runtime.md`; `docs/recovery-center-web.md`; `public/health.php`; `.github/workflows/observar.yml`; issues #1, #55, #182, #625, #750, #760, #817, #915, #934, #940, #941; decisiones #914/A y #933/A.
