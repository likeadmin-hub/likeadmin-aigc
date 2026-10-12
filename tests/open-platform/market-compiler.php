<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\open_platform\{OpenSchemaNormalizer as N, OpenMarketContractCompiler as C, OpenStore as S, OpenContracts};
use think\facade\Db;
function checkCompiler(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$db = config('database.connections.mysql');
checkCompiler($db['hostname'] === '127.0.0.1' && $db['database'] === 'z_cn', 'Local rollback fixtures only');
checkCompiler(class_exists(N::class) && class_exists(C::class), 'Market compiler and schema normalizer are missing');
foreach (['marketPlan', 'importMarketContracts'] as $action) {
    \app\common\service\open_platform\OpenAdminPermission::assert('platform_admin', ['admin_id' => 1, 'root' => 1], $action);
    try { \app\common\service\open_platform\OpenAdminPermission::assert('tenant_admin', ['admin_id' => 1, 'root' => 1], $action); throw new RuntimeException('Tenant gained compiler management'); }
    catch (\app\common\service\open_platform\OpenException $e) { checkCompiler($e->httpStatus === 403, 'Wrong compiler permission rejection'); }
}
$normalized = N::normalize(['messages' => ['type' => 'array', 'required' => '1', 'children' => ['role' => ['type' => 'string', 'required' => true], 'content' => ['type' => 'string | array', 'required' => '1']]], 'enabled' => ['type' => 'boolean', 'required' => '']]);
checkCompiler(!$normalized['blockers'] && $normalized['schema']['required'] === ['messages'], 'Field-map required conversion');
checkCompiler($normalized['schema']['properties']['messages']['items']['required'] === ['role', 'content'], 'Nested children required conversion');
checkCompiler($normalized['schema']['properties']['messages']['items']['properties']['content']['type'] === ['string', 'array'], 'Union types are preserved');
$jsonSchema = ['type' => 'object', 'required' => ['content'], 'properties' => ['content' => ['type' => ['string', 'array'], 'maxLength' => 12]]];
checkCompiler(N::normalize($jsonSchema)['schema'] === $jsonSchema, 'Existing JSON Schema must remain intact');
checkCompiler(N::normalize(['properties' => ['text' => ['type' => 'string']], 'required' => ['text']])['schema']['type'] === 'object', 'Schema properties imply the required object input');
checkCompiler(N::normalize(['type' => ['type' => 'string', 'required' => false], 'prompt' => ['type' => 'string']])['shape'] === 'field_map', 'A parameter named type is not a JSON Schema root type');
checkCompiler((bool) N::normalize(['unknown' => ['type' => 'provider-magic']])['blockers'], 'Unknown mapping type must block');
checkCompiler((bool) N::normalize(['items' => ['type' => 'array', 'options' => 'anything the provider likes']])['blockers'], 'Ambiguous options must not invent allowed values');

$fixture = ['id' => 900001, 'name' => 'Compiler fixture', 'resource_type' => 'app_api', 'model_type' => '', 'upstream_app_code' => 'watermark_removal', 'upstream_api_code' => 'remove', 'upstream_model_code' => '', 'upstream_channel_code' => '', 'source_payload' => S::encode(['market_metadata' => ['method' => 'POST', 'call_type' => 1, 'params_schema' => ['url' => ['type' => 'string', 'required' => true, 'example' => 'https://example.com/public-post']], 'developer_doc_content' => "```json\n{\"code\":1,\"msg\":\"success\",\"data\":{\"title\":\"public result\"}}\n```"]]), 'skus' => [['id' => 900002, 'sku_key' => 'fixture', 'usage_unit' => 'per_call', 'usage_unit_size' => 1, 'sale_points' => 0, 'sale_status' => 1, 'locked_params' => '{}']]];
$row = C::compileProduct($fixture);
checkCompiler(count($row['drafts']) === 1 && !$row['blockers'], 'Ordinary synchronous fixed-call candidate must compile');
$draft = $row['drafts'][0]; OpenContracts::validate($draft);
checkCompiler($draft['config']['billable'] === true && $draft['config']['billing'][0]['max_units'] === 1, 'Zero retail price cannot erase billing ownership');
checkCompiler(!isset($draft['config']['verification']), 'Compiler must not forge publication evidence');
$async = $fixture; $meta = S::json($async['source_payload']); $meta['market_metadata']['call_type'] = 2; $async['source_payload'] = S::encode($meta);
checkCompiler(!C::compileProduct($async)['drafts'], 'Async dependency cannot be guessed');
$resource = $fixture; $resource['upstream_app_code'] = 'seedance'; $resource['upstream_api_code'] = 'createAsset';
checkCompiler(!C::compileProduct($resource)['drafts'], 'Shared upstream resources require an ownership adapter');
$get = $fixture; $meta = S::json($get['source_payload']); $meta['market_metadata']['method'] = 'GET'; $get['source_payload'] = S::encode($meta);
checkCompiler(!C::compileProduct($get)['drafts'], 'A GET resource is not automatically a free API');
$usage = $fixture; $usage['skus'][0]['usage_unit'] = 'token';
checkCompiler(!C::compileProduct($usage)['drafts'], 'Actual usage boundaries need a billing adapter');
$locked = $fixture; $locked['skus'][0]['locked_params'] = '{"_pricing_variant":"with_video"}';
checkCompiler(!C::compileProduct($locked)['drafts'], 'Internal SKU variants must be server-derived');
$economic = $fixture; $meta = S::json($economic['source_payload']); $meta['market_metadata']['developer_doc_content'] = "```json\n{\"code\":1,\"data\":{\"usage\":{\"points_cost\":1}}}\n```"; $economic['source_payload'] = S::encode($meta);
checkCompiler(!C::compileProduct($economic)['drafts'], 'Upstream economic response needs a projection adapter');
$missing = $fixture; $meta = S::json($missing['source_payload']); unset($meta['market_metadata']['params_schema']); $missing['source_payload'] = S::encode($meta);
checkCompiler(!C::compileProduct($missing)['drafts'], 'Absent request schema must block');
$review = ['review_note' => 'Verified source schema review', 'input' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']], 'example' => ['url' => 'https://example.com/public-post']];
checkCompiler(count(C::compileProduct($missing, [], $review)['drafts']) === 1, 'Explicit reviewed structural overrides can repair missing input metadata');
foreach (['upstream_url' => 'https://evil.invalid', 'executor' => 'tool', 'verification' => ['request_id' => 'fake'], 'api_key' => 'forbidden'] as $field => $value) {
    try { C::compileProduct($fixture, [], $review + [$field => $value]); throw new RuntimeException('Unsafe review accepted: ' . $field); }
    catch (\app\common\service\open_platform\OpenException $e) { checkCompiler($e->errorType === 'invalid_review', 'Wrong review rejection'); }
}

Db::startTrans();
try {
    $settingsBefore = S::table('setting')->select()->toArray();
    $requestsBefore = S::table('request')->count();
    $realProduct = Db::name('power_market_product')->where(['upstream_app_code' => 'watermark_removal', 'upstream_api_code' => 'remove'])->find();
    checkCompiler((bool)$realProduct, 'Local fixed-call source fixture missing');
    $realSku = Db::name('power_market_sku')->where('product_id', $realProduct['id'])->find();
    checkCompiler((bool)$realSku, 'Local source SKU missing');
    unset($realProduct['id']); $tag = bin2hex(random_bytes(6));
    $realProduct['product_code'] = 'compiler_fixture_' . $tag;
    $realProduct['upstream_resource_key'] = 'compiler_fixture_' . $tag;
    $realProduct['source_payload'] = $fixture['source_payload']; $realProduct['status'] = 1;
    $id = Db::name('power_market_product')->insertGetId($realProduct);
    unset($realSku['id']); $realSku['product_id'] = $id; $realSku['sku_key'] = 'compiler_fixture_' . $tag; $realSku['usage_unit'] = 'per_call'; $realSku['usage_unit_size'] = 1; $realSku['locked_params'] = '{}'; $realSku['sale_status'] = 1; $realSku['status'] = 1;
    Db::name('power_market_sku')->insert($realSku);
    $plan = C::plan(1, [], [$id]);
    checkCompiler($plan['counts']['products'] === 1 && $plan['counts']['drafts'] === 1, 'Selected fixture plan must remain complete');
    $first = C::import(1, [], [$id]);
    checkCompiler(count($first['created']) === 1, 'Import should create contract and document drafts');
    $cid = $first['created'][0]['contract_id']; $did = $first['created'][0]['document_id'];
    checkCompiler(OpenContracts::detail($cid)['status'] === 'draft' && S::table('document')->where('id', $did)->value('status') === 'draft', 'Import never publishes');
    $second = C::import(1, [], [$id]);
    checkCompiler(!$second['created'] && count($second['skipped']) === 1, 'Repeated import must be idempotent');
    $contract = OpenContracts::detail($cid); $contract['config']['title'] = 'Human edited title'; OpenContracts::save($contract);
    S::table('document')->where('id', $did)->update(['content' => 'Human edited documentation']);
    C::import(1, [], [$id]);
    checkCompiler(OpenContracts::detail($cid)['config']['title'] === 'Human edited title' && S::table('document')->where('id', $did)->value('content') === 'Human edited documentation', 'Compiler must preserve manual work');
    S::table('contract')->where('id', $cid)->update(['status' => 'published']);
    C::import(1, [], [$id]);
    checkCompiler(S::table('contract')->where('code', $contract['code'])->count() === 1, 'Compiler cannot replace published contracts');
    checkCompiler($settingsBefore === S::table('setting')->select()->toArray() && $requestsBefore === S::table('request')->count(), 'Compiler cannot enable tenants or execute requests');
    $full = C::plan(1);
    checkCompiler($full['counts']['products'] === count($full['products']) && $full['counts']['drafts'] + $full['counts']['blocked_candidates'] === $full['counts']['candidates'], 'All candidates must be classified');
    echo 'PASS nested schema normalization, reviewed structural overrides, fixed-call billing, ownership/async/economic blockers, rollback import idempotency and no publication/execution', "\n";
} finally { Db::rollback(); }
