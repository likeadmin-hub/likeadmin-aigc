<?php
/** Compare the real Agent model options against both marketplace catalogues without running a model. */
require dirname(__DIR__).'/vendor/autoload.php';
(new think\App())->initialize();

use app\common\service\app\aigc_llm\agent\AgentComposerSettings;
use app\common\service\power\TenantPowerMarketService as Market;
use app\platformapi\lists\power\PowerMarketProductLists;
use think\facade\Db;

function checkCatalogue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$config = config('database.connections.mysql');
checkCatalogue($config['hostname']==='127.0.0.1' && $config['database']==='z_cn', 'Local rollback fixture only');
$product = Db::name('power_market_product')->where([
    'resource_type'=>'model','model_type'=>'text','upstream_model_code'=>'kimi-k3','status'=>1,
])->find();
checkCatalogue((bool)$product, 'Synchronization fixture requires the local Kimi K3 catalogue product');
$id = (int)$product['id'];
$sku = Db::name('power_market_sku')->where(['product_id'=>$id,'status'=>1])->order('id')->find();
checkCatalogue((bool)$sku, 'Kimi K3 SKU missing');
$skuId = (int)$sku['id'];
$original = [(int)$sku['sale_status'], Db::name('tenant_power_market_product')->where(['tenant_id'=>1,'product_id'=>$id])->find()];
$beforeTasks = Db::name('ai_app_task')->count();
$displayName = 'Kimi K3 满血版 '.bin2hex(random_bytes(4));
Db::startTrans();
try {
    // Supply a known publishable local catalogue state to cover previously hidden factory models.
    Db::name('power_market_sku')->where('id',$skuId)->update(['sale_status'=>1]);
    Market::savePrices(1,$id,[['sku_id'=>$skuId,'state_only'=>true,'sale_status'=>1]]);
    Market::saveProductDisplay(1,$id,['name'=>$displayName]);

    request()->adminInfo = ['admin_id'=>1];
    request()->adminId = 1;
    request()->withGet(['keyword'=>'kimi-k3','catalog_type'=>'model','resource_type'=>'model','model_type'=>'text']);
    $platform = new PowerMarketProductLists();
    $card = array_column($platform->lists(), null, 'id')[$id] ?? [];
    checkCatalogue($platform->count()>=1 && ($card['upstream_model_code']??null)==='kimi-k3'
        && (float)$card['text_token_rates']['usage_unit_size']===1000000.0
        && (float)$card['text_token_rates']['cost']['input']>0
        && (float)$card['text_token_rates']['cost']['output']>0, 'Ordinary platform catalogue hid factory text or omitted rate units');

    $agent = array_column(AgentComposerSettings::textOptions(1)['models'],null,'market_product_id');
    $agentView = Market::models(1,'','',1,100,'text',true);
    $expected = array_map('intval',array_keys($agent));
    $actual = array_map('intval',array_column($agentView['lists'],'id'));
    sort($expected); sort($actual);
    checkCatalogue($agentView['count']===count($expected) && $actual === $expected,'Agent-filtered tenant list differs from the actual picker');
    $matches = Market::models(1,$displayName,'',1,15,'text',true);
    $tenant = $matches['lists'][0] ?? [];
    checkCatalogue($matches['count']===1 && (int)$tenant['id']===$id && $tenant['name']===$agent[$id]['name']
        && $tenant['agent_text_price']===['input'=>$agent[$id]['tenant_input_unit_price'],'output'=>$agent[$id]['tenant_output_unit_price']],
        'Tenant display-name search, product identity or advertised Agent prices diverged');
    checkCatalogue(Market::models(91003,$displayName,'',1,15,'text',true)['count']===0,
        'Tenant display search leaked an override');

    Market::savePrices(1,$id,[['sku_id'=>$skuId,'state_only'=>true,'sale_status'=>0]]);
    checkCatalogue(Market::models(1,$displayName,'',1,15,'text',true)['count']===0,
        'Tenant shelf change did not remove the model from the Agent filter');
    $paused = Market::models(1,$displayName,'',1,15,'text')['lists'][0] ?? [];
    checkCatalogue(($paused['agent_text_available']??null)===false && (int)$paused['id']===$id,
        'Administrators cannot find their paused model in the full catalogue');
    echo "PASS platform factory text catalogue, tenant name search and isolation, Agent price and SKU parity, tenant shelf changes\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    $failed = true;
} finally {
    Db::rollback();
}
checkCatalogue($original === [(int)Db::name('power_market_sku')->where('id',$skuId)->value('sale_status'),
    Db::name('tenant_power_market_product')->where(['tenant_id'=>1,'product_id'=>$id])->find()]
    && Db::name('ai_app_task')->count()===$beforeTasks,'Fixture did not fully roll back');
exit(!empty($failed)?1:0);
