<?php
declare(strict_types=1);
// CLI-only, in-memory synthetic fixture. Never loads production configuration.
if(PHP_SAPI!=='cli')exit(1);
date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__).'/src/policy.php';require dirname(__DIR__).'/src/chat.php';
$input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE relationship_settings(id INTEGER,names TEXT,since TEXT,tagline TEXT);
CREATE TABLE memories(id TEXT,title TEXT,story TEXT,location TEXT,date TEXT);
CREATE TABLE albums(id TEXT,title TEXT,description TEXT,created_at TEXT);
CREATE TABLE letters(id TEXT,user_id TEXT,title TEXT,body TEXT,status TEXT,sent_at TEXT);
CREATE TABLE important_dates(id TEXT,title TEXT,date TEXT,recurring INTEGER);');
function query(string $sql,array $params=[]):PDOStatement{global $pdo;$s=$pdo->prepare($sql);$s->execute($params);return $s;}
function field(string $text,string $label):string{preg_match('/'.preg_quote($label,'/').':\s*([^\.]+)/u',$text,$m);return trim($m[1]??'');}
foreach($input['sources'] as $s) {
    switch($s['kind']) {
        case 'profile':query('INSERT INTO relationship_settings VALUES (1,?,?,?)',[field($s['text'],'Nama'),$s['date'],field($s['text'],'Tagline')]);break;
        case 'memory':$location=field($s['text'],'Lokasi');$story=preg_replace('/^Lokasi:\s*[^\.]+\.\s*/u','',$s['text']);query('INSERT INTO memories VALUES (?,?,?,?,?)',[$s['id'],$s['title'],$story,$location,$s['date']]);break;
        case 'album':query('INSERT INTO albums VALUES (?,?,?,?)',[$s['id'],$s['title'],$s['text'],$s['date']]);break;
        case 'letter':query('INSERT INTO letters VALUES (?,?,?,?,?,?)',[$s['id'],str_repeat('a',32),$s['title'],$s['text'],'sent',$s['date']]);break;
        case 'date':query('INSERT INTO important_dates VALUES (?,?,?,?)',[$s['id'],$s['title'],$s['date'],1]);break;
    }
}
foreach(['7','8'] as $owner)query('INSERT INTO letters VALUES (?,?,?,?,?,?)',[str_repeat($owner,32),str_repeat($owner,32),'Untuk Vania Bima Jogja Bandung','DRAFT_SECRET_DO_NOT_EXPORT_'.$owner,'draft',null]);
$contexts=[];
foreach($input['questions'] as $q) {
    $context=chat_retrieve($q['question'],$q['previous']??'');
    if(str_contains(json_encode($context),'DRAFT_SECRET'))throw new RuntimeException('Draft exported');
    $contexts[]=$context;
}
echo json_encode(['contexts'=>$contexts,'draft_excluded'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
