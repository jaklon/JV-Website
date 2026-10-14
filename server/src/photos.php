<?php
declare(strict_types=1);

const PHOTOS_SCOPE = 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly';

final class PhotosException extends RuntimeException {}

function photos_google_url(string $url, bool $picker = false): bool
{
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
    $host = strtolower($parts['host'] ?? '');
    return $picker ? $host === 'photos.google.com' : (bool)preg_match('/^(?:[a-z0-9-]+\.)+googleusercontent\.com$/D', $host);
}

function photos_polling(array $session): array
{
    $duration = static function ($value, float $fallback): float {
        return is_string($value) && preg_match('/^([0-9]+(?:\.[0-9]{1,9})?)s$/D', $value, $match) ? (float)$match[1] : $fallback;
    };
    return ['pollMs' => (int)(max(1, $duration($session['pollingConfig']['pollInterval'] ?? null, 3)) * 1000),
        'timeoutMs' => (int)(min(600, $duration($session['pollingConfig']['timeoutIn'] ?? null, 600)) * 1000)];
}

function photos_bound_identity(array $oauth, array $claims, ?array $user, ?string $currentId): bool
{
    return $user && $currentId === ($oauth['user_id'] ?? null) && $user['id'] === $currentId
        && hash_equals((string)$user['google_sub'], (string)($claims['sub'] ?? ''));
}

function photos_connection(array $user): ?array
{
    $connection = $_SESSION['photos_connection'] ?? null;
    if (!$connection || $connection['user_id'] !== $user['id'] || $connection['expires'] <= time() + 30) {
        unset($_SESSION['photos_connection'], $_SESSION['photos_sessions']); return null;
    }
    return $connection;
}

function photos_request(string $token, string $method, string $path, ?array $body = null): array
{
    $curl = curl_init('https://photospicker.googleapis.com/v1' . $path);
    $data = ''; $oversized = false;
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data, &$oversized): int {
            if (strlen($data) + strlen($chunk) > 1024 * 1024) { $oversized = true; return 0; }
            $data .= $chunk; return strlen($chunk);
        }]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($ok === false || $oversized) throw new PhotosException('Google Photos tidak merespons. Periksa koneksi lalu coba lagi.');
    if ($status === 401) {
        unset($_SESSION['photos_connection'], $_SESSION['photos_sessions']);
        throw new PhotosException('Izin Google Photos berakhir. Hubungkan kembali.');
    }
    if ($status === 403) throw new PhotosException('Google Photos belum diizinkan. Aktifkan Google Photos Picker API di project Google Cloud dan izinkan akses foto saat menghubungkan akun.');
    if ($status === 404) throw new PhotosException('Pilihan Google Photos sudah kedaluwarsa. Mulai pemilihan lagi.');
    if ($status === 429) throw new PhotosException('Google Photos sedang membatasi permintaan. Tunggu sebentar lalu coba lagi.');
    if ($status < 200 || $status >= 300) throw new PhotosException('Permintaan ke Google Photos gagal. Coba lagi.');
    return $data === '' ? [] : json_decode($data, true, 32, JSON_THROW_ON_ERROR);
}

function photos_item(array $item): array
{
    $file = $item['mediaFile'] ?? [];
    $video = ($item['type'] ?? '') === 'VIDEO';
    if (!in_array($item['type'] ?? '', ['PHOTO', 'VIDEO'], true) || !photos_google_url((string)($file['baseUrl'] ?? ''))) {
        throw new PhotosException('Google Photos mengembalikan media yang tidak didukung.');
    }
    $name = mb_substr(str_replace(["\r", "\n", "\0", '/', '\\'], '_', (string)($file['filename'] ?? ($video ? 'video.mp4' : 'foto.jpg'))), 0, 200);
    return ['id' => uid(), 'name' => $name, 'kind' => $video ? 'video' : 'image', 'base_url' => $file['baseUrl'],
        'ready' => !$video || ($file['mediaFileMetadata']['videoMetadata']['processingStatus'] ?? '') === 'READY'];
}

function photos_public_item(array $item): array
{
    return array_intersect_key($item, array_flip(['id', 'name', 'kind', 'ready']));
}

/** Download only a server-selected Google URL, with bounded size and manual, validated redirects. */
function photos_download(string $token, array $item)
{
    if (!$item['ready']) throw new PhotosException('Video masih diproses Google Photos. Coba pilih video ini lagi setelah selesai diproses.');
    $url = $item['base_url'] . ($item['kind'] === 'video' ? '=dv' : '=d');
    $limit = ($item['kind'] === 'video' ? 50 : 10) * 1024 * 1024;
    $file = tmpfile();
    if (!$file) throw new PhotosException('Hosting tidak dapat menyiapkan file sementara.');
    try {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if (!photos_google_url($url)) throw new PhotosException('Alamat media Google Photos tidak valid.');
            ftruncate($file, 0); rewind($file); $size = 0; $location = null; $tooLarge = false;
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                    if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                    return strlen($header);
                }, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($file, $limit, &$size, &$tooLarge): int {
                    $size += strlen($chunk);
                    if ($size > $limit) { $tooLarge = true; return 0; }
                    return fwrite($file, $chunk);
                }]);
            $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
            if ($tooLarge) throw new PhotosException('File melebihi batas: foto 10 MB atau video 50 MB. Pilih file yang lebih kecil.');
            if ($ok === false) throw new PhotosException('Pengambilan file terputus. Coba lagi atau unduh dari Google Photos lalu unggah manual.');
            if (in_array($status, [301,302,303,307,308], true) && $location !== null) { $url = $location; continue; }
            if ($status !== 200 || $size < 1) throw new PhotosException('File Google Photos belum bisa diambil. Pilih ulang file tersebut.');
            $path = stream_get_meta_data($file)['uri'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            $formats = $item['kind'] === 'video' ? ['video/mp4' => 'mp4'] : ['image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp'];
            if (!isset($formats[$mime]) || ($item['kind'] === 'image' && !getimagesize($path))) {
                throw new PhotosException('Format file tidak didukung. Gunakan foto JPEG/PNG/WebP atau video MP4; ubah HEIC/RAW terlebih dahulu.');
            }
            rewind($file);
            return ['stream' => $file, 'size' => $size, 'mime' => $mime, 'extension' => $formats[$mime]];
        }
        throw new PhotosException('Pengambilan media dialihkan terlalu banyak. Coba lagi.');
    } catch (Throwable $error) { fclose($file); throw $error; }
}

