<?php
/** Local rollback fixture for the platform and tenant catalogue and filtered shelf operations. */
require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\power\MarketModelFilter;
use app\common\service\power\PowerMarketService;
use app\common\service\power\TenantPowerMarketService;
use app\platformapi\lists\power\PowerMarketProductLists;
use app\common\service\open_platform\OpenStore;
use think\facade\Db;

$config = config('database.connections.mysql');
if ($config['hostname'] !== '127.0.0.1' || !in_array($config['database'], ['x_cn','z_cn'], true)) {
    throw new RuntimeException('Requires the local rollback database');
}
function assertModelFilter(bool $result, string $message): void {
    if (!$result) throw new RuntimeException($message);
}
$keyword = 'tag-fixture-'.bin2hex(random_bytes(4));
$tenant = 1;
$filters = ['channel_source'=>'official','marketing_tags'=>['new','special_offer']];
$params = $filters + ['resource_type'=>'model','catalog_type'=>'series','model_type'=>'image','keyword'=>$keyword,'status'=>1];
$makeProduct = new ReflectionMethod(PowerMarketService::class, 'upsertProduct');
$makeSku = new ReflectionMethod(PowerMarketService::class, 'upsertSku');
$list = static function (int $offset) use (&$params): PowerMarketProductLists {
    $list = (new ReflectionClass(PowerMarketProductLists::class))->newInstanceWithoutConstructor();
    $list->request = (new app\Request())->withGet($params);
    $list->limitOffset = $offset;
    $list->limitLength = 2;
    (new ReflectionProperty($list, 'searchWhere'))->setValue($list, [['resource_type','=','model'],['model_type','=','image']]);
    return $list;
};

