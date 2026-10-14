<?php
declare(strict_types=1);
require_once __DIR__ . '/policy.php';
date_default_timezone_set('Asia/Jakarta');
$configPath = getenv('JV_STORY_CONFIG') ?: getenv('OUR_STORY_CONFIG') ?: dirname(__DIR__) . '/config.local.php';
$config = is_file($configPath) ? require $configPath : require dirname(__DIR__) . '/config.example.php';
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) require_once dirname(__DIR__) . '/vendor/autoload.php';

function config(string $key): mixed { global $config; return $config[$key] ?? null; }
function server_root(): string { return dirname(__DIR__); }
function app_path(): string { return rtrim((string)(config('base_path') ?? ''), '/'); }
function cookie_path(): string { return app_path() . '/'; }
function db(): PDO
{
    static $connection;
    if (!$connection) $connection = new PDO(config('db_dsn'), config('db_user'), config('db_password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    return $connection;
}
function query(string $sql, array $params = []): PDOStatement { $statement = db()->prepare($sql); $statement->execute($params); return $statement; }
function uid(): string { return bin2hex(random_bytes(16)); }
function respond(mixed $data, int $status = 200): never
{
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}
function fail(string $error, int $status): never { respond(['error' => $error], $status); }
function body(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 * 1024) fail('Formulir terlalu besar.', 413);
    try { $data = json_decode(file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { fail('Format JSON tidak valid.', 400); }
    if (!is_array($data) || array_is_list($data)) fail('Formulir tidak valid.', 400);
    return $data;
}
function require_user(): array
{
    if (empty($_SESSION['user_id'])) fail('Silakan masuk dengan Google.', 401);
    $user = query('SELECT id,google_sub,name,email,picture FROM users WHERE id=?', [$_SESSION['user_id']])->fetch();
    if (!$user || !in_array(strtolower($user['email']), array_map('strtolower', config('allowed_emails')), true)) {
        unset($_SESSION['user_id']); fail('Akun ini tidak diizinkan.', 401);
    }
    unset($user['google_sub']); return $user;
}
function csrf(): void
{
    if (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'])) fail('Sesi formulir kedaluwarsa. Muat ulang halaman.', 403);
}
function require_owner(string $table, string $id, array $user): array
{
    $tables = ['memories','media','letters','important_dates','movie_projects'];
    if (!in_array($table, $tables, true)) throw new LogicException('Invalid table');
    $row = query("SELECT * FROM $table WHERE id=?", [$id])->fetch();
    if (!$row) fail('Konten tidak ditemukan.', 404);
    if ($row['user_id'] !== $user['id']) fail('Hanya pembuat yang bisa mengubah konten ini.', 403);
    return $row;
}
function public_media(array $row): array
{
    unset($row['filename'], $row['mime']); $row['url'] = app_path() . '/media/' . $row['id'];
    $row['size'] = (int)$row['size']; $row['duration'] = $row['duration'] !== null ? (float)$row['duration'] : null;
    return $row;
}
function remove_stored_file(string $filename): void
{
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|mp4|mp3)$/D', $filename)) return;
    $path = dirname(__DIR__) . '/storage/' . $filename;
    if (is_file($path)) unlink($path);
}
function google_configured(): bool { return config('google_client_id') && config('google_client_secret') && class_exists('Google\\Client'); }

ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
session_name('jv_story');
session_set_cookie_params(['lifetime' => 0, 'path' => cookie_path(), 'secure' => (bool)config('secure_cookies'), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 86400) unset($_SESSION['user_id']);
$_SESSION['last_seen'] = time();
