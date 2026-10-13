<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_music\{XhadminAigcMusicProvider,AigcMusicGenerateRequest};
class QueryFixture extends XhadminAigcMusicProvider
{
    public bool $unavailable=true;
    public array $response=['data'=>['status'=>'pending']];
    protected function resolveConfig(AigcMusicGenerateRequest $request): array {return ['query_url'=>'https://fixture.invalid/query','api_key'=>'fixture','timeout'=>30,'ssl_verify'=>true,'connect_timeout'=>10];}
    protected function request(string $method,string $url,string $apiKey,array $payload=[],int $timeout=30,bool $sslVerify=false,int $connectTimeout=30): array
    {
        if($method!=='GET')throw new LogicException('Query must never submit generation');
        if($this->unavailable)throw new RuntimeException('temporary query failure');
        return $this->response;
    }
}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$provider=new QueryFixture();$request=new AigcMusicGenerateRequest('test','test','','','','',5,1,[],[],[],[]);
$thrown=false;try{$provider->fetchResult('accepted-task',$request);}catch(RuntimeException $e){$thrown=$e->getMessage()==='temporary query failure';}
check($thrown,'Query transport failure returned a definitive generation failure');
$provider->unavailable=false;$pending=$provider->fetchResult('accepted-task',$request);
check($pending->success&&$pending->items===[]&&$pending->providerTaskId==='accepted-task','Accepted pending task lost its identity');
$provider->response=['data'=>['status'=>'failed','error'=>'供应商已明确终止任务']];
$failed=$provider->fetchResult('accepted-task',$request);
check(!$failed->success&&$failed->providerTaskId==='accepted-task','Confirmed generation failure was swallowed');
echo "Music query recovery passed: temporary transport failure is retryable, known task retained, explicit terminal failure preserved; no provider call\n";
