<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSettings as Settings;
use app\common\service\app\aigc_short_drama\canvas_agent\FeatureGate;
use app\common\service\app\aigc_short_drama\canvas_agent\MarketTextConversationProvider;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationPreferences;
use app\common\service\power\MarketTextModelRuntimeService;
function rejectsSettings(callable $action,string $code): void {
    try {$action();} catch (RuntimeException $error) {agentCheck($error->getMessage()===$code,$code);return;}
    throw new RuntimeException('Expected '.$code);
}
$groups=[];
foreach (['script_plan','image','video'] as $type) $groups[]=['key'=>$type,'default'=>$type.'-one','options'=>[
    ['id'=>$type.'-one','name'=>$type.' one','enabled'=>true,'api_key'=>'must-not-persist','price'=>-99],
    ['id'=>$type.'-two','name'=>$type.' two','enabled'=>1],
    ['id'=>$type.'-disabled','enabled'=>false],
]];
$defaults=Settings::fromCatalog($groups,[]);
agentCheck($defaults['reasoning_model']['id']==='script_plan-one' && $defaults['generation_mode']==='manual','catalog defaults remain separate from automatic execution');
agentCheck(!str_contains(json_encode($defaults),'must-not-persist') && !str_contains(json_encode($defaults),'price'),'model snapshots discard secrets and arbitrary price fields');
$selected=Settings::fromCatalog($groups,['reasoning_model'=>'script_plan-two','image_model'=>'image-two','video_model'=>'video-two','generation_mode'=>'auto']);
agentCheck($selected['reasoning_model']['id']==='script_plan-two' && $selected['image_model']['id']==='image-two' && $selected['video_model']['id']==='video-two','three explicit model choices preserved');
foreach (['reasoning_model'=>'script_plan','image_model'=>'image','video_model'=>'video'] as $field=>$key) {
    rejectsSettings(fn()=>Settings::fromCatalog($groups,[$field=>$key.'-disabled']),strtoupper($field).'_UNAVAILABLE');
    rejectsSettings(fn()=>Settings::fromCatalog($groups,[$field=>'unknown']),strtoupper($field).'_UNAVAILABLE');
    rejectsSettings(fn()=>Settings::fromCatalog($groups,[$field=>['id'=>$key.'-one']]),'INVALID_MODEL_SELECTION');
}
rejectsSettings(fn()=>Settings::fromCatalog($groups,['reasoning_model'=>'image-one']),'REASONING_MODEL_UNAVAILABLE');
rejectsSettings(fn()=>Settings::fromCatalog($groups,['reasoning_model'=>null]),'INVALID_MODEL_SELECTION');
rejectsSettings(fn()=>Settings::fromCatalog($groups,['system_prompt'=>'override']),'INVALID_AGENT_PREFERENCES');
rejectsSettings(fn()=>Settings::fromCatalog($groups,['generation_mode'=>'unrestricted']),'INVALID_GENERATION_MODE');
agentCheck(Settings::fromCatalog([$groups[0]],[])['image_model']===[],'unconfigured media model does not block pure conversation');
rejectsSettings(fn()=>Settings::fromCatalog([],[]),'REASONING_MODEL_UNAVAILABLE');

