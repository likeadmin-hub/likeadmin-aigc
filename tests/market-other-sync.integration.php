<?php
/** Local rollback regression: reclassification preserves IDs, prices and shelf state. No upstream calls. */
require dirname(__DIR__) . '/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\power\PowerMarketService;
use app\platformapi\lists\power\PowerMarketProductLists;
use think\facade\Db;

$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== '127.0.0.1' || !in_array($config['database'] ?? '', ['x_cn', 'z_cn'], true)) {
    fwrite(STDERR, "Rollback regression requires a local x_cn/z_cn database\n");
    exit(1);
}
$call = static function ($method, ...$args) {
    $m = new ReflectionMethod(PowerMarketService::class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
};
$check = static function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$key = 'other-regression-' . bin2hex(random_bytes(5));
$resource = ['model_code'=>$key,'model_name'=>$key,'channel_code'=>'official','type_code'=>'video'];
$item = ['resource'=>$resource,'market_metadata'=>['model_type'=>'video','catalog_model_type'=>'video']];
$category = ['code'=>'published-video-tools','name'=>'视频处理','path'=>[['code'=>'published-video-tools','name'=>'视频处理','order'=>1]]];
$list = static function (string $catalog, string $category = '') use ($key): PowerMarketProductLists {
    $list = (new ReflectionClass(PowerMarketProductLists::class))->newInstanceWithoutConstructor();
    $list->request = (new app\Request())->withGet(['resource_type'=>'model','catalog_type'=>$catalog,'keyword'=>$key,'other_category_code'=>$category]);
    $list->limitOffset = 0; $list->limitLength = 20;
    $property = new ReflectionProperty($list, 'searchWhere');
    $property->setAccessible(true); $property->setValue($list, [['resource_type','=','model']]);
    return $list;
};
$before = [PowerMarketProduct::count(), PowerMarketSku::count()];
Db::startTrans();
try {
    $product = $call('upsertProduct','model',$resource,$item,true);
    $sku = ['sku_key'=>'720p','title'=>'720P','price'=>['points'=>2]];
    $call('upsertSku',(int)$product->id,$sku,$item,1);
    $saved = PowerMarketSku::where('product_id',$product->id)->find();
    $saved->save(['sale_points'=>7,'sale_status'=>0]);
    $item['market_metadata'] = ['model_type'=>'other','catalog_model_type'=>'other','other_category'=>$category];
    $moved = $call('upsertProduct','model',$resource,$item,true);
    $sku['price']['points'] = 3;
    $call('upsertSku',(int)$moved->id,$sku,$item,1);
    $updated = PowerMarketSku::find($saved->id);
    $check((int)$moved->id === (int)$product->id && $moved['model_type'] === 'other','Reclassification changed product identity');
    $check((int)$updated->id === (int)$saved->id && (float)$updated['upstream_price'] === 3.0
        && (float)$updated['configured_sale_points'] === 7.0 && (int)$updated['sale_status'] === 0,'Sync lost SKU settings');
    $check($list('model')->count() === 0 && $list('series')->count() === 0,'Other model leaked into regular catalogues');
    $check($list('other',$category['code'])->count() === 1 && $list('other','foreign-category')->count() === 0,'Category filtering/count mismatch');
    $rows = $list('other',$category['code'])->lists();
    $check(count($rows) === 1 && $rows[0]['other_category_name'] === '视频处理' && $rows[0]['status'] === 0,'Other list lost category/shelf state');
    unset($item['market_metadata']['other_category']);
    $call('upsertProduct','model',$resource,$item,true);
    $check(PowerMarketService::detail((int)$product->id)['other_category_code'] === $category['code'],'Tree outage erased category');
    $item['market_metadata']['other_category'] = [];
    $call('upsertProduct','model',$resource,$item,true);
    $check($list('other','uncategorized')->count() === 1,'Published removal did not clear former category');
    echo "PASS reclassification, identity, SKU price/shelf preservation, category filtering, regular catalogue isolation and tree outage recovery\n";
} finally {
    Db::rollback();
}
$check($before === [PowerMarketProduct::count(),PowerMarketSku::count()],'Rollback did not restore rows');
