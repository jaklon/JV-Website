<?php
declare(strict_types=1);
$privateRoot = is_file(dirname(__DIR__) . '/src/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__, 3) . '/jv_story_private';
require $privateRoot . '/src/bootstrap.php';
try {
    require_user();
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) fail('Metode tidak diizinkan.',405);
    $path = substr(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),strlen(app_path()));
    if (!preg_match('#^/media/([a-f0-9]{32})$#D',$path,$matches)) fail('Media tidak ditemukan.',404);
    $row = query('SELECT * FROM media WHERE id=?',[$matches[1]])->fetch();
    if (!$row || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|mp4|mp3)$/D',$row['filename'])) fail('Media tidak ditemukan.',404);
    $file = server_root() . '/storage/' . $row['filename'];
    if (!is_file($file)) fail('File tidak tersedia.',404);
    session_write_close();
    $size = filesize($file); $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$parts) || ($parts[1] === '' && $parts[2] === '')) { header("Content-Range: bytes */$size"); http_response_code(416); exit; }
        if ($parts[1] === '') { $suffix = (int)$parts[2]; if ($suffix < 1) { header("Content-Range: bytes */$size"); http_response_code(416); exit; } $start = max(0,$size-$suffix); }
        else { $start = (int)$parts[1]; if ($parts[2] !== '') $end = min($end,(int)$parts[2]); }
        if ($start > $end || $start >= $size) { header("Content-Range: bytes */$size"); http_response_code(416); exit; }
        http_response_code(206); header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Type: ' . $row['mime']); header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store'); header('Accept-Ranges: bytes');
    header("Content-Length: " . ($end-$start+1));
    header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($row['name']));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    $stream = fopen($file,'rb'); fseek($stream,$start); $left = $end-$start+1;
    while ($left > 0 && !feof($stream) && !connection_aborted()) { $chunk = fread($stream,min(65536,$left)); if ($chunk === false) break; echo $chunk; $left -= strlen($chunk); }
    fclose($stream);
} catch (Throwable $error) { error_log('JV Story media: ' . get_class($error)); fail('Media tidak dapat dibuka.',503); }
