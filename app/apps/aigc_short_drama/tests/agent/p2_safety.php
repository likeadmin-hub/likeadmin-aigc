<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationService as Service;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSafety;

final class SafetyProvider implements ConversationProviderInterface {
    public int $calls=0;
    public function preflight(int $tenant,int $user,array $request): void {}
    public function generate(int $tenant,int $user,array $request): array { $this->calls++; return ['content'=>'拒绝输出标记','tool_calls'=>[]]; }
}
if (Db::name('aigc_short_drama_config')->whereIn('tenant_id',[91031,91032])->count()) throw new RuntimeException('Existing safety fixture config');
$canvas=0;$config=[];$product=0;
try {
    $policy=['enabled'=>true,'execution_enabled'=>true,'safety'=>['blocked_terms'=>['拒绝输入标记','拒绝输出标记']]];
    foreach ([91031,91032] as $tenant) $config[]=['id'=>Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>$tenant,'config_json'=>json_encode(['canvas_agent'=>$policy],JSON_UNESCAPED_UNICODE),'status'=>1,'create_time'=>time(),'update_time'=>time()]),'tenant'=>$tenant];
    $product=Db::name('power_market_product')->insertGetId(['product_code'=>'isolated-safety-text','resource_type'=>'model','model_type'=>'text','name'=>'Isolated safety model','source_code'=>'isolated-agent-test','upstream_resource_key'=>'isolated-safety-text','upstream_model_code'=>'isolated-safety','upstream_channel_code'=>'isolated-channel','source_payload'=>'{}','status'=>1]);
    Db::name('power_market_sku')->insert(['product_id'=>$product,'sku_key'=>'input','title'=>'Isolated safety tokens','usage_unit'=>'token','sale_points'=>1,'status'=>1,'sale_status'=>1]);
    $canvas=Canvas::create(91031,92031,['title'=>'P2 safety fixture'])['id'];
    $thread=Store::create(91031,92031,$canvas,'safety-thread')['id'];
    try {
        Service::send(91031,92031,$canvas,$thread,['request_key'=>'blocked-attachment','content'=>'分析材料','base_revision'=>0,'attachments'=>[['type'=>'text','name'=>'input.md','content'=>'拒绝输入标记']]]);
        throw new RuntimeException('Expected blocked attachment');
    } catch (\RuntimeException $error) {agentCheck($error->getMessage()==='CONTENT_BLOCKED','attachment text participates in pre-submit moderation');}
    try {
        Service::send(91031,92031,$canvas,$thread,['request_key'=>'blocked-input','content'=>'请处理拒绝输入标记','base_revision'=>0,'preferences'=>['reasoning_model'=>(string)$product]]);
        throw new RuntimeException('Expected blocked input');
    } catch (\RuntimeException $error) { agentCheck($error->getMessage()==='CONTENT_BLOCKED','blocked input returns the generic public safety result'); }
    agentCheck(Db::name(Store::PREFIX.'run')->where(['tenant_id'=>91031,'canvas_id'=>$canvas])->count()===0 && Db::name(Store::PREFIX.'outbox')->where(['tenant_id'=>91031,'canvas_id'=>$canvas])->count()===0,'blocked input creates no run or provider dispatch');
    $inputAudit=Db::name(ConversationSafety::TABLE)->where(['tenant_id'=>91031,'canvas_id'=>$canvas,'direction'=>'input','request_key'=>'blocked-input'])->find();
    agentCheck((string)$inputAudit['decision']==='blocked' && (int)$inputAudit['provider_submitted']===0,'input audit records a pre-submit rejection');
    agentCheck(!str_contains(json_encode($inputAudit,JSON_UNESCAPED_UNICODE),'拒绝输入标记') && strlen((string)$inputAudit['content_sha256'])===64,'input audit contains no raw blocked text');
    $ack=Service::send(91031,92031,$canvas,$thread,['request_key'=>'safe-input','content'=>'请给出一个安全的短剧开场建议','base_revision'=>0,'preferences'=>['reasoning_model'=>(string)$product]]);
    $provider=new SafetyProvider();
    agentCheck(Worker::process(91031,92031,$ack['run_id'],$provider)==='failed' && $provider->calls===1,'blocked output is rejected after exactly one Provider response');
    $run=Store::run(91031,92031,$canvas,$thread,$ack['run_id']);
    agentCheck($run['status']==='failed' && $run['error_code']==='CONTENT_BLOCKED','output rejection is a safe terminal run state');
    agentCheck(count(Store::messages(91031,92031,$canvas,$thread))===1,'blocked output is never published as an assistant message');
    $outputAudit=Db::name(ConversationSafety::TABLE)->where(['tenant_id'=>91031,'canvas_id'=>$canvas,'run_id'=>$ack['run_id'],'direction'=>'output'])->find();
    agentCheck((string)$outputAudit['decision']==='blocked' && (int)$outputAudit['provider_submitted']===1 && !str_contains(json_encode($outputAudit,JSON_UNESCAPED_UNICODE),'拒绝输出标记'),'output audit is redacted and marks the submitted boundary');
    ConversationSafety::assertInput(91032,92032,7,8,'tenant-two','独立内容');
    agentCheck(Db::name(ConversationSafety::TABLE)->where(['tenant_id'=>91031,'canvas_id'=>$canvas])->count()===4 && Db::name(ConversationSafety::TABLE)->where(['tenant_id'=>91032,'request_key'=>'tenant-two'])->count()===1,'safety audit is isolated by tenant and canvas scope');
    Db::name(ConversationSafety::TABLE)->where(['tenant_id'=>91031,'canvas_id'=>$canvas,'request_key'=>'blocked-input'])->update(['expires_at'=>time()-1]);
    agentCheck(ConversationSafety::purgeExpired(91031)===1 && Db::name(ConversationSafety::TABLE)->where(['tenant_id'=>91031,'canvas_id'=>$canvas,'request_key'=>'blocked-input'])->count()===0,'expired minimal audit is purged without touching another tenant');
} finally {
    Db::name(ConversationSafety::TABLE)->whereIn('tenant_id',[91031,91032])->delete();
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['tenant_id'=>91031,'user_id'=>92031,'canvas_id'=>$canvas])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91031,'user_id'=>92031])->delete();
    }
    foreach ($config as $row) Db::name('aigc_short_drama_config')->where(['id'=>$row['id'],'tenant_id'=>$row['tenant']])->delete();
    if ($product) {
        Db::name('power_market_sku')->where('product_id',$product)->delete();
        Db::name('power_market_product')->where('id',$product)->delete();
    }
}
echo "NOT_RUN real semantic moderation provider; configured tenant rule behavior is covered in isolation\n";
