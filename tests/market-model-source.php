<?php
require dirname(__DIR__).'/vendor/autoload.php';
require getenv('MARKET_MODEL_SOURCE_FILE') ?: dirname(__DIR__).'/app/common/service/power/MarketModelSource.php';
use app\common\service\power\MarketModelSource as S;
foreach ([['official','official'],['OpenAIYI','third_party'],['minimax','third_party'],['','unknown']] as [$code,$expected]) {
    if (S::channel($code)['channel_source'] !== $expected) throw new RuntimeException('Incorrect source: '.$code);
}
if (S::product(['name'=>'官方 GPT','upstream_channel_code'=>'relay'])['channel_source'] !== 'third_party') throw new RuntimeException('Brand name asserted official ownership');
if (S::product(['name'=>'Third party','upstream_channel_code'=>'official'])['channel_source'] !== 'official') throw new RuntimeException('Upstream channel identity lost');
echo "PASS authoritative upstream channel classification; names and provider brands do not assert official ownership\n";

use app\common\service\power\MarketCatalogBadges as Catalog;

$routes = [
    ['model_code'=>'same', 'channel_code'=>'official'],
    ['model_code'=>'same', 'channel_code'=>'relay'],
    ['model_code'=>'same', 'channel_code'=>'', 'pricing'=>['pricing_v2'=>['resource_type'=>'factory_model']]],
];
$public = [
    ['model_code'=>'same','channel_code'=>'official','marketing_tags'=>['new','special_offer']],
    ['model_code'=>'same','channel_code'=>'relay','marketing_tags'=>[]],
    ['model_code'=>'same','channel_code'=>'model_factory','factory_model'=>1,'is_official_channel'=>true,'marketing_tags'=>['new']],
    ['model_code'=>'public-only','channel_code'=>'relay','marketing_tags'=>['special_offer']],
];
$rows = Catalog::models($routes, $public);
if (count($rows) !== 3 || array_column($rows,'marketing_tags') !== [['new','special_offer'],[],['new']]
    || $rows[2]['channel_source'] !== 'official') throw new RuntimeException('Catalog join crossed routes or added a model');
$rows = Catalog::models([['marketing_tags'=>[]] + $routes[0]], $public);
if ($rows[0]['marketing_tags'] !== []) throw new RuntimeException('Authenticated clear must take priority');
$rows = Catalog::models([$routes[0]], [$public[0],$public[0]]);
if (isset($rows[0]['marketing_tags'])) throw new RuntimeException('Ambiguous identity received marketing tags');
$app = ['id'=>1,'code'=>'app','apis'=>[['id'=>8,'code'=>'generate'],['id'=>9,'code'=>'query']]];
$featured = ['id'=>1,'code'=>'app','marketing_tags'=>['special_offer'],'apis'=>[['id'=>8,'code'=>'generate','marketing_tags'=>['new']]]];
$rows = Catalog::applications([$app], [$featured]);
if (($rows[0]['apis'][0]['marketing_tags']??null) !== ['new'] || isset($rows[0]['apis'][1]['marketing_tags'])) throw new RuntimeException('App-wide tags leaked between API actions');
$product = ['upstream_channel_code'=>'relay','source_payload'=>['market_metadata'=>['catalog_badges'=>[
    'channel_source'=>'official', 'marketing_tags'=>['new','special_offer'],'new_until'=>time()-1
]]]];
if (S::product($product) !== ['channel_source'=>'official','channel_source_label'=>'官方渠道','marketing_tags'=>['special_offer']]) throw new RuntimeException('Expired new badge or declared channel lost');
echo "PASS marketing tags follow exact model, factory and application identities and expiry\n";
