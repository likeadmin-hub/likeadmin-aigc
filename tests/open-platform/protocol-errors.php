<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenGateway as G;
use app\common\service\open_platform\OpenException as E;
foreach(['/api/v1/tasks','/api/v1/tasks/native-id','/api/v1/apps/a/b','/api/v1/models','/api/v1/user/balance'] as $path){$r=G::error(new E('permission_denied','拒绝',403),$path,'req_fixture');$d=$r->getData();if($r->getCode()!==403||($d['code']??null)!==0||($d['msg']??null)!=='拒绝'||!array_key_exists('data',$d))throw new RuntimeException('Custom upstream protocol error changed: '.$path);}
$r=G::error(new E('invalid_api_key','拒绝',401),'/api/v1/chat/completions');if(!isset($r->getData()['error']['code']))throw new RuntimeException('OpenAI error structure changed');
$r=G::error(new E('invalid_api_key','拒绝',401),'/api/v1/messages');if(($r->getData()['type']??'')!=='error')throw new RuntimeException('Anthropic error structure changed');
$r=G::error(new E('invalid_api_key','拒绝',401),'/api/v1/tools','req_fixture');if(($r->getData()['request_id']??'')!=='req_fixture')throw new RuntimeException('Tool catalog request ID missing');
echo "PASS marketplace custom, OpenAI, Anthropic and tool error structures\n";
