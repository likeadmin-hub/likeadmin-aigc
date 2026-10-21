<?php
require dirname(__DIR__,2).'/app/common/service/redeem/RedeemCardPolicy.php';
require_once dirname(__DIR__,2).'/vendor/topthink/think-helper/src/helper/Str.php';
use app\common\service\redeem\RedeemCardPolicy as P;
$count=0;$check=function($ok,$msg)use(&$count){if(!$ok)throw new RuntimeException($msg);$count++;};
$codes=[];for($i=0;$i<100;$i++){$c=P::newCode();$check((bool)preg_match('/^[A-F0-9]{8}(?:-[A-F0-9]{8}){3}$/D',$c),'cryptographic code format');$codes[]=$c;}
$check(count(array_unique($codes))===100,'codes unique');$code=$codes[0];
$check(P::hash($code)===P::hash(strtolower(str_replace('-',' ',$code))),'canonical hash');
$check(!str_contains(P::mask($code),P::normalizeCode($code)),'masked list value');
$check(P::state(['status'=>'used'],['status'=>'disabled','expire_time'=>1],2)==='used','immutable used status');
$check(P::state(['status'=>'unused'],['status'=>'disabled','expire_time'=>0],2)==='disabled','batch disabled');
$check(P::state(['status'=>'unused'],['status'=>'enabled','expire_time'=>2],2)==='expired','expiry boundary');
$raw=['code'=>1,'data'=>['codes'=>[['code'=>$code,'code_mask'=>P::mask($code)]],'code_hash'=>P::hash($code)]];
$redacted=P::redact($raw);$check($redacted['code']===1&&!str_contains(json_encode($redacted),$code),'operation log keeps envelope status and removes secret');$check($raw['data']['codes'][0]['code']===$code,'redaction never modifies actual HTTP response');
foreach (['options','batches','lists','records','generate','disable'] as $action) {
    $check(P::allowsAdminAction(['root'=>1],$action,[]) === true,'existing super admin may perform known card action');
    $check(P::allowsAdminAction(['root'=>0],$action,[]) === false,'unregistered/empty permissions fail closed');
    $check(P::allowsAdminAction(['root'=>0],$action,['finance.redeem_card/'.$action]) === true,'specific financial role permission allowed');
}
$check(!P::allowsAdminAction(['root'=>0],'generate',null),'missing permissions denied');
$check(!P::allowsAdminAction(['root'=>0],'generate','finance.redeem_card/generate'),'invalid cache shape denied');
$check(!P::allowsAdminAction(['root'=>0],'generate',['finance.redeem_card/lists']),'read permission cannot generate');
$check(!P::allowsAdminAction(['root'=>0],'disable',['finance.redeem_card/generate']),'generate permission cannot disable');
$check(!P::allowsAdminAction(['root'=>0],'generate',['finance.recharge_package/add']),'other financial permission does not grant cards');
$check(!P::allowsAdminAction(['root'=>0],'generate',['finance.redeem_card/*']),'wildcard has no implicit access');
$check(!P::allowsAdminAction(['root'=>1],'unknown',[]),'unknown card action denied even for root');
$check(P::allowsAdminAction(['root'=>0],'generate',['FINANCE.REDEEM_CARD/GENERATE']),'middleware normalization retained');
$check(P::allowsAdminAction(['root'=>0],'generate',['finance.redeemCard/generate']),'camel URI normalization retained');
$check(!P::allowsAdminAction(['root'=>0],'generate',[null,[],false,12]),'malformed permission rows ignored');
echo "PASS redeem-card policy: $count checks\n";
