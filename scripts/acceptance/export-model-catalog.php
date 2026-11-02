<?php
declare(strict_types=1);

// Read-only inventory; never starts workers or calls a supplier.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['tenant-id:', 'out:']);
$tenant = filter_var($options['tenant-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$out = $options['out'] ?? '';
if (!$tenant || !is_string($out) || $out === '') {
    fwrite(STDERR, "Usage: php scripts/acceptance/export-model-catalog.php --tenant-id=ID --out=FILE\n"); exit(2);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();
$products = think\facade\Db::name('power_market_product')->where(['resource_type'=>'model','model_type'=>'text','status'=>1])
    ->field('id,name,model_type,status,upstream_model_code,upstream_channel_code')->order('id')->select()->toArray();
$groups = app\common\service\power\MarketTextModelRuntimeService::modelGroups((int)$tenant);
$fields = array_flip(['id','product_id','name','model_code','channel_code','channel_source','protocol','market_sku_id','market_input_sku_id','market_output_sku_id','max_tokens','max_output_tokens','context_window','supports_vision']);
$models = array_map(static fn(array $m): array => array_intersect_key($m,$fields), (array)($groups[0]['options'] ?? []));
$payload = ['tenant_id'=>(int)$tenant,'database'=>config('database.connections.mysql.database'), 'platformModels'=>$products,
    'tenantModels'=>$models,'applications'=>['aigc_llm','aigc_canvas','aigc_short_drama'], 'exported_at'=>date(DATE_ATOM)];
if (!is_dir(dirname($out))) mkdir(dirname($out),0700,true);
if (file_put_contents($out,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX) === false) throw new RuntimeException('Cannot save inventory');
chmod($out,0600);
echo json_encode(['platform_models'=>count($products),'tenant_models'=>count($models),'paid_requests'=>0,'output'=>$out],JSON_UNESCAPED_UNICODE)."\n";
