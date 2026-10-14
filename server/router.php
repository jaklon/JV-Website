<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = realpath(__DIR__ . '/public');
if (str_starts_with($path, '/api/')) { require $root . '/api.php'; return true; }
if (str_starts_with($path, '/media/')) { require $root . '/media.php'; return true; }
$file = realpath($root . $path);
if ($path !== '/' && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) return false;
if (is_file($root . '/index.html')) { header('Content-Type: text/html; charset=utf-8'); readfile($root . '/index.html'); return true; }
http_response_code(404); echo 'Run npm run dev for the frontend, or npm run build.';
