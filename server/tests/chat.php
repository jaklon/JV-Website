<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
date_default_timezone_set('Asia/Jakarta');
require dirname(__DIR__).'/src/policy.php';
require dirname(__DIR__).'/src/chat.php';
$root=sys_get_temp_dir().'/jv-chat-test-'.bin2hex(random_bytes(8));mkdir($root,0700);
$pdo=new PDO('sqlite:'.$root.'/test.sqlite');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys=ON;
CREATE TABLE users(id TEXT PRIMARY KEY,name TEXT);
CREATE TABLE relationship_settings(id INTEGER,names TEXT,since TEXT,tagline TEXT);
CREATE TABLE memories(id TEXT,title TEXT,story TEXT,location TEXT,date TEXT);
CREATE TABLE albums(id TEXT,title TEXT,description TEXT,created_at TEXT);
CREATE TABLE letters(id TEXT,user_id TEXT,title TEXT,body TEXT,status TEXT,sent_at TEXT);
CREATE TABLE important_dates(id TEXT,title TEXT,date TEXT,recurring INTEGER);
CREATE TABLE chat_threads(id TEXT PRIMARY KEY,user_id TEXT,title TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE chat_messages(id TEXT PRIMARY KEY,thread_id TEXT REFERENCES chat_threads(id) ON DELETE CASCADE,user_id TEXT,role TEXT,content TEXT,request_id TEXT UNIQUE,reply_to TEXT UNIQUE,status TEXT,sources TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE chat_usage(bucket_key TEXT PRIMARY KEY,used INTEGER DEFAULT 0,leased_until INTEGER DEFAULT 0);");
function server_root():string{global $root;return $root;}
function db():PDO{global $pdo;return $pdo;}
function query(string $sql,array $params=[]):PDOStatement{$s=db()->prepare($sql);$s->execute($params);return $s;}
function uid():string{return bin2hex(random_bytes(16));}
$checks=0;
function verify(bool $yes,string $label):void{global $checks;if(!$yes)throw new RuntimeException('FAIL: '.$label);$checks++;}
function rejects(callable $action,int $code,string $label):void{try{$action();}catch(ChatError $e){verify($e->status===$code,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
query('INSERT INTO relationship_settings VALUES (1,?,?,?)',['Jascon & Vania','2024-02-14','Tempat kecil untuk cerita besar kita.']);
$one=['id'=>str_repeat('a',32)];$two=['id'=>str_repeat('b',32)];$thread=str_repeat('c',32);
foreach([$one,$two] as $i=>$u)query('INSERT INTO users VALUES (?,?)',[$u['id'],$i?'Vania':'Jascon']);
query('INSERT INTO chat_threads(id,user_id,title) VALUES (?,?,?)',[$thread,$one['id'],'Test']);
query('INSERT INTO memories VALUES (?,?,?,?,?)',[str_repeat('d',32),'Liburan Jogja','Melihat matahari terbenam dan makan gudeg.','Yogyakarta','2026-09-14']);
query('INSERT INTO memories VALUES (?,?,?,?,?)',[str_repeat('e',32),'Hari di pantai','Bermain pasir.','Manado','2026-10-03']);
query('INSERT INTO albums VALUES (?,?,?,?)',[str_repeat('f',32),'Liburan pertama','Perjalanan pertama ke Jogja.','2026-09-01']);
query('INSERT INTO letters VALUES (?,?,?,?,?,?)',[str_repeat('1',32),$one['id'],'Untuk Vania','Terima kasih sudah menemani perjalanan ini.','sent','2026-09-15']);
query('INSERT INTO letters VALUES (?,?,?,?,?,?)',[str_repeat('2',32),$one['id'],'Jogja rahasia','DRAFT_SECRET_DO_NOT_EXPORT','draft',null]);
query('INSERT INTO letters VALUES (?,?,?,?,?,?)',[str_repeat('3',32),$two['id'],'Jogja draft pasangan','PARTNER_DRAFT_SECRET','draft',null]);
query('INSERT INTO important_dates VALUES (?,?,?,?)',[str_repeat('4',32),'Ulang tahun Vania','2000-12-10',1]);
verify(!chat_status()['enabled'],'disabled until config/readiness');
$readyConfig=['credentials_file'=>$root.'/test-invoker.json','readiness_file'=>$root.'/test-readiness.json'];
file_put_contents($readyConfig['credentials_file'],'{}');
$readyReport=['model'=>'qwen3-0.6b-q4_k_m','quality_pass'=>true,'cost_pass'=>true,'runtime_pass'=>true,'iam_pass'=>true];
foreach(['https://private.run.app','https://jv-story-chat-365373732228.us-central1.run.app','https://jv-story-chat-example-uc.a.run.app'] as $serviceUrl){
    $readyConfig['service_url']=$serviceUrl;$readyReport['service_url']=$serviceUrl;
    file_put_contents($readyConfig['readiness_file'],json_encode($readyReport));
    verify(chat_ready($readyConfig),'official Cloud Run hostname accepted: '.$serviceUrl);
}
foreach(['http://private.run.app','https://private.run.app.evil.example','https://evil.example/run.app','https://private.run.app/path','https://private.run.app:443','https://user@private.run.app'] as $serviceUrl){
    $readyConfig['service_url']=$serviceUrl;$readyReport['service_url']=$serviceUrl;
    file_put_contents($readyConfig['readiness_file'],json_encode($readyReport));
    verify(!chat_ready($readyConfig),'unsafe or noncanonical endpoint rejected');
}
$readyConfig['service_url']='https://private.run.app';$readyReport['service_url']='https://other.run.app';
file_put_contents($readyConfig['readiness_file'],json_encode($readyReport));verify(!chat_ready($readyConfig),'readiness bound to exact endpoint');
$readyReport['service_url']=$readyConfig['service_url'];$readyReport['iam_pass']=false;
file_put_contents($readyConfig['readiness_file'],json_encode($readyReport));verify(!chat_ready($readyConfig),'failed IAM verification still blocks activation');
$retrieved=chat_retrieve('Apa cerita Jogja?');$export=json_encode($retrieved);
verify(str_contains($export,'gudeg'),'memory retrieved');
verify(!str_contains($export,'DRAFT_SECRET')&&!str_contains($export,'PARTNER_DRAFT'),'neither author draft exported');
$range=chat_retrieve('Kenangan kita bulan lalu apa?');
verify(count(array_filter($range,static fn($s)=>$s['kind']==='memory'&&$s['date']>date('Y-m-t',strtotime('first day of last month'))))===0,'month filter excludes newer memories');
verify(chat_date_range('tanggal 2025-02-29')===['2025-01-01','2025-12-31'],'invalid exact date is not treated as valid');
verify(chat_public_source($retrieved[0])['href']==='/pengaturan','profile source link');
verify(count(chat_retrieve('Bagaimana dengan itu?','cerita Jogja'))>1,'follow-up uses previous question');
verify(chat_date_range('September 2026')===['2026-09-01','2026-09-30'],'named month is narrower than the whole year');
verify(chat_date_range('Februari 2024')===['2024-02-01','2024-02-29'],'named leap-year month');
verify(str_contains(json_encode(chat_retrieve('Kenangan apa pada bulan September 2026?')),'gudeg'),'named month finds stories without month words');
verify(!str_contains(json_encode(chat_retrieve('Kenangan apa pada bulan September 2026?')),'Bermain pasir'),'named month excludes newer stories');
verify(in_array('kamera',chat_terms('kameranya'),true),'possessive suffix normalized');
verify(str_contains(json_encode(chat_retrieve('Apa warna kameranya?','Liburan Jogja')),'gudeg'),'follow-up with nouns inherits previous topic');
rejects(fn()=>chat_send($thread,$one,['content'=>'hello','request_id'=>uid()]),422,'shared acknowledgment mandatory');
$request=uid();$result=chat_reserve($thread,$one,'Hai',$request);
verify(isset($result['message_id']),'reservation');
verify(chat_status()['daily_remaining']===19&&chat_status()['monthly_remaining']===599,'one shared quota reserved');
$replay=chat_reserve($thread,$one,'Hai',$request);
verify(isset($replay['replay'])&&chat_status()['daily_remaining']===19,'idempotency does not consume quota twice');
rejects(fn()=>chat_reserve($thread,$two,'Hai',uid()),409,'partner cannot infer while worker leased');
rejects(fn()=>chat_replay($request,$thread,$two,'Hai'),409,'request owner binding');
rejects(fn()=>chat_replay($request,$thread,$one,'Different'),409,'request content binding');
query("UPDATE chat_messages SET status='complete' WHERE id=?",[$result['message_id']]);
query("INSERT INTO chat_messages(id,thread_id,role,content,reply_to,status,sources) VALUES (?,?,'assistant',?,?,'complete','[]')",[uid(),$thread,'Halo',$result['message_id']]);
$messages=chat_messages($thread);verify($messages[0]['author']==='Jascon'&&$messages[1]['role']==='assistant','author shown and reply ordered after question');
query("UPDATE chat_usage SET leased_until=0 WHERE bucket_key='lock'");
$partner=chat_reserve($thread,$two,'Hai juga',uid());verify(chat_status()['daily_remaining']===18,'same quota applies to partner');
query("UPDATE chat_messages SET status='failed' WHERE id=?",[$partner['message_id']]);query("UPDATE chat_usage SET leased_until=0 WHERE bucket_key='lock'");
verify(chat_status()['daily_remaining']===18,'failed invocation remains charged to quota');
query('UPDATE chat_usage SET used=20 WHERE bucket_key=?',['day:'.date('Y-m-d')]);
rejects(fn()=>chat_reserve($thread,$one,'Over limit',uid()),429,'daily limit enforced');
verify(chat_status()['monthly_remaining']===598,'daily rejection rolls back monthly increment');
query('UPDATE chat_usage SET used=0 WHERE bucket_key=?',['day:'.date('Y-m-d')]);query('UPDATE chat_usage SET used=600 WHERE bucket_key=?',['month:'.date('Y-m')]);
rejects(fn()=>chat_reserve($thread,$one,'Over monthly',uid()),429,'monthly limit enforced');
verify(chat_status()['daily_remaining']===20,'monthly rejection rolls back daily increment');
query('DELETE FROM chat_threads WHERE id=?',[$thread]);verify(query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()==0,'thread deletion cascades messages');
verify(chat_status()['monthly_remaining']===0,'deletion cannot restore quota');
echo "$checks chat checks passed (isolated SQLite; no Google/model calls).\n";
