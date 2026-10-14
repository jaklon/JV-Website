<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__); $temp = dirname($root) . '/.test-data/' . bin2hex(random_bytes(6));
mkdir($temp,0700,true); mkdir($temp . '/sessions',0700);
$pdo = new PDO('sqlite:' . $temp . '/test.sqlite'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id TEXT PRIMARY KEY,google_sub TEXT,email TEXT,name TEXT,picture TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE relationship_settings (id INTEGER PRIMARY KEY,names TEXT,since TEXT,tagline TEXT,cover_id TEXT);
CREATE TABLE albums (id TEXT PRIMARY KEY,user_id TEXT,title TEXT,description TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE memories (id TEXT PRIMARY KEY,user_id TEXT,album_id TEXT,title TEXT,date TEXT,location TEXT,story TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE media (id TEXT PRIMARY KEY,memory_id TEXT,user_id TEXT,kind TEXT,name TEXT,filename TEXT,mime TEXT,size INTEGER,duration REAL,position INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE letters (id TEXT PRIMARY KEY,user_id TEXT,title TEXT,body TEXT,status TEXT,sent_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE important_dates (id TEXT PRIMARY KEY,user_id TEXT,title TEXT,date TEXT,recurring INTEGER);
CREATE TABLE movie_projects (id TEXT PRIMARY KEY,user_id TEXT,title TEXT,recipe TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO relationship_settings (id,names,tagline) VALUES (1,"Test couple","Test tagline");');
$pdo->exec("PRAGMA foreign_keys=ON;
CREATE TABLE chat_threads(id TEXT PRIMARY KEY,user_id TEXT,title TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE chat_messages(id TEXT PRIMARY KEY,thread_id TEXT REFERENCES chat_threads(id) ON DELETE CASCADE,user_id TEXT,role TEXT,content TEXT,request_id TEXT UNIQUE,reply_to TEXT UNIQUE,status TEXT,sources TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE chat_usage(bucket_key TEXT PRIMARY KEY,used INTEGER DEFAULT 0,leased_until INTEGER DEFAULT 0);");
$users = [str_repeat('a',32),str_repeat('b',32)]; $sessions=[]; $csrf=bin2hex(random_bytes(32));
foreach($users as $i=>$id){
    $statement=$pdo->prepare('INSERT INTO users (id,google_sub,email,name) VALUES (?,?,?,?)');$statement->execute([$id,"test-$i",($i?'two':'one').'@gmail.com',"Test $i"]);
    $sid=bin2hex(random_bytes(20));$sessions[]=$sid;
    file_put_contents($temp.'/sessions/sess_'.$sid,'user_id|s:32:"'.$id.'";csrf|s:64:"'.$csrf.'";last_seen|i:'.time().';');
    if ($i === 0) {
        // Isolated cached selection: no real Google request or production token.
        $connection=['user_id'=>$id,'expires'=>time()+3600,'token'=>'fixture-token'];
        $selection=[str_repeat('d',32)=>['google_id'=>'fixture-picker','expires'=>time()+3600,'items'=>[
            ['id'=>str_repeat('e',32),'name'=>'fixture.jpg','kind'=>'image','ready'=>true,'base_url'=>'https://lh3.googleusercontent.com/fixture']]]];
        file_put_contents($temp.'/sessions/sess_'.$sid,'photos_connection|'.serialize($connection).'photos_sessions|'.serialize($selection),FILE_APPEND);
    }
}
$config=['app_url'=>'http://127.0.0.1:5173','base_path'=>'','secure_cookies'=>false,'db_dsn'=>'sqlite:'.$temp.'/test.sqlite','db_user'=>'','db_password'=>'','google_client_id'=>'','google_client_secret'=>'','google_redirect_uri'=>'http://127.0.0.1:8095/api/auth/callback','allowed_emails'=>['one@gmail.com','two@gmail.com']];
file_put_contents($temp.'/config.php','<?php return '.var_export($config,true).';');
$environment=getenv();$environment['JV_STORY_CONFIG']=$temp.'/config.php';
$server=proc_open([PHP_BINARY,'-d','session.save_path='.$temp.'/sessions','-d','upload_max_filesize=52M','-d','post_max_size=55M','-S','127.0.0.1:8095','-t',$root.'/public',$root.'/router.php'],[0=>['pipe','r'],1=>['file',$temp.'/server.log','a'],2=>['file',$temp.'/server.log','a']],$pipes,dirname($root),$environment);
if(!is_resource($server))throw new RuntimeException('Cannot start test server');
$checks=0;
function http(string $path,string $method='GET',mixed $body=null,int $who=0,array $extra=[]):array {
    global $sessions,$csrf;
    $curl=curl_init('http://127.0.0.1:8095'.$path);
    $headers=$extra;
    if (!array_filter($extra,static fn($header)=>stripos($header,'X-CSRF-Token:')===0)) $headers[]='X-CSRF-Token: '.$csrf;
    if($body!==null&&!is_array($body))$headers[]='Content-Type: application/json';
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>5]);
    if($who>=0)curl_setopt($curl,CURLOPT_COOKIE,'jv_story='.$sessions[$who]);
    if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
    $response=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);curl_close($curl);
    return [$status,substr((string)$response,$size),substr((string)$response,0,$size)];
}
function verify(bool $condition,string $label):void {global $checks;if(!$condition)throw new RuntimeException('FAILED: '.$label);$checks++;}
try {
    for($attempt=0;$attempt<30;$attempt++){if(http('/api/session')[0]===200)break;usleep(100000);}
    verify(http('/api/session')[0]===200,'authenticated session');
    verify(http('/api/bootstrap','GET',null,-1)[0]===401,'anonymous API denied');
    verify(http('/media/'.str_repeat('c',32),'GET',null,-1)[0]===401,'anonymous media denied');
    verify(http('/api/auth/google','GET',null,-1)[0]===503,'unconfigured Google sign-in fails closed');
    verify(http('/api/photos/status','GET',null,-1)[0]===401,'anonymous Photos access denied');
    verify(http('/api/photos/sessions','POST','{}',-1)[0]===401,'anonymous Photos creation denied');
    verify(http('/api/photos/auth')[0]===503,'unconfigured Photos OAuth fails closed');
    verify(json_decode(http('/api/photos/status')[1],true)['connected']===true,'cached short-lived Photos connection');
    verify(json_decode(http('/api/photos/status','GET',null,1)[1],true)['connected']===false,'Photos connection isolated from partner');
    [$code,$picked]=http('/api/photos/sessions/'.str_repeat('d',32));
    verify($code===200&&json_decode($picked,true)['items'][0]['name']==='fixture.jpg','selected Photos metadata');
    verify(!str_contains($picked,'googleusercontent')&&!str_contains($picked,'fixture-token'),'Google URLs and tokens never exposed');
    verify(http('/api/photos/sessions/'.str_repeat('d',32),'GET',null,1)[0]===422,'partner cannot access selection');
    verify(http('/api/photos/sessions/'.str_repeat('d',32).'/files/'.str_repeat('f',32))[0]===404,'unselected Photos file denied');
    verify(http('/api/photos/sessions','POST','{}',0,['X-CSRF-Token: wrong'])[0]===403,'Photos creation CSRF enforced');
    verify(http('/api/photos/prepare','POST','{}',0,['X-CSRF-Token: wrong'])[0]===403,'Photos authorization preparation CSRF enforced');
    verify(http('/api/photos/prepare','POST','{}')[0]===200,'Photos authorization retry prepared');
    [$code,$payload]=http('/api/albums','POST',json_encode(['title'=>'Fixture album','description'=>'Only test data']));verify($code===201,'create album');$album=json_decode($payload,true)['id'];
    [$code,$payload]=http('/api/memories','POST',json_encode(['title'=>'Fixture memory','date'=>'2026-10-08','story'=>'Fixture story','location'=>'','album_id'=>$album]));verify($code===201,'create memory');$memory=json_decode($payload,true)['id'];
    verify(http('/api/memories/'.$memory,'PATCH',json_encode(['title'=>'No permission']),1)[0]===403,'partner cannot edit author content');
    verify(http('/api/albums/'.$album,'PATCH',json_encode(['title'=>'Shared album','description'=>'']),1)[0]===200,'albums editable together');
    [$code,$payload]=http('/api/letters','POST',json_encode(['title'=>'Private draft','body'=>'Not visible to partner','status'=>'draft']));verify($code===201,'save draft');$letter=json_decode($payload,true)['id'];
    $partner=json_decode(http('/api/bootstrap','GET',null,1)[1],true);verify(count($partner['letters'])===0,'draft hidden from partner');
    verify(http('/api/letters/'.$letter,'PATCH',json_encode(['title'=>'Sent letter','body'=>'Now visible','status'=>'sent']))[0]===200,'send letter');
    $partner=json_decode(http('/api/bootstrap','GET',null,1)[1],true);verify(count($partner['letters'])===1,'sent letter visible');
    $fixture=$temp.'/pixel.png';file_put_contents($fixture,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aJ1kAAAAASUVORK5CYII='));
    [$code,$payload]=http('/api/media','POST',['file'=>new CURLFile($fixture,'image/png','pixel.png'),'memory_id'=>$memory]);verify($code===201,'upload photo');$media=json_decode($payload,true)['id'];
    [$code,$bytes]=http('/media/'.$media);verify($code===200&&$bytes===file_get_contents($fixture),'private media streams correctly');
    [$code,$bytes,$headers]=http('/media/'.$media,'GET',null,0,['Range: bytes=0-9']);verify($code===206&&strlen($bytes)===10&&str_contains($headers,'Content-Range: bytes 0-9/'),'range streaming');
    verify(http('/media/'.$media,'GET',null,0,['Range: bytes=9999-'])[0]===416,'invalid ranges rejected');
    verify(http('/api/media/'.$media,'DELETE',null,1)[0]===403,'media owner enforced');
    $recipe=['version'=>1,'orientation'=>'landscape','transition'=>'cut','musicId'=>null,'items'=>[['mediaId'=>$media,'duration'=>2,'start'=>0,'end'=>0]]];
    verify(http('/api/projects','POST',json_encode(['title'=>'Fixture film','recipe'=>$recipe]))[0]===201,'save film recipe');
    verify(http('/api/dates','POST',json_encode(['title'=>'Leap anniversary','date'=>'2025-02-29','recurring'=>true]))[0]===422,'invalid dates rejected');
    [$code]=http('/api/settings','PATCH',json_encode(['names'=>'Updated couple','since'=>'2024-02-14','tagline'=>'Updated tagline','cover_id'=>$media]),1);verify($code===200,'shared settings');
    $bad=curl_init('http://127.0.0.1:8095/api/albums');curl_setopt_array($bad,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: wrong'],CURLOPT_COOKIE=>'jv_story='.$sessions[0],CURLOPT_RETURNTRANSFER=>true]);curl_exec($bad);verify(curl_getinfo($bad,CURLINFO_HTTP_CODE)===403,'CSRF enforced');curl_close($bad);
    verify(http('/api/media/'.$media,'DELETE')[0]===200,'delete disposable fixture upload');
    verify(http('/media/'.$media)[0]===404,'deleted media inaccessible');
    verify(http('/api/chat/status','GET',null,-1)[0]===401,'anonymous chat denied');
    $chatStatus=json_decode(http('/api/chat/status')[1],true);
    verify($chatStatus['installed']&&!$chatStatus['enabled'],'chat remains disabled without GCP readiness');
    verify(http('/api/chat/threads','POST',json_encode(['title'=>'Shared fixture']),0,['X-CSRF-Token: wrong'])[0]===403,'chat CSRF enforced');
    [$code,$payload]=http('/api/chat/threads','POST',json_encode(['title'=>'Shared fixture']));
    verify($code===201,'create shared conversation');$chatThread=json_decode($payload,true)['id'];
    $partnerThreads=json_decode(http('/api/chat/threads','GET',null,1)[1],true);
    verify(count($partnerThreads)===1&&$partnerThreads[0]['user_id']===$users[0],'partner reads same shared history');
    verify(http('/api/chat/threads/'.$chatThread,'GET',null,1)[0]===200,'partner reads conversation');
    verify(http('/api/chat/threads/'.$chatThread,'DELETE',null,1)[0]===403,'only creator can delete chat');
    verify(http('/api/chat/threads/'.$chatThread.'/messages','POST',json_encode(['content'=>'Hello','request_id'=>str_repeat('f',32)]))[0]===422,'chat shared acknowledgment enforced');
    verify(http('/api/chat/threads/'.$chatThread.'/messages','POST',json_encode(['content'=>'Hello','request_id'=>str_repeat('f',32),'shared_acknowledged'=>true]))[0]===503,'disabled chat cannot invoke model');
    verify(json_decode(http('/api/chat/status')[1],true)['daily_remaining']===20,'disabled request uses no quota');
    verify(http('/api/chat/threads/'.$chatThread,'DELETE')[0]===200,'creator deletes shared conversation');
    verify(http('/api/chat/threads/'.$chatThread,'GET',null,1)[0]===404,'deleted chat no longer visible');
    verify(http('/api/auth/logout','POST')[0]===200,'logout');
    verify(http('/api/bootstrap')[0]===401,'session revoked after logout');
    echo "$checks API integration checks passed (isolated SQLite fixture; production SQL validated separately in MariaDB).\n";
} finally {proc_terminate($server);proc_close($server);}
