<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!getenv('JV_CHAT_TEST_PORT'))exit(1);
require dirname(__DIR__).'/src/policy.php';require dirname(__DIR__).'/src/chat.php';date_default_timezone_set('Asia/Jakarta');
$port=(int)getenv('JV_CHAT_TEST_PORT');
$pdo=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE jv_chat_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE jv_chat_fixture');
$pdo->exec(file_get_contents(dirname(__DIR__).'/schema.sql'));
$migration=file_get_contents(dirname(__DIR__).'/migrations/001-chat.sql');$pdo->exec($migration);$pdo->exec($migration);
function db():PDO{global $pdo;return $pdo;}
function query(string $sql,array $params=[]):PDOStatement{$s=db()->prepare($sql);$s->execute($params);return $s;}
function uid():string{return bin2hex(random_bytes(16));}
function server_root():string{return sys_get_temp_dir().'/no-chat-config';}
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);}
$one=['id'=>str_repeat('a',32)];$two=['id'=>str_repeat('b',32)];$thread=str_repeat('c',32);
foreach([$one,$two] as $i=>$user)query('INSERT INTO users(id,google_sub,email,name) VALUES (?,?,?,?)',[$user['id'],'fixture-'.$i,$i.'@gmail.com','User '.$i]);
query('INSERT INTO chat_threads(id,user_id,title) VALUES (?,?,?)',[$thread,$one['id'],'Concurrent fixture']);
$runtime=dirname(__DIR__,2).'/.test-data/chat-race-'.uid();mkdir($runtime,0700,true);
$worker=<<<'PHP'
<?php
require $argv[1].'/src/policy.php';require $argv[1].'/src/chat.php';date_default_timezone_set('Asia/Jakarta');
function db():PDO{static $p;if(!$p)$p=new PDO('mysql:host=127.0.0.1;port='.getenv('JV_CHAT_TEST_PORT').';dbname=jv_chat_fixture;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);return $p;}
function query(string $s,array $a=[]):PDOStatement{$q=db()->prepare($s);$q->execute($a);return $q;}
function uid():string{return bin2hex(random_bytes(16));}
function server_root():string{return sys_get_temp_dir().'/no-chat-config';}
while(!is_file($argv[2]))usleep(1000);
try{$r=chat_reserve(str_repeat('c',32),['id'=>$argv[3]],'Concurrent message',$argv[4]);echo isset($r['replay'])?'replay':'reserved';}
catch(ChatError $e){echo $e->status;}
PHP;
file_put_contents($runtime.'/worker.php',$worker);
function race(array $users,array $requests):array {
    global $runtime;
    $gate=$runtime.'/gate-'.uid();$processes=[];
    foreach($users as $i=>$user){$out=$runtime.'/out-'.uid();$p=proc_open([PHP_BINARY,$runtime.'/worker.php',dirname(__DIR__),$gate,$user,$requests[$i]],[0=>['pipe','r'],1=>['file',$out,'w'],2=>['file',$out.'.err','w']],$pipes);$processes[]=[$p,$out];}
    file_put_contents($gate,'go');$answers=[];
    foreach($processes as [$p,$out]){check(proc_close($p)===0,'race worker completed');$answers[]=file_get_contents($out);}
    return $answers;
}
$requests=[uid(),uid()];$answers=race([$one['id'],$two['id']],$requests);sort($answers);
check($answers===['409','reserved'],'only one concurrent request is reserved');
check((int)query('SELECT used FROM chat_usage WHERE bucket_key=?',['day:'.date('Y-m-d')])->fetchColumn()===1,'race consumes exactly one daily request');
query("UPDATE chat_usage SET leased_until=0 WHERE bucket_key='lock'");
query('DELETE FROM chat_messages');
$id=uid();$answers=race([$one['id'],$one['id']],[$id,$id]);sort($answers);
check($answers===['replay','reserved'],'duplicate simultaneous submissions share reservation');
check((int)query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===1,'one user message for duplicate request');
check(chat_messages($thread)[0]['status']==='pending','timestamp uses consistent application timezone');
query("UPDATE chat_usage SET leased_until=0 WHERE bucket_key='lock'");query('UPDATE chat_usage SET used=20 WHERE bucket_key=?',['day:'.date('Y-m-d')]);
try{chat_reserve($thread,$one,'Too many',uid());throw new RuntimeException('quota failed');}catch(ChatError $e){check($e->status===429,'real SQL rejects exhausted quota');}
check((int)query('SELECT used FROM chat_usage WHERE bucket_key=?',['month:'.date('Y-m')])->fetchColumn()===2,'failed reservation rolls back counters');
query('DELETE FROM chat_threads WHERE id=?',[$thread]);
check((int)query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===0,'real cascade deletes messages');
echo "MariaDB checks passed: idempotent migration, foreign keys, concurrent quota reservations, simultaneous duplicate, timestamps and rollback.\n";
