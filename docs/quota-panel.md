# Panel de cuotas — ControlBot #1360

## Alcance y autoridad

Esta hoja define una **tarjeta informativa, de solo lectura** para el panel privado. Muestra las cuatro fuentes canónicas en orden estable: GitHub, Hostinger, Observador y Cron. Por fuente se presenta **uso, límite y última medición**; los datos ausentes, no fiables o parcialmente inválidos se muestran como **UNKNOWN** en el campo afectado. Los instantes UTC canónicos se presentan en **America/Bogota, formato de 12 horas**.

**Una cuota jamás concede ni deniega autoridad.** No modifica reservas V3, CI, jobs, deploy, pausas, puertas humanas, estado de proyectos o decisiones. El aviso es solo texto visible cuando el cociente *uso / límite* **supera** (estrictamente) el umbral configurable (predeterminado 80 %); un límite no conocido o no positivo nunca produce porcentaje ni aviso inferido. Un error de lectura nunca equivale a consumo cero.

### Contrato de snapshot por fuente

La entrada de la tarjeta es un diccionario sintético / snapshot previamente autorizado de solo lectura; cada clave canónica (github, hostinger, observer, cron) acepta campos opcionales:

- used: entero no negativo (no booleano, decimal ni negativo).
- limit: entero estrictamente positivo.
- measured_at: UTC exacto YYYY-MM-DDTHH:MM:SSZ. Ausente, incoherente o inválido: UNKNOWN.
- threshold: parámetro del renderizador, cociente (0, 1], con 0.8 por defecto; un parámetro inválido usa el valor por defecto, **nunca detiene** la cola.

No se consultan proveedores, se leen secretos, se agregan credenciales ni se calculan cuotas reales en esta hoja. Los adaptadores de lectura y la conexión a la interfaz PHP del producto necesitan trabajo posterior con claims explícitos en las rutas del panel; este Issue solo reclama **este documento y su prueba**. Ninguna prueba de la tarjeta declarará Hostinger o el observador realmente conectado.

### Componente PHP de referencia ejecutado por la regresión

Este bloque es un prototipo autocontenido y reproducible **para integración futura**, no un endpoint ni un widget ya montado en el panel de producción. La regresión ejecuta este código PHP contra snapshots simulados.

~~~php
function quota_panel_render(array $measurements, float $threshold = 0.8): string
{
    if (!is_finite($threshold) || $threshold <= 0.0 || $threshold > 1.0) {
        $threshold = 0.8;
    }
    $sources = [
        'github' => 'GitHub',
        'hostinger' => 'Hostinger',
        'observer' => 'Observador',
        'cron' => 'Cron',
    ];
    $zone = new DateTimeZone('America/Bogota');
    $html = '<section class="quota-panel" aria-label="Cuotas informativas">'
        . '<h2>Cuotas (solo información)</h2>'
        . '<table><thead><tr><th>Fuente</th><th>Uso</th><th>Límite</th>'
        . '<th>Última medición (Bogotá, 12 h)</th><th>Aviso</th></tr></thead><tbody>';
    foreach ($sources as $key => $label) {
        $row = $measurements[$key] ?? null;
        $row = is_array($row) ? $row : [];
        $used = $row['used'] ?? null;
        $limit = $row['limit'] ?? null;
        $used = is_int($used) && $used >= 0 ? $used : null;
        $limit = is_int($limit) && $limit > 0 ? $limit : null;
        $time = 'UNKNOWN';
        $stamp = $row['measured_at'] ?? null;
        if (is_string($stamp) &&
            preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $stamp) === 1) {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z', $stamp, new DateTimeZone('UTC')
            );
            if ($parsed instanceof DateTimeImmutable &&
                $parsed->format('Y-m-d\TH:i:s\Z') === $stamp) {
                $time = $parsed->setTimezone($zone)->format('d/m/Y g:i A');
            }
        }
        $warn = $used !== null && $limit !== null
            && ($used / $limit) > $threshold;
        $html .= '<tr data-source="' . $key . '" data-warning="'
            . ($warn ? 'yes' : 'no') . '"><th scope="row">' . $label
            . '</th><td>' . ($used === null ? 'UNKNOWN' : (string) $used)
            . '</td><td>' . ($limit === null ? 'UNKNOWN' : (string) $limit)
            . '</td><td>' . $time . '</td><td>'
            . ($warn ? 'Aviso informativo: umbral superado' : 'Sin aviso')
            . '</td></tr>';
    }
    return $html . '</tbody></table></section>';
}
~~~

### Pruebas y reversión

Ejecutar: python3 -m unittest tests.test_quota_panel_nonblocking -v

AC-01 se ejerce con la clase y método exactos QuotaPanelNonblockingTests.test_criterio_principal. Se cubren cuatro fuentes, orden fijo, UTC → Bogotá (12 h), umbral estrictamente superior, ausencia parcial o total (UNKNOWN), datos malformados y texto de solo lectura. AC-02 corresponde a CI Factory v1 / Validar en el SHA exacto.

**Fuera de alcance:** medidores live, UI ya conectada, credenciales, despliegues, mutaciones o nuevos servicios. El squash se revierte retirando exclusivamente los dos archivos reclamados. No convertir esta documentación en una afirmación de funcionamiento productivo.
