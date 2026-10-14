<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/policy.php';
$count = 0;
function check(bool $condition, string $label): void { global $count; if (!$condition) throw new RuntimeException($label); $count++; }
$allowed = ['one@gmail.com','two@gmail.com'];
$claims = ['sub'=>'google-1','email'=>'one@gmail.com','email_verified'=>true];
check(permitted_google_identity($claims,$allowed),'allowlisted verified identity');
check(!permitted_google_identity([...$claims,'email'=>'other@gmail.com'],$allowed),'reject other personal Gmail');
check(!permitted_google_identity([...$claims,'email_verified'=>false],$allowed),'reject unverified email');
check(!permitted_google_identity([...$claims,'email_verified'=>'true'],$allowed),'reject coerced verification');
check(!permitted_google_identity([...$claims,'hd'=>'workspace.test'],$allowed),'reject Workspace identity');
check(!permitted_google_identity([...$claims,'sub'=>''],$allowed),'require Google subject');
check(!permitted_google_identity([...$claims,'email'=>'one@gmail.com.attacker.test'],$allowed),'reject lookalike Gmail domains');
check(valid_date('2024-02-29'),'valid leap date'); check(!valid_date('2025-02-29'),'invalid leap date');
$media = [['id'=>'photo','kind'=>'image','size'=>100,'duration'=>null],['id'=>'video','kind'=>'video','size'=>100,'duration'=>10]];
$recipe = ['version'=>1,'orientation'=>'landscape','transition'=>'fade','musicId'=>null,'items'=>[['mediaId'=>'photo','duration'=>4,'start'=>0,'end'=>0],['mediaId'=>'video','duration'=>4,'start'=>1,'end'=>5]]];
check(count(checked_recipe($recipe,$media)['items'])===2,'valid recipe');
foreach ([['missing source',[...$recipe,'items'=>[['mediaId'=>'missing','duration'=>4,'start'=>0,'end'=>0]]]],['invalid trim',[...$recipe,'items'=>[['mediaId'=>'video','duration'=>4,'start'=>0,'end'=>11]]]],['invalid photo duration',[...$recipe,'items'=>[['mediaId'=>'photo','duration'=>16,'start'=>0,'end'=>0]]]]] as [$label,$invalid]) {
    $rejected=false; try { checked_recipe($invalid,$media); } catch (InvalidArgumentException) {$rejected=true;} check($rejected,$label);
}
echo "$count backend policy checks passed.\n";
