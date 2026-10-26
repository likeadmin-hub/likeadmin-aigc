<?php
/** Read-only by default. --refresh-meters updates only verified unchanged SKU meter metadata, never prices or historical tasks. */
require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
use app\common\model\power\{PowerMarketProduct, PowerMarketSku};
use app\common\service\app\UpstreamPricingService;
use app\common\service\power\{MarketGenerationCatalogService, MarketGenerationSchema};
use app\common\service\app\aigc_canvas\CanvasGenerationParameters;
use think\facade\Db;
$args = getopt('', ['tenant-id:', 'upstream', 'refresh-meters', 'output:']);
$tenant = (int)($args['tenant-id'] ?? 1);
$report = ['time'=>date('c'),'tenant_id'=>$tenant,'counts'=>[],'issues'=>[],'parameters'=>[],'prices'=>[]];
$applicationIds = [];
foreach (MarketGenerationCatalogService::directory($tenant) as $type=>$catalog) {
    $report['counts'][$type] = count($catalog['channels']);
    foreach ($catalog['channels'] as $model) {
        if (($model['resource_type'] ?? '') === 'app_api') $applicationIds[] = (int)$model['market_product_id'];
        $passed = 0;
        foreach (CanvasGenerationParameters::definitions($model, $type) as $field) {
            $values = $field['options'];
            if (array_key_exists('default',$field)) $values[] = $field['default'];
            if ($field['type'] === 'boolean') $values = [true,false];
            foreach (['minimum','maximum'] as $key) if (isset($field[$key])) $values[] = $field[$key];
            foreach ($values as $value) {
                try { CanvasGenerationParameters::apply(['advanced_params'=>[$field['key']=>$value]],$model,$type); $passed++; }
                catch (Throwable $e) { $report['issues'][]=['kind'=>'parameter','product'=>$model['market_product_id']??0,'field'=>$field['key'],'value'=>$value,'error'=>$e->getMessage()]; }
            }
        }
        $report['parameters'][] = ['product'=>$model['market_product_id']??0,'name'=>$model['name']??$model['label']??'','checks'=>$passed,'fields'=>count(CanvasGenerationParameters::definitions($model,$type))];
    }
}
if (isset($args['upstream'])) {
    $products = PowerMarketProduct::where('status',1)->where('resource_type','model')->select()->toArray();
    if ($applicationIds !== []) $products = array_merge($products, PowerMarketProduct::where('status',1)->whereIn('id',array_unique($applicationIds))->select()->toArray());
    foreach ($products as $product) {
        $id=(int)$product['id'];
        try { $remote=$product['resource_type'] === 'app_api'
            ? UpstreamPricingService::queryAppApi($product['upstream_app_code'],$product['upstream_api_code'])
            : UpstreamPricingService::queryModel($product['upstream_model_code'],$product['upstream_channel_code']); }
        catch(Throwable $e){$report['issues'][]=['kind'=>'upstream','product'=>$id,'name'=>$product['name'],'error'=>$e->getMessage()];continue;}
        if (empty($remote['available'])) {$report['issues'][]=['kind'=>'unavailable','product'=>$id,'name'=>$product['name']];continue;}
        $remoteSkus=array_column((array)($remote['pricing_v2']['items']??[]),null,'sku_key');
        foreach (PowerMarketSku::where(['product_id'=>$id,'status'=>1])->select()->toArray() as $sku) {
            $source=$remoteSkus[$sku['sku_key']]??null;
            $matches=$source && (float)($source['price']['points']??-1)===(float)$sku['upstream_price'] && ($source['usage_unit']??'')===$sku['usage_unit'] && (float)($source['usage_unit_size']??1)===(float)$sku['usage_unit_size'] && MarketGenerationSchema::array($sku['locked_params'])==($source['locked_params']??[]);
            $row=['product'=>$id,'sku'=>(int)$sku['id'],'name'=>$product['name'],'title'=>$sku['title'],'unit'=>$sku['usage_unit'],'local_price'=>(float)$sku['upstream_price'],'upstream_price'=>$source['price']['points']??null,'matched'=>$matches,'meter_summary'=>$source['meter_summary']??''];
            if (!$matches) $report['issues'][]=['kind'=>'price_or_spec_changed']+$row;
            elseif (isset($args['refresh-meters']) && !empty($source['meter_summary'])) {
                $payload=MarketGenerationSchema::array($sku['source_payload']);
                foreach(['meter_summary','pricing_rule_key'] as $key)$payload[$key]=(string)($source[$key]??'');
                Db::transaction(function()use($sku,$payload){
                    $current=Db::name('power_market_sku')->where('id',$sku['id'])->lock(true)->find();
                    foreach(['upstream_price','usage_unit','usage_unit_size','locked_params','source_payload'] as $key)if($current[$key]!==$sku[$key] && (!in_array($key,['locked_params','source_payload'],true)||MarketGenerationSchema::array($current[$key])!==MarketGenerationSchema::array($sku[$key])))throw new RuntimeException('核对期间规格已变化，请重新核对');
                    Db::name('power_market_sku')->where('id',$sku['id'])->update(['source_payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                });
                $row['meter_refreshed']=true;
            }
            $report['prices'][]=$row;
        }
    }
}
$report['summary']=['parameter_checks'=>array_sum(array_column($report['parameters'],'checks')),'prices'=>count($report['prices']),'matched_prices'=>count(array_filter($report['prices'],fn($r)=>$r['matched'])),'issues'=>count($report['issues'])];
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
if(isset($args['output']))file_put_contents($args['output'],$json);else echo $json.PHP_EOL;
echo json_encode($report['summary'],JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['issues']===[]?0:2);
