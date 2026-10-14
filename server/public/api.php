<?php
declare(strict_types=1);
$privateRoot = is_file(dirname(__DIR__) . '/src/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__, 3) . '/jv_story_private';
require $privateRoot . '/src/bootstrap.php';
require $privateRoot . '/src/auth.php';
require $privateRoot . '/src/photos.php';
require $privateRoot . '/src/chat.php';

try {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $prefix = app_path() . '/api';
    $path = str_starts_with($requestPath, $prefix . '/') ? substr($requestPath, strlen($prefix)) : '';
    $method = $_SERVER['REQUEST_METHOD'];
    if ($path === '/session' && $method === 'GET') {
        $user = !empty($_SESSION['user_id']) ? require_user() : null;
        respond(['user' => $user, 'csrf' => $_SESSION['csrf'], 'configured' => (bool)google_configured()]);
    }
    if ($path === '/auth/google' && $method === 'GET') auth_start();
    if ($path === '/auth/callback' && $method === 'GET') auth_callback();
    $user = require_user();
    if ($method !== 'GET') csrf();
    photos_routes($path, $method, $user);
    chat_routes($path, $method, $user);
    if ($path === '/auth/logout' && $method === 'POST') {
        $_SESSION = []; session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => cookie_path(), 'secure' => (bool)config('secure_cookies'), 'httponly' => true, 'samesite' => 'Lax']);
        respond(['ok' => true]);
    }
    if ($path === '/bootstrap' && $method === 'GET') {
        $memories = query('SELECT * FROM memories ORDER BY date DESC,created_at DESC,id DESC')->fetchAll();
        $media = array_map('public_media', query('SELECT * FROM media ORDER BY position,created_at,id')->fetchAll());
        foreach ($memories as &$memory) $memory['media'] = array_values(array_filter($media, static fn($m) => $m['memory_id'] === $memory['id']));
        unset($memory);
        $dates = query('SELECT * FROM important_dates ORDER BY date')->fetchAll();
        foreach ($dates as &$date) $date['recurring'] = (bool)$date['recurring']; unset($date);
        $projects = query('SELECT * FROM movie_projects ORDER BY updated_at DESC')->fetchAll();
        foreach ($projects as &$project) $project['recipe'] = json_decode($project['recipe'], true, 64, JSON_THROW_ON_ERROR); unset($project);
        $settings = query('SELECT names,since,tagline,cover_id FROM relationship_settings WHERE id=1')->fetch();
        if (!$settings) fail('Tabel belum disiapkan. Jalankan installer database.', 503);
        respond(['users' => query('SELECT id,name,email,picture FROM users')->fetchAll(), 'settings' => $settings,
            'memories' => $memories, 'albums' => query('SELECT * FROM albums ORDER BY created_at DESC')->fetchAll(),
            'letters' => query("SELECT * FROM letters WHERE status='sent' OR user_id=? ORDER BY created_at DESC", [$user['id']])->fetchAll(),
            'dates' => $dates, 'projects' => $projects, 'audio' => array_values(array_filter($media, static fn($m) => $m['kind'] === 'audio'))]);
    }
    if ($path === '/settings' && $method === 'PATCH') {
        $data = body(); $since = $data['since'] ?? null;
        if ($since !== null && (!is_string($since) || !valid_date($since))) fail('Tanggal mulai hubungan tidak valid.', 422);
        $cover = $data['cover_id'] ?? null;
        if ($cover !== null && (!is_string($cover) || !query("SELECT id FROM media WHERE id=? AND kind='image'", [$cover])->fetch())) fail('Foto sampul tidak ditemukan.', 422);
        query('UPDATE relationship_settings SET names=?,since=?,tagline=?,cover_id=? WHERE id=1', [checked_text($data['names'] ?? null,'Nama kalian',100), $since, checked_text($data['tagline'] ?? null,'Tagline',250),$cover]);
        respond(['ok' => true]);
    }
    if ($path === '/media' && $method === 'POST') {
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) fail('Upload gagal. Periksa batas upload PHP/hosting.', 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types = ['image/jpeg' => ['image','jpg',10], 'image/png' => ['image','png',10], 'image/webp' => ['image','webp',10], 'video/mp4' => ['video','mp4',50], 'audio/mpeg' => ['audio','mp3',10]];
        if (!isset($types[$mime])) fail('Gunakan JPEG, PNG, WebP, MP4, atau MP3.', 422);
        [$kind,$extension,$limit] = $types[$mime];
        if ($file['size'] > $limit * 1024 * 1024 || $file['size'] < 1) fail("File maksimal $limit MB.", 413);
        if ($kind === 'image' && !getimagesize($file['tmp_name'])) fail('Foto tidak valid.', 422);
        $memoryId = $_POST['memory_id'] ?? null;
        if ($kind === 'audio') $memoryId = null;
        else {
            if (!is_string($memoryId) || $memoryId === '') fail('Pilih kenangan untuk media ini.', 422);
            require_owner('memories', $memoryId, $user);
        }
        $duration = null;
        if ($kind !== 'image') {
            $duration = filter_var($_POST['duration'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($duration === false || $duration <= 0 || $duration > 86400) fail('Durasi media tidak valid.', 422);
        }
        $id = uid(); $filename = "$id.$extension"; $storage = server_root() . '/storage';
        if (!is_dir($storage) && !mkdir($storage, 0700, true)) throw new RuntimeException('Cannot create private storage');
        if (!move_uploaded_file($file['tmp_name'], "$storage/$filename")) throw new RuntimeException('Cannot store upload');
        try {
            $position = (int)query('SELECT COALESCE(MAX(position),-1)+1 FROM media WHERE memory_id=?', [$memoryId])->fetchColumn();
            query('INSERT INTO media (id,memory_id,user_id,kind,name,filename,mime,size,duration,position) VALUES (?,?,?,?,?,?,?,?,?,?)', [$id,$memoryId,$user['id'],$kind,mb_substr(basename($file['name']),0,255),$filename,$mime,$file['size'],$duration,$position]);
        } catch (Throwable $error) { remove_stored_file($filename); throw $error; }
        respond(public_media(query('SELECT * FROM media WHERE id=?', [$id])->fetch()), 201);
    }
    if (preg_match('#^/media/([a-f0-9]{32})$#D', $path, $matches) && $method === 'DELETE') {
        $row = require_owner('media',$matches[1],$user);
        query('DELETE FROM media WHERE id=?',[$row['id']]); remove_stored_file($row['filename']);
        query('UPDATE relationship_settings SET cover_id=NULL WHERE cover_id=?',[$row['id']]); respond(['ok'=>true]);
    }
    if (preg_match('#^/(memories|albums|letters|dates|projects)(?:/([a-f0-9]{32}))?$#D', $path, $matches)) {
        $resource = $matches[1]; $id = $matches[2] ?? null;
        $table = ['memories'=>'memories','albums'=>'albums','letters'=>'letters','dates'=>'important_dates','projects'=>'movie_projects'][$resource];
        if ($id && in_array($method, ['PATCH','DELETE'], true)) {
            if ($resource === 'albums') { if (!query('SELECT id FROM albums WHERE id=?',[$id])->fetch()) fail('Album tidak ditemukan.',404); }
            else require_owner($table,$id,$user);
        }
        if ($id && $method === 'DELETE') {
            $files = $resource === 'memories' ? query('SELECT filename,id FROM media WHERE memory_id=?',[$id])->fetchAll() : [];
            db()->beginTransaction();
            try {
                foreach ($files as $file) query('UPDATE relationship_settings SET cover_id=NULL WHERE cover_id=?',[$file['id']]);
                query("DELETE FROM $table WHERE id=?",[$id]); db()->commit();
            } catch (Throwable $error) { db()->rollBack(); throw $error; }
            foreach ($files as $file) remove_stored_file($file['filename']);
            respond(['ok'=>true]);
        }
        if (($method === 'POST' && !$id) || ($method === 'PATCH' && $id)) {
            $data = body(); $fields = []; $newId = $id ?? uid();
            if ($resource === 'memories') {
                $date = $data['date'] ?? ''; if (!is_string($date) || !valid_date($date)) fail('Tanggal kenangan tidak valid.',422);
                $album = $data['album_id'] ?? null;
                if ($album !== null && (!is_string($album) || !query('SELECT id FROM albums WHERE id=?',[$album])->fetch())) fail('Album tidak ditemukan.',422);
                $fields = ['title'=>checked_text($data['title'] ?? null,'Judul',150),'date'=>$date,'location'=>checked_text($data['location'] ?? '', 'Lokasi',180,false),'story'=>checked_text($data['story'] ?? '', 'Cerita',30000,false),'album_id'=>$album];
            } elseif ($resource === 'albums') {
                $fields = ['title'=>checked_text($data['title'] ?? null,'Nama album',120),'description'=>checked_text($data['description'] ?? '', 'Deskripsi',5000,false)];
            } elseif ($resource === 'letters') {
                $status = $data['status'] ?? 'draft'; if (!in_array($status,['draft','sent'],true)) fail('Status surat tidak valid.',422);
                $existing = $id ? query('SELECT status,sent_at FROM letters WHERE id=?',[$id])->fetch() : null;
                if ($existing && $existing['status'] === 'sent' && $status === 'draft') fail('Surat terkirim tidak bisa dikembalikan menjadi draf.',422);
                $fields = ['title'=>checked_text($data['title'] ?? null,'Judul surat',150),'body'=>checked_text($data['body'] ?? null,'Isi surat',30000),'status'=>$status,'sent_at'=>$status === 'sent' ? ($existing['sent_at'] ?? date('Y-m-d H:i:s')) : null];
            } elseif ($resource === 'dates') {
                $date = $data['date'] ?? ''; if (!is_string($date) || !valid_date($date)) fail('Tanggal tidak valid.',422);
                if (!is_bool($data['recurring'] ?? null)) fail('Pilihan pengulangan tidak valid.',422);
                $fields = ['title'=>checked_text($data['title'] ?? null,'Nama acara',120),'date'=>$date,'recurring'=>(int)$data['recurring']];
            } else {
                if (!is_array($data['recipe'] ?? null)) fail('Proyek tidak valid.',422);
                $fields = ['title'=>checked_text($data['title'] ?? null,'Nama film',150),'recipe'=>json_encode(checked_recipe($data['recipe'],query('SELECT * FROM media')->fetchAll()),JSON_THROW_ON_ERROR)];
            }
            if ($id) {
                $assignments = implode(',',array_map(static fn($key)=>"$key=?",array_keys($fields)));
                query("UPDATE $table SET $assignments WHERE id=?", [...array_values($fields),$id]);
            } else {
                $fields = ['id'=>$newId,'user_id'=>$user['id'],...$fields];
                $columns = implode(',',array_keys($fields)); $placeholders = implode(',',array_fill(0,count($fields),'?'));
                query("INSERT INTO $table ($columns) VALUES ($placeholders)",array_values($fields));
            }
            respond(['id'=>$newId],$id ? 200 : 201);
        }
    }
    fail('Endpoint tidak ditemukan.',404);
} catch (PhotosException $error) { fail($error->getMessage(),422); }
catch (InvalidArgumentException $error) { fail($error->getMessage(),422); }
catch (Throwable $error) { error_log('JV Story API: ' . $error->getMessage()); fail('Server belum siap atau koneksi database bermasalah. Periksa konfigurasi dan log server.',503); }
