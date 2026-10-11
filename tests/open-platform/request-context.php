<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\open_platform\OpenToolRequest;
$original=new \app\Request();$original->withGet(['id'=>'old','unrelated'=>'old'])->withPost(['id'=>'old']);$original->setMethod('POST');$original->param();$original->tenantId=21;$original->userId=34;$original->userInfo=['user_id'=>34,'tenant_id'=>21];
$fresh=OpenToolRequest::from($original,['id'=>55,'prompt'=>'new'],['app_code'=>'aigc_image','route'=>'POST tasks','controller'=>'Generate','action'=>'index']);
if((int)$fresh->param('id')!==55||$fresh->param('unrelated')!==null||$fresh->param('prompt')!=='new')throw new RuntimeException('Cached parameter contamination');
if($fresh->tenantId!==21||$fresh->userInfo['user_id']!==34)throw new RuntimeException('Trusted ownership lost');
if($original->param('id')!=='old')throw new RuntimeException('Original request mutated');
echo "PASS fresh tool parameters preserve trusted ownership without stale query cache\n";