function photos_routes(string $path, string $method, array $user): void
{
    if ($path === '/photos/auth' && $method === 'GET') auth_start(true);
    if ($path === '/photos/status' && $method === 'GET') {
        respond(['connected' => photos_connection($user) !== null, 'configured' => (bool)google_configured(), 'authError' => $_SESSION['photos_auth_error'] ?? null]);
    }
    if ($path === '/photos/prepare' && $method === 'POST') {
        // Clear a previous denial before displaying the fallback link for blocked popups.
        unset($_SESSION['photos_auth_error']); respond(['ok' => true]);
    }
    if (!str_starts_with($path, '/photos/sessions')) return;
    $connection = photos_connection($user);
    if (!$connection) throw new PhotosException('Hubungkan Google Photos terlebih dahulu.');
    foreach ($_SESSION['photos_sessions'] ?? [] as $id => $entry) {
        if ($entry['expires'] <= time()) unset($_SESSION['photos_sessions'][$id]);
    }
    if ($path === '/photos/sessions' && $method === 'POST') {
        if (count($_SESSION['photos_sessions'] ?? []) >= 3) throw new PhotosException('Selesaikan atau batalkan pemilihan Google Photos yang sedang terbuka.');
        $session = photos_request($connection['token'], 'POST', '/sessions', ['pickingConfig' => ['maxItemCount' => '20']]);
        if (empty($session['id']) || !photos_google_url((string)($session['pickerUri'] ?? ''), true)) throw new PhotosException('Pemilih Google Photos tidak tersedia.');
        $id = uid();
        $_SESSION['photos_sessions'][$id] = ['google_id' => $session['id'], 'expires' => min(time() + 3600, strtotime($session['expireTime'] ?? '') ?: time() + 3600), 'items' => null];
        respond(['id' => $id, 'pickerUri' => rtrim($session['pickerUri'], '/') . '/autoclose', ...photos_polling($session)], 201);
    }
    if (!preg_match('#^/photos/sessions/([a-f0-9]{32})(?:/files/([a-f0-9]{32}))?$#D', $path, $match)) return;
    $id = $match[1]; $session = $_SESSION['photos_sessions'][$id] ?? null;
    if (!$session) fail('Pemilihan Google Photos tidak ditemukan atau kedaluwarsa.', 404);
    $googlePath = '/sessions/' . rawurlencode($session['google_id']);
    if (!isset($match[2]) && $method === 'DELETE') {
        unset($_SESSION['photos_sessions'][$id]);
        try { photos_request($connection['token'], 'DELETE', $googlePath); } catch (Throwable $error) { error_log('JV Story Photos: picker cleanup failed'); }
        respond(['ok' => true]);
    }
    if (!isset($match[2]) && $method === 'GET') {
        if ($session['items'] !== null) respond(['ready' => true, 'items' => array_map('photos_public_item', $session['items'])]);
        $remote = photos_request($connection['token'], 'GET', $googlePath);
        if (empty($remote['mediaItemsSet'])) respond(['ready' => false, ...photos_polling($remote)]);
        $items = []; $page = '';
        do {
            $response = photos_request($connection['token'], 'GET', '/mediaItems?' . http_build_query(['sessionId' => $session['google_id'], 'pageSize' => 100, 'pageToken' => $page]));
            foreach ($response['mediaItems'] ?? [] as $item) {
                if (count($items) >= 20) throw new PhotosException('Pilih maksimal 20 file sekali impor.');
                $items[] = photos_item($item);
            }
            $page = $response['nextPageToken'] ?? '';
            // An empty paginated response must not create an unbounded polling loop.
            if ($page !== '' && empty($response['mediaItems'])) throw new PhotosException('Daftar Google Photos belum tersedia. Mulai pemilihan lagi.');
        } while ($page !== '');
        $_SESSION['photos_sessions'][$id]['items'] = $items;
        respond(['ready' => true, 'items' => array_map('photos_public_item', $items)]);
    }
    if (isset($match[2]) && $method === 'GET') {
        $item = null;
        foreach ($session['items'] ?? [] as $candidate) if ($candidate['id'] === $match[2]) $item = $candidate;
        if (!$item) fail('File tidak termasuk pilihan Google Photos kamu.', 404);
        // Release the session lock so cancel/logout and other requests stay responsive.
        session_write_close();
        $download = photos_download($connection['token'], $item);
        header('Content-Type: ' . $download['mime']); header('Content-Length: ' . $download['size']);
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(pathinfo($item['name'], PATHINFO_FILENAME) . '.' . $download['extension']));
        try { fpassthru($download['stream']); } finally { fclose($download['stream']); }
        exit;
    }
}