Db::startTrans();
try {
    rejectsSettings(fn()=>Settings::resolve(91001,[]),'CANVAS_AGENT_DISABLED');
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1,'create_time'=>time(),'update_time'=>time()]);
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>91002,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $product=Db::name('power_market_product')->insertGetId(['product_code'=>'isolated-agent-text','resource_type'=>'model','model_type'=>'text','name'=>'Isolated reasoning fixture','source_code'=>'isolated-agent-test','upstream_resource_key'=>'isolated-agent-text','upstream_model_code'=>'isolated-reasoning','upstream_channel_code'=>'isolated-channel','source_payload'=>json_encode(['market_metadata'=>['supports_vision'=>true]]),'status'=>1]);
    $sku=Db::name('power_market_sku')->insertGetId(['product_id'=>$product,'sku_key'=>'input','title'=>'Isolated input tokens','usage_unit'=>'token','sale_points'=>1,'status'=>1,'sale_status'=>1]);
    $textOnly=Db::name('power_market_product')->insertGetId(['product_code'=>'isolated-agent-text-only','resource_type'=>'model','model_type'=>'text','name'=>'Isolated text-only fixture','source_code'=>'isolated-agent-test','upstream_resource_key'=>'isolated-agent-text-only','upstream_model_code'=>'isolated-text-only','upstream_channel_code'=>'isolated-channel','source_payload'=>json_encode(['market_metadata'=>['supports_vision'=>false]]),'status'=>1]);
    Db::name('power_market_sku')->insert(['product_id'=>$textOnly,'sku_key'=>'input','title'=>'Isolated text-only input tokens','usage_unit'=>'token','sale_points'=>1,'status'=>1,'sale_status'=>1]);
    $real=Settings::resolve(91001,['reasoning_model'=>(string)$product]);
    agentCheck($real['reasoning_model']['id']===(string)$product && $real['reasoning_model']['model_code']==='isolated-reasoning','real database catalog resolves exact requested market identity');
    agentCheck($real['reasoning_model']['supports_vision']===true && $real['reasoning_model']['market_input_sku_id']===(int)$sku,'market capabilities and SKU resolved server-side');
    agentCheck(ConversationPreferences::read(91001,92001)===['preferences'=>[],'revision'=>0],'account preference starts empty at revision zero');
    $saved=ConversationPreferences::save(91001,92001,0,['reasoning_model'=>(string)$product,'generation_mode'=>'auto']);
    agentCheck(
        (string)($saved['preferences']['reasoning_model'] ?? '') === (string)$product
        && (string)($saved['preferences']['generation_mode'] ?? '') === 'auto'
        && (int)($saved['revision'] ?? 0) === 1,
        'preference save persists validated reasoning selection and mode'
    );
    agentCheck(ConversationPreferences::read(91001,92001)===$saved,'preference read returns durable account-scoped revision');
    rejectsSettings(fn()=>ConversationPreferences::save(91001,92001,0,['reasoning_model'=>(string)$product]),'PREFERENCE_VERSION_CONFLICT');
    agentCheck(ConversationPreferences::save(91001,92002,0,['reasoning_model'=>(string)$product])['revision']===1,'different users have independent default model preferences');
    rejectsSettings(fn()=>ConversationPreferences::save(91001,92001,1,['reasoning_model'=>'unknown']),'REASONING_MODEL_UNAVAILABLE');
    rejectsSettings(fn()=>ConversationPreferences::save(91001,92001,1,['reasoning_model'=>(string)$product,'provider_url'=>'forged']),'INVALID_AGENT_PREFERENCES');
    agentCheck(!FeatureGate::executionEnabled(91001),'Agent model execution remains opt-in when only the conversation panel is enabled');
    $provider=new MarketTextConversationProvider();
    rejectsSettings(fn()=>$provider->preflight(91001,92001,['settings'=>['reasoning_model'=>['id'=>(string)$product]]]),'CANVAS_AGENT_EXECUTION_DISABLED');
    AigcShortDramaService::saveConfig(91001,['canvas_agent'=>['enabled'=>true,'execution_enabled'=>true]]);
    agentCheck(FeatureGate::enabled(91001) && FeatureGate::executionEnabled(91001),'tenant configuration explicitly enables Agent model execution');
    AigcShortDramaService::saveConfig(91001,['canvas_agent'=>['enabled'=>true,'execution_enabled'=>true,'workflow'=>['enabled'=>false]]]);
    agentCheck(!FeatureGate::workflowEnabled(91001,'short_drama_creation') && FeatureGate::executionEnabled(91001),'tenant can stop the platform workflow without overriding its rules or disabling the Agent');
    AigcShortDramaService::saveConfig(91001,['canvas_agent'=>['enabled'=>true,'execution_enabled'=>true,'workflow'=>['enabled'=>true]]]);
    $provider->preflight(91001,92001,['settings'=>['reasoning_model'=>['id'=>(string)$product]]]);
    agentCheck(true,'enabled Agent preflight accepts the server-resolved market model without invoking it');
    $routed=MarketTextModelRuntimeService::resolveRoutedModel(91001,['id'=>(string)$textOnly],true);
    agentCheck(
        (int)($routed['product_id'] ?? 0) !== (int)$textOnly && !empty($routed['supports_vision']),
        'image context silently routes a text-only preference to a tenant-enabled vision model'
    );
    $fallbackClassifier=new ReflectionMethod(MarketTextModelRuntimeService::class,'isExplicitModelUnavailable');
    $fallbackClassifier->setAccessible(true);
    agentCheck($fallbackClassifier->invoke(null,'model_not_found: unavailable')===true && $fallbackClassifier->invoke(null,'provider timeout')===false,'only explicit upstream model rejection permits server-side model fallback');
    AigcShortDramaService::saveConfig(91001,['canvas_agent'=>['enabled'=>false,'execution_enabled'=>true]]);
    agentCheck(!FeatureGate::enabled(91001) && !FeatureGate::executionEnabled(91001),'turning off Agent also disables paid model execution');
    AigcShortDramaService::saveConfig(91001,['canvas_agent'=>['enabled'=>true,'execution_enabled'=>false]]);
    Db::name('tenant_power_market_sku_price')->insert(['tenant_id'=>91002,'sku_id'=>$sku,'sale_status'=>0,'sale_points'=>1]);
    rejectsSettings(fn()=>Settings::resolve(91002,['reasoning_model'=>(string)$product]),'REASONING_MODEL_UNAVAILABLE');
    agentCheck(Settings::resolve(91001,['reasoning_model'=>(string)$product])['reasoning_model']['id']===(string)$product,'another tenant disabling SKU does not hide current tenant model');
    Db::name('power_market_product')->where('id',$product)->update(['status'=>0]);
    rejectsSettings(fn()=>Settings::resolve(91001,['reasoning_model'=>(string)$product]),'REASONING_MODEL_UNAVAILABLE');
} finally {Db::rollback();}
echo "NOT_RUN Provider calls, prices/ledger settlement and full media-catalog integration; model records are synthetic isolated fixtures\n";
