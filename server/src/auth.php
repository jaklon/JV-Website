<?php
declare(strict_types=1);
function auth_start(bool $photos = false): never
{
    if (!google_configured()) fail('Login Google belum dikonfigurasi. Isi Client ID dan Client Secret di konfigurasi server.', 503);
    $_SESSION['oauth'] = ['state' => bin2hex(random_bytes(32)), 'nonce' => bin2hex(random_bytes(32)), 'verifier' => bin2hex(random_bytes(32)), 'expires' => time() + 600];
    $oauth = $_SESSION['oauth'];
    if ($photos) {
        $user = require_user();
        $_SESSION['oauth']['purpose'] = 'photos';
        $_SESSION['oauth']['user_id'] = $user['id'];
        unset($_SESSION['photos_auth_error']);
    }
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $oauth['verifier'], true)), '+/', '-_'), '=');
    $params = ['client_id' => config('google_client_id'), 'redirect_uri' => config('google_redirect_uri'), 'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $oauth['state'], 'nonce' => $oauth['nonce'], 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'prompt' => 'select_account'];
    if ($photos) {
        $params['scope'] .= ' ' . PHOTOS_SCOPE;
        $params['login_hint'] = $user['email'];
        $params['prompt'] = 'consent';
    }
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params)); exit;
}
function auth_callback(): never
{
    $oauth = $_SESSION['oauth'] ?? null; unset($_SESSION['oauth']);
    $error = 'oauth_failed';
    try {
        if (!google_configured() || !$oauth || $oauth['expires'] < time() || !is_string($_GET['state'] ?? null)
            || !hash_equals($oauth['state'], $_GET['state']) || !is_string($_GET['code'] ?? null)) throw new RuntimeException('Invalid OAuth state');
        $curl = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['code' => $_GET['code'], 'client_id' => config('google_client_id'), 'client_secret' => config('google_client_secret'), 'redirect_uri' => config('google_redirect_uri'), 'grant_type' => 'authorization_code', 'code_verifier' => $oauth['verifier']]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($response === false || $status !== 200) throw new RuntimeException('Google token exchange failed');
        $tokens = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        $client = new Google\Client(['client_id' => config('google_client_id')]);
        $claims = $client->verifyIdToken($tokens['id_token'] ?? '');
        if (!$claims || !hash_equals($oauth['nonce'], (string)($claims['nonce'] ?? ''))) throw new RuntimeException('Invalid Google token');
        if (!permitted_google_identity($claims, config('allowed_emails'))) { $error = 'account_denied'; throw new RuntimeException('Google account not allowlisted'); }
        if (($oauth['purpose'] ?? '') === 'photos') {
            $user = query('SELECT id,google_sub FROM users WHERE id=?', [$oauth['user_id']])->fetch();
            if (!photos_bound_identity($oauth, $claims, $user ?: null, $_SESSION['user_id'] ?? null)) {
                $error = 'wrong_account'; throw new RuntimeException('Photos account differs from current login');
            }
            if (!in_array(PHOTOS_SCOPE, explode(' ', (string)($tokens['scope'] ?? '')), true) || empty($tokens['access_token'])) {
                $error = 'permission_denied'; throw new RuntimeException('Photos permission missing');
            }
            $_SESSION['photos_connection'] = ['user_id' => $user['id'], 'token' => $tokens['access_token'], 'expires' => time() + min(3600, max(0, (int)($tokens['expires_in'] ?? 0)))];
            unset($_SESSION['photos_auth_error']);
            photos_auth_result(true);
        }
        $email = strtolower($claims['email']);
        $existing = query('SELECT id FROM users WHERE google_sub=?', [$claims['sub']])->fetch();
        $id = $existing['id'] ?? uid();
        $name = mb_substr((string)($claims['name'] ?? explode('@', $email)[0]), 0, 100);
        $picture = filter_var($claims['picture'] ?? '', FILTER_VALIDATE_URL) && str_starts_with($claims['picture'], 'https://') ? $claims['picture'] : null;
        if ($existing) query('UPDATE users SET email=?,name=?,picture=? WHERE id=?', [$email,$name,$picture,$id]);
        else query('INSERT INTO users (id,google_sub,email,name,picture) VALUES (?,?,?,?,?)', [$id,$claims['sub'],$email,$name,$picture]);
        unset($_SESSION['photos_connection'], $_SESSION['photos_sessions'], $_SESSION['photos_auth_error']);
        session_regenerate_id(true); $_SESSION['user_id'] = $id; $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: ' . rtrim(config('app_url'), '/') . app_path() . '/'); exit;
    } catch (Throwable $exception) {
        error_log('JV Story OAuth: ' . get_class($exception));
        if (($oauth['purpose'] ?? '') === 'photos') {
            $_SESSION['photos_auth_error'] = match ($error) {
                'wrong_account' => 'Pilih Gmail yang sama dengan akun yang sedang masuk di JV Story.',
                'account_denied' => 'Akun Google ini tidak diizinkan masuk ke JV Story.',
                'permission_denied' => 'Izin memilih foto/video belum diberikan. Hubungkan lagi dan centang izin Google Photos.',
                default => 'Koneksi Google Photos dibatalkan atau gagal. Kamu bisa mencoba lagi.'
            };
            photos_auth_result(false);
        }
        header('Location: ' . rtrim(config('app_url'), '/') . app_path() . '/?auth_error=' . $error); exit;
    }
}

function photos_auth_result(bool $ok): never
{
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
    $message = $ok ? 'Google Photos sudah terhubung. Kembali ke tab JV Story untuk melanjutkan memilih foto dan video.'
        : (string)($_SESSION['photos_auth_error'] ?? 'Koneksi Google Photos gagal.');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google Photos · JV Story</title><style>body{font:18px/1.7 system-ui;background:#fbf7f1;color:#44392f;padding:12vh 8vw;max-width:600px;margin:auto}h1{font-family:Georgia;font-weight:400}</style><h1>JV Story</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></html>';
    exit;
}
