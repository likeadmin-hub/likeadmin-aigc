<?php
require dirname(__DIR__).'/app/common/service/virtualpay/VirtualPayKeySetup.php';
require dirname(__DIR__).'/public/install/YxEnv.php';
use app\common\service\virtualpay\VirtualPayKeySetup as Setup;
$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$dir=sys_get_temp_dir().'/virtualpay-key-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try{
    $file=$dir.'/.env';$original="APP_DEBUG = false\n[DATABASE]\nPASSWORD = \"keep-sensitive-value\"\n[WECHAT]\nCREDENTIAL_KEY = \"unchanged-wechat-key\"\n";
    file_put_contents($file,$original);
    $result=Setup::initialize($file,'',static fn()=>false);
    $ini=parse_ini_file($file,true,INI_SCANNER_RAW);$key=$ini['VIRTUALPAY']['CREDENTIAL_KEY']??'';
    check($result['created']===true&&strlen($key)===64&&ctype_xdigit($key),'fresh random key persisted');
    check(str_starts_with(file_get_contents($file),$original),'unrelated config and WeChat key unchanged');
    check(file_get_contents($result['backup'])===$original&&(fileperms($result['backup'])&0777)===0600,'private backup preserves original');
    check(!str_contains(json_encode($result),$key),'setup never returns key');
    $before=file_get_contents($file);$again=Setup::initialize($file,'',static fn()=>true);
    check(!$again['created']&&file_get_contents($file)===$before,'idempotent and existing key preserved even with history');
    file_put_contents($file,$original);$env=Setup::initialize($file,str_repeat('e',32),static fn()=>true);
    check(!$env['created']&&file_get_contents($file)===$original,'existing process environment never overwritten');
    try{Setup::initialize($file,'',static fn()=>true);throw new LogicException('history accepted');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'恢复原密钥')&&file_get_contents($file)===$original,'history stops key regeneration');}
    file_put_contents($file,$original."[VIRTUALPAY]\nCREDENTIAL_KEY = short\n");$before=file_get_contents($file);
    try{Setup::initialize($file,'',static fn()=>false);throw new LogicException('short key overwritten');}catch(RuntimeException $error){check(file_get_contents($file)===$before,'invalid configured key not overwritten');}
    file_put_contents($file,$original."[VIRTUALPAY]\nCREDENTIAL_KEY = \"\"\n");Setup::initialize($file,'',static fn()=>false);
    check(strlen(parse_ini_file($file,true,INI_SCANNER_RAW)['VIRTUALPAY']['CREDENTIAL_KEY'])===64,'blank section initialized without duplicate key');
    file_put_contents($file,"[VIRTUALPAY]");Setup::initialize($file,'',static fn()=>false);check(strlen(parse_ini_file($file,true,INI_SCANNER_RAW)['VIRTUALPAY']['CREDENTIAL_KEY'])===64,'section without trailing newline remains valid');
    $db=['host'=>'127.0.0.1','name'=>'test','user'=>'user','password'=>'fixture','port'=>'3306','prefix'=>'la_'];
    $example=$dir.'/example.env';file_put_contents($example,"[DATABASE]\nDATABASE = test\n[VIRTUALPAY]\nCREDENTIAL_KEY = \"\"\n");
    $installed=$dir.'/new.env';$yx=new YxEnv();$yx->load($example);check($yx->putEnv($installed,$db),'installer writes environment');
    $first=parse_ini_file($installed,true,INI_SCANNER_RAW)['VIRTUALPAY']['CREDENTIAL_KEY']??'';check(strlen($first)===64,'installer provisions unique key');
    $yx=new YxEnv();$yx->load($example);check($yx->putEnv($installed,$db),'installer repeat succeeds');
    check(parse_ini_file($installed,true,INI_SCANNER_RAW)['VIRTUALPAY']['CREDENTIAL_KEY']===$first,'installer repeat preserves original vault key');
    $other=$dir.'/other.env';check($yx->putEnv($other,$db),'second installation writes');check(parse_ini_file($other,true,INI_SCANNER_RAW)['VIRTUALPAY']['CREDENTIAL_KEY']!==$first,'installations do not share keys');
    echo "PASS virtual payment key setup: $checks checks\n";
}finally{foreach(glob($dir.'/*')?:[] as $path)unlink($path);foreach(glob($dir.'/.*')?:[] as $path)if(is_file($path))unlink($path);rmdir($dir);}