Db::startTrans();
try {
    $ids = [];
    foreach ([
        [true,['new','special_offer']], [true,['new','special_offer']], [true,['new','special_offer']],
        [false,['new','special_offer']], [true,['special_offer']], [true,['new','special_offer'], time()-1],
    ] as $index => $case) {
        [$official,$tags] = $case;
        $metadata = ['marketing_tags'=>$tags,'is_official_channel'=>$official,'pricing'=>['pricing_v2'=>['resource_type'=>'factory_model']]];
        if (isset($case[2])) $metadata['new_until'] = $case[2];
        $resource = ['model_code'=>$keyword.$index,'model_name'=>$keyword.$index,'channel_code'=>'','type_code'=>'image'];
        $item = ['resource'=>$resource,'market_metadata'=>['model_type'=>'image','upstream_metadata'=>$metadata]];
        $product = $makeProduct->invoke(null, 'model', $resource, $item, true);
        $ids[] = (int)$product['id'];
        $makeSku->invoke(null, end($ids), ['sku_key'=>'default','title'=>'默认','usage_unit'=>'image','price'=>['points'=>2]], $item, 1);
    }
    // The last fixture has an expired new badge; rank it first only while recommended.
    $original = $params;
    unset($params['marketing_tags'], $params['channel_source']);
    PowerMarketProduct::where('id', $ids[5])->update(['is_recommended' => 1]);
    $synced = $makeProduct->invoke(null, 'model', $resource, $item, true);
    assertModelFilter((int)$synced['is_recommended'] === 1, 'Upstream sync cleared a local recommendation');
    $ranked = array_merge($list(0)->lists(), $list(2)->lists(), $list(4)->lists());
    assertModelFilter(array_map('intval', array_column($ranked, 'id')) === [$ids[5],$ids[2],$ids[1],$ids[0],$ids[3],$ids[4]], 'Priority or pagination changed');
    assertModelFilter($ranked[0]['marketing_tags'] === ['recommended','special_offer'], 'Expired new tag or missing recommendation');
    $params['marketing_tags'] = ['recommended'];
    assertModelFilter($list(0)->count() === 1, 'Recommended filter count differs from list');
    assertModelFilter(TenantPowerMarketService::models($tenant, $keyword, 1, 1, 1, 'image')['lists'][0]['id'] == $ids[5], 'Tenant first page omitted recommended model');
    PowerMarketProduct::where('id', $ids[5])->update(['is_recommended' => 0]);
    assertModelFilter($list(0)->count() === 0, 'Removing recommendation left a stale label');
    unset($params['marketing_tags']);
    foreach ([0 => $ids[0], $tenant => $ids[1]] as $owner => $joined) {
        $table = OpenStore::table('setting');
        $config = ['models' => [(string)$joined]];
        $row = $table->where('tenant_id', $owner)->lock(true)->find();
        if ($row) {
            $config += OpenStore::json($row['config']);
            OpenStore::table('setting')->where('id', $row['id'])->update(['config'=>OpenStore::encode($config)]);
        } else {
            OpenStore::table('setting')->insert(['tenant_id'=>$owner, 'config'=>OpenStore::encode($config)]);
        }
    }
    foreach ([1, 0] as $apiStatus) {
        $params['api_status'] = $apiStatus;
        $count = $apiStatus ? 1 : count($ids) - 1;
        assertModelFilter($list(0)->count() === $count, 'Platform API status count mismatch');
        $matched = PowerMarketProductLists::shelfProductIds($params);
        assertModelFilter(count($matched) === $count && in_array($ids[0], $matched) === (bool)$apiStatus, 'API filter broadened platform bulk scope');
        $tenantList = TenantPowerMarketService::models($tenant, $keyword, 1, 1, 10, 'image', false, ['api_status'=>$apiStatus]);
        assertModelFilter($tenantList['count'] === $count && in_array($ids[1], array_column($tenantList['lists'], 'id')) === (bool)$apiStatus, 'Tenant must use its own API selections');
    }
    $params = $original;
    $expected = array_slice($ids, 0, 3);
    $first = $list(0);
    $allRows = array_merge($first->lists(), $list(2)->lists());
    assertModelFilter($first->count()===3 && count($allRows)===3, 'Platform page count mismatch');
    $listedIds = array_map('intval',array_column($allRows, 'id')); sort($listedIds);
    assertModelFilter($listedIds === $expected && $allRows[0]['channel_source']==='official', 'Factory metadata or tag intersection lost');
    $bulkIds = array_map('intval',PowerMarketProductLists::shelfProductIds($params)); sort($bulkIds);
    assertModelFilter($bulkIds === $expected, 'Platform filtered bulk scope differs from paged results');
    $page = TenantPowerMarketService::models($tenant, $keyword, 1, 2, 2, 'image', false, $filters);
    assertModelFilter($page['count']===3 && count($page['lists'])===1, 'Tenant filter applied after pagination');
    $updated = TenantPowerMarketService::batchShelf($tenant, [], 0, true, $keyword, 'image', 1, $filters);
    assertModelFilter($updated['products'] === 3, 'Tenant bulk scope mismatch');
    $remaining = TenantPowerMarketService::models($tenant, $keyword, 1, 1, 15, 'image');
    assertModelFilter($remaining['count']===3 && !array_intersect($expected, array_column($remaining['lists'], 'id')), 'Tenant bulk modified unmatched model');
    PowerMarketService::batchShelf($bulkIds, 0);
    assertModelFilter(PowerMarketSku::whereIn('product_id',$ids)->where('sale_status',1)->count()===3, 'Platform shelf update crossed filter scope');
    foreach ([['all'=>'false'], ['channel_source'=>['official']], ['marketing_tags'=>'new'], ['marketing_tags'=>['bad']], ['model_type'=>'invalid']] as $bad) {
        try {
            MarketModelFilter::isBulkRequest($bad + ['all'=>1,'sale_status'=>0] + $params);
            throw new RuntimeException('Malformed bulk request accepted');
        } catch (InvalidArgumentException $expectedError) {}
    }
    assertModelFilter(PowerMarketProductLists::shelfProductIds(['marketing_tags'=>['__missing']] + $params)===[], 'No-match input broadened platform scope');
} finally {
    Db::rollback();
}
assertModelFilter(PowerMarketProduct::whereLike('product_code','%'.$keyword.'%')->count()===0,'Rollback failed');
echo "PASS factory channel, tag intersection and expiry, both paged counts, both bulk scopes, invalid inputs and rollback\n";
