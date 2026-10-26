# ControlBot #625 · Plan de lanzamiento verificable, **offline solamente**

**Autoridad:** #914/A con journal de decisión humana v2 del propietario permite preparar el plan, no operar la infraestructura. La herramienta es deliberadamente un inventario de evidencias suministradas; no se conecta a Hostinger, GitHub, backups, DNS ni cuentas. Incluso un resultado `REVIEW_ONLY` **no autoriza publicación**.

## Ejecución reproducible

Desde la raíz del repositorio, con Python 3 y sin credenciales ni PII:

```bash
python3 -m unittest discover -s tests -p 'test_hostinger_go_live_plan.py' -v
printf '{"version":1,"target":"control.condorapp.com.co","sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","phase":"construccion","evidence":[]}' | python3 scripts/hostinger_go_live_plan.py
```

El comando imprime `NO_GO` y las ocho comprobaciones faltantes. Un manifiesto sintético con las ocho presentes, `verified=true`, `fresh=true`, y el mismo scope/SHA solo produce `REVIEW_ONLY`, nunca `GO`, `GREEN`, `execution`, `deploy_authorized` o `go_live_authorized`. Los marcadores `verified` son **entrada de fixture no confiable**, no prueba de verificación externa. La herramienta no genera un dictamen operativo independiente.

Cada fila `evidence` tiene exactamente seis campos: `gate`, `source_ref` opaca con prefijo `controlbot:`, `scope`, `sha`, `verified` y `fresh`. Los gates son `owner_approval`, `hostinger_operator`, `legal_privacy`, `backup_restore`, `rollback`, `artifact_provenance`, `owner_only_https`, `production_observation`. Duplicados, scope/SHA equivocado, valores ausentes, credenciales, URLs, PII, campos inesperados, tamaños excesivos y JSON malformado fallan cerrado; la herramienta no refleja los valores de entrada.

## Frontera de responsabilidad del operador

1. **Autoridad**: obtener una decisión adicional específica de lanzamiento y un operador Hostinger legítimo; #914/A **solo** autorizó preparación. Verificar requisitos legales y de tratamiento de datos según D-059.
2. **Continuidad y seguridad**: backup real de `public_html`, restore aislado probado, rollback operativo y una identidad exacta de build/SHA; los preflights de `ops/factory/` permanecen `SYNTHETIC_ONLY`.
3. **Servicio**: verificar HTTPS, autenticación owner-only sin fuga de rutas, `health.php` exact-SHA y smoke autenticado contra servicio realmente desplegado; observar el flujo de más de 400 señales de #760 sin recopilar ni migrar chats reales.
4. **Evidencia**: conservar en issues solo referencias opacas autorizadas, tiempos, alcance y SHA. No incluir cuentas, bearer tokens, cookies, claves, passwords, URLs firmadas, IPs privadas ni datos personales. Corroborar las fuentes externamente; jamás transformar un fixture o un workflow verde en D-043, `BACKUP_VERIFIED` o `VALIDATED_IN_PRODUCTION`.

El proyecto sigue en `construccion`. Esta hoja no cambia flags `DOMAIN`/`DEPLOY_ENABLED`, credenciales, DNS, cron, SSH, pagos, backups, producción, Hostinger ni permite migración de chats. El go-live requiere permisos humanos y gates independientes. Revertir el PR elimina sus tres archivos sin efectos remotos.
