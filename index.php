<?php
// ControlBot - Redirector al directorio público
// Si Hostinger tiene el web root en la raíz del proyecto,
// este archivo sirve como punto de entrada a public/index.php

if ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '') {
    require_once __DIR__ . '/public/index.php';
    exit;
}

// Para otras rutas, servir desde public/
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public_path = __DIR__ . '/public' . $path;

if (is_file($public_path)) {
    // Servir archivos estáticos (CSS, JS, imágenes, etc)
    $mime = mime_content_type($public_path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($public_path);
    exit;
}

// Si no es archivo estático, servir index.php
require_once __DIR__ . '/public/index.php';
