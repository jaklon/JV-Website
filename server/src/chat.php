<?php
declare(strict_types=1);

final class ChatError extends RuntimeException {
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}
function chat_config(): array {
    $path = server_root() . '/chat.config.php';
    return is_file($path) ? require $path : [];
}
function chat_ready(array $config): bool {
    $url = $config['service_url'] ?? '';
    if (!preg_match('#^https://[a-z0-9-]+(?:\.[a-z0-9-]+)*\.run\.app$#D', $url) || !is_file($config['credentials_file'] ?? '')) return false;
    if (!is_file($config['readiness_file'] ?? '')) return false;
    $report = json_decode((string)file_get_contents($config['readiness_file']), true);
    return is_array($report) && ($report['service_url'] ?? '') === $url
        && ($report['quality_pass'] ?? false) === true && ($report['cost_pass'] ?? false) === true
        && ($report['runtime_pass'] ?? false) === true && ($report['iam_pass'] ?? false) === true
        && ($report['model'] ?? '') === 'qwen3-0.6b-q4_k_m';
}
function chat_installed(): bool {
    try { query('SELECT bucket_key FROM chat_usage LIMIT 1'); return true; }
    catch (PDOException $e) { return false; }
}
function chat_status(): array {
    $config = chat_config(); $installed = chat_installed();
    $enabled = $installed && ($config['enabled'] ?? false) === true && chat_ready($config);
    $counts = [];
    if ($installed) foreach (query('SELECT bucket_key,used FROM chat_usage WHERE bucket_key IN (?,?)', ['day:'.date('Y-m-d'), 'month:'.date('Y-m')])->fetchAll() as $row) $counts[$row['bucket_key']] = (int)$row['used'];
    return ['enabled'=>$enabled, 'installed'=>$installed, 'model'=>'Qwen3 · 0.6B',
        'reason'=>$enabled ? '' : 'Chat sedang disiapkan. Kenangan kalian tetap dapat dibuka.',
        'daily_remaining'=>max(0,20-($counts['day:'.date('Y-m-d')]??0)),
        'monthly_remaining'=>max(0,600-($counts['month:'.date('Y-m')]??0)),
        'shared'=>true];
}
function chat_source(string $kind, string $id, string $title, string $text, string $date=''): array {
    return ['key'=>$kind.':'.$id, 'kind'=>$kind, 'id'=>$id, 'title'=>$title, 'text'=>mb_substr($text,0,1600), 'date'=>$date];
}
function chat_terms(string $text): array {
    $stop = ['yang','dan','atau','untuk','dari','dengan','kita','kami','aku','saya','kamu','apa','kapan','mana','ada','cerita','kenangan','tentang','tolong','ingin','sudah','pernah','bulan','tahun','lalu','kemarin','ini','hari','tidak','bisa','berapa','itu','bagaimana','pada','saat','waktu'];
    $words = preg_split('/[^\pL\pN]+/u',mb_strtolower($text),-1,PREG_SPLIT_NO_EMPTY);
    $words = array_map(static function($word){
        if(str_ends_with($word,'nya'))$word=mb_substr($word,0,-3);
        return match($word){'membawa','dibawa'=>'bawa','makanan','memakan','dimakan'=>'makan',default=>$word};
    },$words);
    return array_slice(array_values(array_unique(array_filter($words,static fn($w)=>mb_strlen($w)>=3 && !in_array($w,$stop,true)))),0,6);
}
function chat_date_range(string $text): ?array {
    $lower = mb_strtolower($text);
    if (str_contains($lower,'bulan lalu')) return [date('Y-m-01',strtotime('first day of last month')),date('Y-m-t',strtotime('first day of last month'))];
    if (str_contains($lower,'bulan ini')) return [date('Y-m-01'),date('Y-m-t')];
    if (str_contains($lower,'tahun lalu')) return [(date('Y')-1).'-01-01',(date('Y')-1).'-12-31'];
    if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/',$text,$m) && valid_date($m[1])) return [$m[1],$m[1]];
    $months=['januari'=>1,'februari'=>2,'maret'=>3,'april'=>4,'mei'=>5,'juni'=>6,'juli'=>7,'agustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'desember'=>12];
    foreach($months as $name=>$number) if(preg_match('/\b'.$name.'\b/u',$lower)) {
        $year=preg_match('/\b(20\d{2})\b/',$lower,$m)?(int)$m[1]:(int)date('Y');
        $start=sprintf('%04d-%02d-01',$year,$number);return [$start,date('Y-m-t',strtotime($start))];
    }
    if (preg_match('/\b(20\d{2})\b/',$text,$m)) return [$m[1].'-01-01',$m[1].'-12-31'];
    return null;
}
function chat_retrieve(string $question, string $previous=''): array {
    $terms = chat_terms($question); $range = chat_date_range($question);
    // Only inherit the previous question for an explicit follow-up; no bulk export.
    if (preg_match('/\b(itu|tadi|tersebut|lanjut)\b|\pL+nya\b/u',mb_strtolower($question))) $terms = array_slice(array_values(array_unique(array_merge($terms,chat_terms($previous)))),0,10);
    $sources = [];
    $profile = query('SELECT names,since,tagline FROM relationship_settings WHERE id=1')->fetch();
    if ($profile) $sources[] = chat_source('profile','1','Tentang kita',"Nama: {$profile['names']}. Mulai hubungan: ".($profile['since']?:'belum diisi').". Tagline: {$profile['tagline']}",$profile['since']??'');
    $specs = [
        ['memories','memory','title,story,location','story','date',''],
        ['albums','album','title,description','description','created_at',''],
        ['letters','letter','title,body','body','sent_at',"status='sent'"],
        ['important_dates','date','title','title','date',''],
    ];
    foreach ($specs as [$table,$kind,$search,$content,$dateColumn,$mandatory]) {
        $where = $mandatory ? [$mandatory] : []; $params = []; $or = [];
        $tableTerms=$terms;
        // Month/year words constrain the date column; requiring them in the
        // story/title as well would hide otherwise matching dated memories.
        if($range&&$kind==='memory')$tableTerms=array_values(array_filter($terms,static fn($term)=>!preg_match('/^20\d{2}$/D',$term)&&!in_array($term,['januari','februari','maret','april','mei','juni','juli','agustus','september','oktober','november','desember'],true)));
        foreach ($tableTerms as $term) {
            foreach (explode(',',$search) as $column) { $or[]="$column LIKE ? ESCAPE '!'"; $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%'; }
        }
        if ($or) $where[]='('.implode(' OR ',$or).')';
        if ($range && $kind === 'memory') { $where[]='date BETWEEN ? AND ?'; array_push($params,...$range); }
        // With no keywords only dated memories or important dates are relevant.
        if (!$tableTerms && !($range && $kind==='memory') && $kind!=='date') continue;
        $rows = query("SELECT * FROM $table".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY $dateColumn DESC,id DESC LIMIT 8",$params)->fetchAll();
        foreach ($rows as $row) {
            $text = $row[$content];
            if ($kind==='memory') $text="Lokasi: {$row['location']}. $text";
            if ($kind==='date') $text.='; berulang setiap tahun: '.($row['recurring']?'ya':'tidak');
            $sources[] = chat_source($kind,$row['id'],$row['title'],$text,substr($row[$dateColumn]??'',0,10));
        }
    }
    // Prefer matches in titles; the relationship profile is always first.
    $head = array_shift($sources);
    usort($sources,static function($a,$b)use($terms){
        $score=static fn($s)=>array_sum(array_map(static fn($t)=>str_contains(mb_strtolower($s['title']),$t)?5:(str_contains(mb_strtolower($s['text']),$t)?1:0),$terms));
        return $score($b)<=>$score($a) ?: strcmp($b['date'],$a['date']);
    });
    return array_slice(array_merge($head?[$head]:[],$sources),0,6);
}
function chat_public_source(array $s): array {
    $href = match($s['kind']) {
        'memory'=>'/kenangan/'.$s['id'], 'album'=>'/timeline?album='.$s['id'],
        'letter'=>'/surat?letter='.$s['id'], 'date'=>'/tanggal#date-'.$s['id'], default=>'/pengaturan'
    };
    return ['key'=>$s['key'],'title'=>$s['title'],'href'=>$href];
}
function chat_messages(string $thread): array {
    query("UPDATE chat_messages SET status='failed' WHERE thread_id=? AND status='pending' AND created_at<?",[$thread,date('Y-m-d H:i:s',time()-180)]);
    $rows=query('SELECT m.*,u.name AS author FROM chat_messages m LEFT JOIN users u ON u.id=m.user_id WHERE thread_id=? ORDER BY m.created_at,m.id',[$thread])->fetchAll();
    // Replies are ordered directly after the corresponding question, even in the same second.
    $answers=[]; foreach($rows as $row) if($row['reply_to']) $answers[$row['reply_to']]=$row;
    $ordered=[]; foreach($rows as $row) if($row['role']==='user') { $ordered[]=$row; if(isset($answers[$row['id']]))$ordered[]=$answers[$row['id']]; }
    return array_map(static function($r){$r['sources']=json_decode($r['sources'],true)??[];unset($r['reply_to']);return $r;},$ordered);
}
function chat_replay(string $requestId,string $thread,array $user,string $text): ?array {
    $row=query('SELECT * FROM chat_messages WHERE request_id=?',[$requestId])->fetch();
    if (!$row) return null;
    if ($row['thread_id']!==$thread || $row['user_id']!==$user['id'] || $row['content']!==$text) throw new ChatError('ID permintaan sudah dipakai untuk pesan lain.',409);
    if ($row['status']==='pending' && strtotime($row['created_at']) < time()-180) { query("UPDATE chat_messages SET status='failed' WHERE id=? AND status='pending'",[$row['id']]); }
    return ['messages'=>chat_messages($thread),'status'=>chat_status()];
}
function chat_reserve(string $thread,array $user,string $text,string $requestId): array {
    $pdo=db();
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; $insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
    // Seed outside the transaction: INSERT IGNORE can take shared locks which
    // deadlock when concurrent workers then try upgrading to an exclusive lock.
    query("$insert INTO chat_usage(bucket_key,used,leased_until) VALUES ('lock',0,0)");
    $pdo->beginTransaction();
    try {
        // This single row serializes reservations across both accounts and all PHP workers.
        if(!$mysql)query('UPDATE chat_usage SET used=used WHERE bucket_key=?',['lock']);
        $lock=query('SELECT leased_until FROM chat_usage WHERE bucket_key=?'.($mysql?' FOR UPDATE':''),['lock'])->fetchColumn();
        if ($replay=chat_replay($requestId,$thread,$user,$text)) { $pdo->commit();return ['replay'=>$replay]; }
        if ((int)$lock>time()) throw new ChatError('Ada pesan yang sedang dijawab. Tunggu sebentar.',409);
        if (!query('SELECT id FROM chat_threads WHERE id=?'.($mysql?' FOR UPDATE':''),[$thread])->fetch()) throw new ChatError('Percakapan tidak ditemukan.',404);
        foreach (['day:'.date('Y-m-d')=>20,'month:'.date('Y-m')=>600] as $key=>$limit) {
            query("$insert INTO chat_usage(bucket_key,used,leased_until) VALUES (?,0,0)",[$key]);
            if (query('UPDATE chat_usage SET used=used+1 WHERE bucket_key=? AND used<?',[$key,$limit])->rowCount()!==1) throw new ChatError('Kuota chat bersama sudah habis. Coba lagi setelah kuota diperbarui.',429);
        }
        query('UPDATE chat_usage SET leased_until=? WHERE bucket_key=?',[time()+150,'lock']);
        $id=uid();query("INSERT INTO chat_messages(id,thread_id,user_id,role,content,request_id,status,sources,created_at) VALUES (?,?,?,'user',?,?,'pending','[]',?)",[$id,$thread,$user['id'],$text,$requestId,date('Y-m-d H:i:s')]);
        query('UPDATE chat_threads SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$thread]);
        $pdo->commit();return ['message_id'=>$id];
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}
function chat_invoke(array $payload): array {
    $config=chat_config(); $url=$config['service_url'];
    $credentials=new Google\Auth\Credentials\ServiceAccountCredentials(null,$config['credentials_file'],null,$url);
    $http=new GuzzleHttp\Client(['timeout'=>10,'connect_timeout'=>5]);
    $token=$credentials->fetchAuthToken(Google\Auth\HttpHandler\HttpHandlerFactory::build($http));
    if(empty($token['id_token']))throw new ChatError('Koneksi chat belum tersedia.',503);
    $curl=curl_init($url.'/chat');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token['id_token']],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    $response=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if($response===false||$status!==200)throw new ChatError('Chat belum berhasil menjawab. Pesan tersimpan; kuota tetap dihitung.',503);
    $result=json_decode($response,true,32,JSON_THROW_ON_ERROR);
    if(!is_string($result['answer']??null)||mb_strlen($result['answer'])>5000||!is_array($result['source_keys']??null))throw new ChatError('Jawaban chat tidak valid.',503);
    return $result;
}
function chat_send(string $thread,array $user,array $data): array {
    if(($data['shared_acknowledged']??false)!==true)throw new ChatError('Konfirmasi bahwa percakapan dapat dibaca pasangan.');
    $text=checked_text($data['content']??null,'Pesan',2000);
    $request=$data['request_id']??'';
    if(!is_string($request)||!preg_match('/^[a-f0-9]{32}$/D',$request))throw new ChatError('ID permintaan tidak valid.');
    if($replay=chat_replay($request,$thread,$user,$text))return $replay;
    if(!chat_status()['enabled'])throw new ChatError('Chat belum diaktifkan setelah verifikasi GCP.',503);
    $history=chat_messages($thread);$previous='';foreach($history as $m)if($m['role']==='user')$previous=$m['content'];
    $sources=chat_retrieve($text,$previous);
    $reservation=chat_reserve($thread,$user,$text,$request);
    if(isset($reservation['replay']))return $reservation['replay'];
    $id=$reservation['message_id'];
    // Do not hold the Google login session lock during a slow inference.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    @set_time_limit(110);
    try {
        $messages=array_map(static fn($m)=>['role'=>$m['role'],'content'=>mb_substr($m['content'],0,800)],array_slice(array_values(array_filter($history,static fn($m)=>$m['status']==='complete')),-6));
        $result=chat_invoke(['question'=>$text,'history'=>$messages,'sources'=>$sources,'today'=>date('Y-m-d')]);
        $selected=array_values(array_filter($sources,static fn($s)=>in_array($s['key'],$result['source_keys'],true)));
        $pdo=db();$pdo->beginTransaction();
        query('UPDATE chat_threads SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$thread]);
        query("UPDATE chat_messages SET status='complete' WHERE id=?",[$id]);
        // Deleting a thread while it has pending inference is prohibited by the route below.
        query("INSERT INTO chat_messages(id,thread_id,role,content,reply_to,status,sources,created_at) VALUES (?,?,'assistant',?,?,'complete',?,?)",[uid(),$thread,mb_substr($result['answer'],0,5000),$id,json_encode(array_map('chat_public_source',$selected),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),date('Y-m-d H:i:s')]);
        query('UPDATE chat_threads SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$thread]);$pdo->commit();
    } catch(Throwable $e) {
        if(db()->inTransaction())db()->rollBack();
        query("UPDATE chat_messages SET status='failed' WHERE id=?",[$id]);
        throw new ChatError('Chat belum berhasil menjawab. Pesan tersimpan; kuota tetap dihitung.',503);
    } finally { query('UPDATE chat_usage SET leased_until=0 WHERE bucket_key=?',['lock']); }
    return ['messages'=>chat_messages($thread),'status'=>chat_status()];
}
function chat_routes(string $path,string $method,array $user): void {
    if(!str_starts_with($path,'/chat'))return;
    try {
        if($path==='/chat/status'&&$method==='GET')respond(chat_status());
        if(!chat_installed())throw new ChatError('Tabel chat belum disiapkan. Kenangan tetap tersedia.',503);
        if($path==='/chat/threads'&&$method==='GET')respond(query('SELECT t.*,u.name AS author FROM chat_threads t JOIN users u ON u.id=t.user_id ORDER BY t.updated_at DESC,t.id DESC LIMIT 100')->fetchAll());
        if($path==='/chat/threads'&&$method==='POST') {
            $data=body();$id=uid();$title=checked_text($data['title']??'Cerita baru','Judul',120);
            query('INSERT INTO chat_threads(id,user_id,title) VALUES (?,?,?)',[$id,$user['id'],$title]);respond(['id'=>$id],201);
        }
        if(preg_match('#^/chat/threads/([a-f0-9]{32})(/messages)?$#D',$path,$m)) {
            $thread=query('SELECT * FROM chat_threads WHERE id=?',[$m[1]])->fetch();
            if(!$thread)throw new ChatError('Percakapan tidak ditemukan.',404);
            if(!isset($m[2])&&$method==='GET')respond(['thread'=>$thread,'messages'=>chat_messages($thread['id'])]);
            if(!isset($m[2])&&$method==='DELETE') {
                if($thread['user_id']!==$user['id'])throw new ChatError('Hanya pembuat yang dapat menghapus percakapan.',403);
                $pdo=db();$pdo->beginTransaction();
                try {
                    query('UPDATE chat_threads SET title=title WHERE id=?',[$thread['id']]);
                    if(query("SELECT id FROM chat_messages WHERE thread_id=? AND status='pending' AND created_at>?",[$thread['id'],date('Y-m-d H:i:s',time()-180)])->fetch())throw new ChatError('Tunggu sampai jawaban selesai sebelum menghapus.',409);
                    query('DELETE FROM chat_threads WHERE id=?',[$thread['id']]);$pdo->commit();
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                respond(['ok'=>true]);
            }
            if(isset($m[2])&&$method==='POST')respond(chat_send($thread['id'],$user,body()));
        }
        throw new ChatError('Halaman chat tidak ditemukan.',404);
    } catch(ChatError $e) { fail($e->getMessage(),$e->status); }
}
