<?php
require dirname(__DIR__).'/app/common/service/virtualpay/VirtualPayVault.php';
use app\common\service\virtualpay\VirtualPayVault as Vault;
$key='';function env($name,$default=null){global $key;return $name==='virtualpay.credential_key'?$key:$default;}
$external=getenv('PHP_VIRTUALPAY_CREDENTIAL_KEY');$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try{
    putenv('PHP_VIRTUALPAY_CREDENTIAL_KEY');
    try{Vault::requireKey();throw new LogicException('missing key accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'加密密钥'),'missing key rejected');}
    $key=str_repeat('f',64);$cipher=Vault::encrypt('fixture-payment-secret');check($cipher!=='fixture-payment-secret'&&Vault::decrypt($cipher)==='fixture-payment-secret','original encryption format roundtrips');
    putenv('PHP_VIRTUALPAY_CREDENTIAL_KEY='.str_repeat('e',64));check(Vault::decrypt($cipher)==='fixture-payment-secret','explicit config retains precedence over external key');
    $key='';check(Vault::configuredKey()===str_repeat('e',64),'blank config falls back to independent external key');
    $other=Vault::encrypt('external fixture');check(Vault::decrypt($other)==='external fixture','external key encrypt/decrypt agrees');
    check(Vault::decrypt($cipher)==='','different keys cannot read historical ciphertext');
    $key='short';try{Vault::requireKey();throw new LogicException('short key accepted');}catch(RuntimeException $e){check(true,'short explicit key never silently falls back');}
    echo "PASS virtual payment vault key precedence/crypto: $checks checks\n";
}finally{putenv($external===false?'PHP_VIRTUALPAY_CREDENTIAL_KEY':'PHP_VIRTUALPAY_CREDENTIAL_KEY='.$external);}
