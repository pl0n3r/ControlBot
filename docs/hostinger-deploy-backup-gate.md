# Puerta D-059 de despliegue: copia verificada y restore simulado

> **Solo preparación offline; sin red, sin cambios a Hostinger o Backblaze B2.** Esta prueba no implementa el ejecutor real del deploy ni convierte la fase construccion en live.

La decisión del dueño en ControlBot #625 (30-oct-2026) exige que **un proceso automático**, y no la intervención manual del dueño, genere la copia previa al cambio y que la puerta niegue cualquier deploy sin recibo **verificado antes del cambio**. El destino previsto es **Backblaze B2**, compatible con S3, condicionado a autoridad de proveedor y permisos independientes según docs/backblaze-b2-preflight.md y D-059.

## Recibo obligatorio de una futura operación real

Un proceso productor, después de verificar efectivamente la copia, debe emitir por canal seguro: identificador opaco, fecha UTC de creación, fecha UTC de verificación, expiración, SHA-256 del objeto, ubicación y destino B2, alcance exacto del recurso, project, environment, resource, run_id, issue y backup_type. El recibo debe estar ligado a la misma operación que se quiere proteger y a su release exacto, sin filtrar URLs firmadas ni datos personales.

Las credenciales residen **únicamente en archivos privados NAME_FILE** administrados por un operador autorizado; jamás en el repositorio, logs, Issues o fixtures. La configuración B2 (bucket privado, retención/Object Lock, SSE-B2) no acredita por sí sola copias reales ni restauración.

## Puerta fail-closed antes del cambio

1. Validar autoridad del operador/OWNER, capacidad protegida, identidad y alcance de recibo auténtico. Ausente, ambiguo, no listo, de otra ejecución, futuro, vencido o antiguo -> denegar; UNKNOWN -> denegar.
2. El proceso automatizado crea y verifica el backup antes de modificar Hostinger. Comprobar el SHA-256 mediante bytes recuperados del destino protegido, no confiando solo en metadatos del solicitante.
3. Ejecutar un **restore** descartable en entorno aislado y cotejar huella SHA-256 y tamaño. Cualquier discrepancia, truncamiento, falta de evidencia o destino incorrecto mantiene la puerta cerrada.
4. Solo después de todos los gates independientes de identidad, Hostinger, legal/privacidad, TLS, backup restaurable, rollback, D-043, D-059 y #625 se podrá evaluar un deploy real, siempre con autorización específica. CI verde, fixtures o etiquetas nunca dan autoridad operativa.

## Implementación y límite de la demostración

tests/test_deploy_backup_gate.py llama a las **clases PHP reales** BackupGate, BackupReceipt y BackupIntegrityVerifier con bytes y almacén **simulados**, sin red. El fixture usa release_artifact_snapshot y la capacidad existente deploy.rollback_artifact como prueba aislada de bloqueo de escritura protegida. La salida SATISFIED significa solamente una **precondición sintética** y jamás ejecución, permiso de deploy ni evidencia de proveedor real.

Las regresiones negativas cubren SHA-256 alterado, restore truncado, falta de recibo listo, scope/capacidad/ejecución distinta, vencimiento, destino no-B2, ausencia de verificación y precedencia temporal. El resultado seguro no contiene el payload restaurado ni su huella y conserva runtime_authorized=false, deploy_enabled=false y production_verified=false.

Este PR **no conecta** al proveedor B2 ni integra el gate en un workflow de Hostinger: backups reales, Object Lock sobre objetos, restore operativo, auth/HTTPS y observación VALIDATED_IN_PRODUCTION siguen UNKNOWN bajo #182, #625 y #760. No habilita ninguna acción real ni cambia la fase del proyecto.

**Prueba local/CI:** python3 -m unittest tests.test_deploy_backup_gate -v (requiere PHP CLI). **Reversión:** eliminar estos dos archivos documentales/de test; no afecta producción, credenciales ni ejecutores.
