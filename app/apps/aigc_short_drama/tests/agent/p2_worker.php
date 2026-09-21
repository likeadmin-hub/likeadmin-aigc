<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;

final class IsolatedConversationProvider implements ConversationProviderInterface {
    public int $calls=0;
    public array $requests=[];
    public function __construct(private string $scenario) {}
    public function preflight(int $tenant,int $user,array $request): void {
        if ($this->scenario==='preflight') throw new RuntimeException('private policy detail');
        if ($tenant!==91001 || $user!==92001 || $request['app_code']!=='aigc_short_drama') throw new RuntimeException('Wrong provider scope');
    }
    public function generate(int $tenant,int $user,array $request): array {
        $this->calls++;$this->requests[]=$request;
        agentCheck(!Db::connect()->getPdo()->inTransaction(),'provider boundary has no open DB transaction');
        if ($this->scenario==='throw') throw new RuntimeException('private upstream detail');
        if ($this->scenario==='malformed') return ['content'=>'ignored','tool_calls'=>'not json'];
        if ($this->scenario==='tool') return ['content'=>'ignored','tool_calls'=>[['name'=>'delete_canvas','arguments'=>'{broken']]];
        if ($this->scenario==='late') Db::name(Store::PREFIX.'outbox')->where('run_id',$request['run_id'])->update(['lease_until'=>time()-1]);
        return ['content'=>'隔离模拟回复','tool_calls'=>[]];
    }
}
if (Db::name('aigc_short_drama_config')->where('tenant_id',91001)->count()) throw new RuntimeException('Existing fixture config');
$config=0;$canvas=0;
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=Canvas::create(91001,92001,['title'=>'P2 finite Worker fixture'])['id'];
    foreach (['success','preflight','throw','malformed','tool','late'] as $scenario) {
        $thread=Store::create(91001,92001,$canvas,$scenario)['id'];
        $request=['request_key'=>$scenario,'content'=>'仅讨论，不生成节点','base_revision'=>0];
        $snapshot=['settings'=>['reasoning_model'=>['id'=>'isolated-model']],'skill'=>[]];
        $ack=Store::enqueue(91001,92001,$canvas,$thread,$request,$snapshot);
        $provider=new IsolatedConversationProvider($scenario);
        $state=Worker::process(91001,92001,$ack['run_id'],$provider);
        agentCheck($state===($scenario==='success'?'success':($scenario==='preflight'?'failed':'needs_reconciliation')),$scenario.' has expected terminal/quarantine status');
        agentCheck(Worker::process(91001,92001,$ack['run_id'],$provider)==='not_claimed',$scenario.' duplicate Worker does not resubmit');
        agentCheck($provider->calls===($scenario==='preflight'?0:1),$scenario.' provider attempt count');
        $events=Store::events(91001,92001,$canvas,$thread);
        agentCheck(!str_contains(json_encode($events),'private'),$scenario.' errors do not expose private provider/policy details');
        $messages=Store::messages(91001,92001,$canvas,$thread);
        agentCheck(count($messages)===($scenario==='success'?2:1),$scenario.' only validated success publishes assistant message');
        if ($scenario==='success') {
            $dto=$provider->requests[0];
            agentCheck($dto['settings']['reasoning_model']['id']==='isolated-model' && $dto['tools']===[] && $dto['automatic_retry']===false,'Worker preserves chosen model and disables tools/retries');
            agentCheck($dto['business_table']===Store::PREFIX.'run' && $dto['business_id']===$ack['run_id'],'billing adapter receives correct app-owned business identity');
            $next=Store::enqueue(91001,92001,$canvas,$thread,array_replace($request,['request_key'=>'follow-up','content'=>'继续分析']),$snapshot);
            agentCheck(Worker::process(91001,92001,$next['run_id'],$provider)==='success','same conversation can continue after successful reply');
            agentCheck(array_column($provider->requests[1]['messages'],'role')===['user','assistant','user'],'follow-up receives frozen user/assistant conversation history');
            agentCheck($provider->requests[1]['messages'][1]['content']==='隔离模拟回复','history includes actual previous reply');
        }
        if ($scenario==='preflight') agentCheck((int)Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('active_run_id')===0,'no-cost preflight rejection releases conversation');
    }
    agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$canvas)->count()===0,'Worker never creates media generation tasks');
    agentCheck((int)Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('graph_revision')===0,'Worker does not mutate graph');
} finally {
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>91001])->delete();
}
echo "NOT_RUN real Provider adapter, billing ledger, moderation, production scheduler, process-kill and frontend\n";
