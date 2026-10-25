# Grants owner-gated exclusivamente para pruebas offline (#874)

La decisión humana #873 /decidir A, acreditada por factory-human-decision v2, autorizó esta hoja independiente del roadmap #55 para avanzar hacia #763. **No autoriza** permisos reales, ejecución de GitHub, publicación ni cambios en CapabilityPolicy o GitHubIntentPolicy.

GitHubIntentTestGrantAuthority::evaluate compone el emisor de firma HMAC para fixtures (#869) y el verificador offline (#856). Sólo acepta requisitos tipados, un contexto de servidor offline_test_only, aprobación explícita owner-verificada del mismo scope y acción, reloj de pruebas, TTL máximo de 300 segundos, nonce no visto/revocado y clave efímera del test harness. El catálogo local clasifica github.issue.write, github.pr.review, github.pr.merge, github.release.approve, github.workflow.dispatch y github.project.write como owner_required **solo en el arnés de pruebas**, sin registrarlos como capacidades productivas. Los errores se reducen a razones allowlisted, sin divulgar claves ni entradas.

Con fixture válido, el resultado test_grant_valid=true incluye una prueba firmada **no ejecutable** para arneses posteriores. Conserva siempre decision=deny, execution=false, authorized=false, grant_issued=false, approval_issued=false y runtime_authorized=false. El verificador compartido también responde deny/offline_only. Un actor o petición HTTP que controle sus propios datos no obtiene autoridad: el contexto sólo existe en un entorno aislado de pruebas; ningún ejecutor de producción debe importar ni invocar esta clase.

## Reentrada de #763

#763 necesita además autorización owner-gated **específica** para escenarios workflow.dispatch y project.freeze/unfreeze, reserva V3, sus AC exactos, transporte fake, CI Validar, seguridad y las puertas D-069 antes del squash. #873/A autoriza preparar la infraestructura de test, **no autoriza** ese paso de ejecución por inferencia. Para cualquier operación real se necesitaría otra puerta del OWNER y capacidades/credenciales por canal seguro. No se tocan el kill switch #767, producción, red, datos reales, GitHub App, Hostinger ni gastos.

Prueba: python3 -m unittest -v tests.test_github_intent_test_grant_authority sobre PHP CLI. Reversión: revertir la entrega de cuatro archivos; no modifica runtime.
