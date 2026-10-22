<?php
use app\common\service\storage\StorageOperationTimeout as Budget;
require_once dirname(__DIR__,2).'/app/common/service/storage/StorageOperationTimeout.php';
$checks=0;$check=function($ok,$msg)use(&$checks){if(!$ok)throw new RuntimeException($msg);++$checks;};
$check(Budget::seconds([])===null&&Budget::cos([])===[]&&Budget::qiniu([])===null,'normal SDK defaults preserved');
$check(Budget::seconds(['operation_timeout'=>0])===1&&Budget::seconds(['operation_timeout'=>500])===60,'budget bounded in seconds');
$oss=new OSS\OssClient('fixture-ak','fixture-sk','https://oss-cn-hangzhou.aliyuncs.com');Budget::oss($oss,['operation_timeout'=>7]);
foreach(['timeout'=>7,'connectTimeout'=>3,'maxRetries'=>0] as $property=>$expected){$p=new ReflectionProperty($oss,$property);$p->setAccessible(true);$check($p->getValue($oss)===$expected,'OSS consumes '.$property);}
$options=Budget::qiniu(['operation_timeout'=>8]);$curl=$options->getCurlOpt();$check($curl[CURLOPT_TIMEOUT]===4&&$curl[CURLOPT_CONNECTTIMEOUT]===3,'Qiniu actual RequestOptions curl seconds consumed');
$manager=new Qiniu\Storage\UploadManager(new Qiniu\Config(),$options);$p=new ReflectionProperty($manager,'reqOpt');$p->setAccessible(true);$check($p->getValue($manager)===$options,'Qiniu UploadManager receives options');
$curl=Budget::qiniu(['operation_timeout'=>2],true)->getCurlOpt();$check($curl[CURLOPT_TIMEOUT]===2,'Qiniu cleanup one-request budget');
$cos=new Qcloud\Cos\Client(Budget::cos(['operation_timeout'=>7])+['region'=>'ap-guangzhou','credentials'=>['secretId'=>'fixture-ak','secretKey'=>'fixture-sk']]);$p=new ReflectionProperty($cos,'cosConfig');$p->setAccessible(true);$cfg=$p->getValue($cos);$check($cfg['timeout']===7&&$cfg['connect_timeout']===3&&$cfg['retry']===0,'COS consumes operation/connect seconds and retry zero');
echo "PASS storage operation budget: $checks checks; no network\n";
