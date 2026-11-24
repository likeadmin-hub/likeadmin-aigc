<?php
declare(strict_types=1);

require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

use app\common\enum\FileEnum;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\aigc_layer_decomposition\LayerDecompositionService as Tool;
use app\common\service\power\MarketLayerDecomposition as Market;
use app\common\service\power\MarketImageModelRuntimeService as Runtime;
use think\facade\Db;

$database = config('database.connections.mysql');
if (($database['hostname'] ?? '') !== '127.0.0.1' || ($database['database'] ?? '') !== 'z_cn') {
    throw new RuntimeException('Local fixture DB only');
}

function checkLayer(bool $pass, string $message): void
{
    if (!$pass) throw new RuntimeException($message);
    echo "PASS $message\n";
}

function assertLayerRejected(callable $call, string $message): void
{
    try {
        $call();
    } catch (InvalidArgumentException $error) {
        checkLayer(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

$tenant = 1;
$user = (int)Db::name('user')->where('tenant_id', $tenant)->where('user_money', '>', 1000)->value('id');
if (!$user) throw new RuntimeException('Local funded test user missing');
$wallet = static fn() => (float)Db::name('user')->where(['tenant_id'=>$tenant, 'id'=>$user])->value('user_money');
$balance = $wallet();
$nonce = bin2hex(random_bytes(8));
$uri = 'uploads/layer-fixture-'.$nonce.'.png';
$path = dirname(__DIR__).'/public/'.$uri;
$image = imagecreatetruecolor(640, 640);
if (!$image || !imagepng($image, $path)) throw new RuntimeException('Cannot write image fixture');

Db::startTrans();
try {
    Db::name('tenant_file')->insert(['tenant_id'=>$tenant, 'source_id'=>$user, 'source'=>FileEnum::SOURCE_USER,
        'type'=>FileEnum::IMAGE_TYPE, 'name'=>basename($uri), 'uri'=>$uri, 'storage_scope'=>'tenant',
        'storage_engine'=>'local', 'storage_domain'=>'https://example.com', 'create_time'=>time()]);
    $admin = Tool::config($tenant, true);
    $models = array_column($admin['models'], null, 'code');
    checkLayer(isset($models[Market::MODEL], $models[Market::FLASH], $models[Market::PRO]), 'All three available models are selectable');
    $otherTenantConfig = Tool::config(2)['model'];
    $request = new ReflectionMethod(Tool::class, 'request');
    $settle = new ReflectionMethod(Runtime::class, 'settle');

    foreach ([Market::MODEL, Market::FLASH, Market::PRO] as $model) {
        Tool::setup($tenant, ['default_model'=>$model]);
        $advanced = $model === Market::MODEL;
        $limit = $advanced ? 4 : 17;
        $config = Tool::config($tenant);
        checkLayer($config['model']['code'] === $model && !isset($config['models']), "$model is active only in the selected tenant app");
        $params = ['source_image'=>$uri, 'model_code'=>$model, 'prompt'=>'分离商品和背景', 'size'=>'1K',
            'output_format'=>'png', 'max_images'=>4, 'text_recognition'=>true];
        $selection = $request->invoke(null, $tenant, $user, $params);
        $quote = Tool::estimate($tenant, $user, $params);
        $snapshot = Runtime::selectionSnapshot($tenant, $selection, Market::APP);
        $native = Runtime::prepareRequest($snapshot, $selection, $tenant)->nativeInput;
        checkLayer($snapshot['model_code'] === $model && ($native['size'] ?? '') === '1K', "$model compiles its own market SKU");
        checkLayer(isset($native['max_images'], $native['text_recognition'], $native['nsfw_checker']) === $advanced,
            "$model only sends supported decomposition options");
        checkLayer($quote['quantity'] === 1 && (int)$quote['billing_details']['output_limit'] === $limit,
            "$model reserves $limit outputs for a single task");
        if ($advanced) {
            checkLayer(count($quote['billing_details']['items']) > 1, '5.5 keeps its input fee');
            continue;
        }
        checkLayer($quote['billing_details']['round_up']
            && $quote['user_charge_points'] === ceil($limit * $quote['user_unit_points']), "$model reserves the maximum at the current tenant rate");
        assertLayerRejected(fn() => Tool::estimate($tenant, $user, array_replace($params, ['model_code'=>Market::MODEL])), '默认模型已调整');
        $reserved = Runtime::reserve($tenant, $user, 'generate', '0', $selection, $selection, 1, Market::APP);
        checkLayer(abs($balance - $wallet() - $quote['user_charge_points']) < 0.00001, "$model debits the quoted reservation");
        $results = [['image_uri'=>'fixtures/base.png'], ['image_uri'=>'fixtures/layer.png']];
        $id = (int)$reserved['consumption_id'];
        $settle->invoke(null, $id, $results, '', "layer-$nonce", []);
        $settle->invoke(null, $id, $results, '', "layer-$nonce", []);
        $consumption = AiConsumptionLog::find($id);
        $actual = ceil(2 * $quote['user_unit_points']);
        checkLayer((float)$consumption['actual_user_price'] === $actual && (int)$consumption['quantity'] === 2
            && abs($balance - $wallet() - $actual) < 0.00001, "$model settles two outputs and refunds the difference once");
        $balance = $wallet();
    }
    AppDisplayConfigService::save($tenant, Market::APP, ['extra'=>[Market::MODEL_SETTING=>Market::MODEL]]);
    checkLayer(Tool::config($tenant)['model']['code'] === Market::PRO && Tool::config(2)['model'] === $otherTenantConfig,
        'Display saves preserve the configured model and tenant isolation');
    assertLayerRejected(fn() => Tool::setup($tenant, ['default_model'=>'unrelated-model']), '请选择图层分离模型');
} finally {
    Db::rollback();
    unlink($path);
}
