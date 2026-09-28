<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ControlBot - Centro de Control</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0d1117; color: #c9d1d9; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        header { border-bottom: 1px solid #30363d; padding-bottom: 30px; margin-bottom: 40px; }
        h1 { font-size: 2.5em; margin-bottom: 10px; color: #58a6ff; }
        .subtitle { font-size: 1.1em; color: #8b949e; }
        .status { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 40px 0; }
        .status-card { background: #161b22; border: 1px solid #30363d; border-radius: 6px; padding: 20px; }
        .status-label { font-size: 0.9em; color: #8b949e; text-transform: uppercase; letter-spacing: 0.05em; }
        .status-value { font-size: 1.3em; font-weight: 600; margin-top: 8px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 500; margin-top: 8px; }
        .badge.construction { background: #f0883e; color: #000; }
        .badge.php { background: #777bb3; color: #fff; }
        .badge.hostinger { background: #ff9900; color: #000; }
        .links { margin-top: 40px; padding-top: 30px; border-top: 1px solid #30363d; }
        .links a { color: #58a6ff; text-decoration: none; margin-right: 20px; }
        .links a:hover { text-decoration: underline; }
        footer { margin-top: 60px; padding-top: 30px; border-top: 1px solid #30363d; text-align: center; color: #8b949e; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🤖 ControlBot</h1>
            <p class="subtitle">Centro de control web privado de la fábrica de software</p>
        </header>

        <div class="status">
            <div class="status-card">
                <div class="status-label">Versión</div>
                <div class="status-value">0.1.18</div>
                <span class="badge construction">En construcción</span>
            </div>
            <div class="status-card">
                <div class="status-label">Stack</div>
                <div class="status-value">PHP 8.5</div>
                <span class="badge php">PHP</span>
            </div>
            <div class="status-card">
                <div class="status-label">Hosting</div>
                <div class="status-value">Hostinger</div>
                <span class="badge hostinger">Hostinger</span>
            </div>
            <div class="status-card">
                <div class="status-label">Estado</div>
                <div class="status-value">🟡 Activo</div>
            </div>
        </div>

        <div class="links">
            <h3 style="margin-bottom: 15px;">Referencias</h3>
            <a href="https://github.com/pl0n3r/ControlBot" target="_blank">📦 Repositorio</a>
            <a href="https://github.com/pl0n3r/Factory" target="_blank">🏭 Factory</a>
            <a href="https://github.com/pl0n3r/Condor" target="_blank">🦅 Condor</a>
        </div>

        <footer>
            <p>ControlBot v0.1.18 | Centro de control de pl0n3r/Factory</p>
        </footer>
    </div>
</body>
</html>
