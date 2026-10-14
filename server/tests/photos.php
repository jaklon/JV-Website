<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/photos.php';
function uid(): string { return bin2hex(random_bytes(16)); }
$count = 0;
function check(bool $condition, string $label): void { global $count; if (!$condition) throw new RuntimeException($label); $count++; }
check(photos_google_url('https://lh3.googleusercontent.com/selected'), 'Google download host accepted');
foreach (['http://lh3.googleusercontent.com/file', 'https://googleusercontent.com.evil.test/x', 'https://evil.test/x', 'https://googleusercontent.com/x', 'https://user@lh3.googleusercontent.com/x', 'https://lh3.googleusercontent.com:8443/x', 'https://lh3.googleusercontent.com/x#fragment', 'https://127.0.0.1/x', 'https://photos.google.com/x'] as $url) {
    check(!photos_google_url($url), 'Reject non-download URL: ' . $url);
}
check(photos_google_url('https://photos.google.com/picker/session',true), 'Picker URI accepted');
check(!photos_google_url('https://photos.google.com.evil.test/x',true), 'Picker hostname lookalike rejected');
check(photos_polling(['pollingConfig'=>['pollInterval'=>'3.5s','timeoutIn'=>'0s']]) === ['pollMs'=>3500,'timeoutMs'=>0], 'Fractional duration and stop signal');
check(photos_polling([]) === ['pollMs'=>3000,'timeoutMs'=>600000], 'Safe polling defaults');
$oauth=['user_id'=>'u']; $claims=['sub'=>'google-u']; $user=['id'=>'u','google_sub'=>'google-u'];
check(photos_bound_identity($oauth,$claims,$user,'u'), 'Same signed-in Google account accepted');
check(!photos_bound_identity($oauth,['sub'=>'google-partner'],$user,'u'), 'Partner Google account cannot replace current connection');
check(!photos_bound_identity($oauth,$claims,$user,'partner'), 'Login changed during OAuth rejected');
check(!photos_bound_identity($oauth,$claims,null,'u'), 'Deleted user rejected');
$photo=photos_item(['type'=>'PHOTO','mediaFile'=>['baseUrl'=>'https://lh3.googleusercontent.com/file','filename'=>"photo\r\n.jpg"]]);
check($photo['ready'] && !str_contains($photo['name'],"\r"), 'Photo metadata normalized');
check(!isset(photos_public_item($photo)['base_url']), 'Private Google URL never returned to frontend');
$video=photos_item(['type'=>'VIDEO','mediaFile'=>['baseUrl'=>'https://lh3.googleusercontent.com/video','mediaFileMetadata'=>['videoMetadata'=>['processingStatus'=>'PROCESSING']]]]);
check(!$video['ready'], 'Processing video unavailable');
$failed=false;try { photos_download('fixture',$video); } catch (PhotosException) { $failed=true; }
check($failed, 'Processing video rejected without network call');
$_SESSION=['photos_connection'=>['user_id'=>'u','expires'=>time()+3600,'token'=>'fixture'],'photos_sessions'=>['fixture'=>[]]];
check(photos_connection(['id'=>'u']) !== null, 'Valid short-lived connection accepted');
check(photos_connection(['id'=>'partner']) === null && !isset($_SESSION['photos_sessions']), 'Connection and selections cleared for another user');
$_SESSION=['photos_connection'=>['user_id'=>'u','expires'=>time()+10,'token'=>'fixture']];
check(photos_connection(['id'=>'u']) === null, 'Token expiring soon cleared');
echo "$count Google Photos policy checks passed.\n";
