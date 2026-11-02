<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\power\MarketModelSource as Source;
use app\common\service\app\aigc_digital_human\DigitalHumanMarketService as DigitalHuman;
function check($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$rows = [['id'=>1,'upstream_channel_code'=>'relay'],['id'=>2,'upstream_channel_code'=>'official'],['id'=>3],['id'=>4,'upstream_channel_code'=>' OFFICIAL '],['id'=>5,'upstream_channel_code'=>'relay']];
$ordered = Source::officialFirst($rows);
check(array_column($ordered,'id') === [2,4,1,3,5], 'Official routes must precede other routes with stable order');
check(array_column($rows,'id') === [1,2,3,4,5], 'Original rows must not change');
check(array_column(array_slice($ordered,0,2),'id') === [2,4], 'Official routes must be ordered before pagination');
check(Source::officialFirst([['id'=>1,'channel_source'=>'unknown','upstream_channel_code'=>'official'],['id'=>2,'channel_source'=>'official']])[0]['id'] === 2, 'Do not promote explicit unknown source');
$platform = [
 ['code'=>'custom-master','provider'=>'xhadmin','model'=>'xiaojiayu1.0','name'=>'配置中的大师模型'],
 ['code'=>'custom-all','provider'=>'xhadmin','model'=>'xiaojiayu2.0','name'=>'配置中的全能模型'],
 ['code'=>'unrelated','provider'=>'other','model'=>'xiaojiayu3.0','name'=>'不相关提供商'],
];
$names = DigitalHuman::modelDisplayNames($platform,[['code'=>'custom-all','name'=>'租户模型名','model'=>'']]);
check($names === ['xiaojiayu1.0'=>'配置中的大师模型','xiaojiayu2.0'=>'租户模型名'], 'Display names must join configured models and tenant overrides');
$option = ['value'=>'market_lipsync_app:39:75','label'=>'xiaojiayu2.0','locked_params'=>['model'=>'xiaojiayu2.0'],'tenant_unit_price'=>2,'usage_unit_size'=>1,'channel_source'=>'unknown'];
$channel = DigitalHuman::channel($option,$names);
check($channel['name'] === '租户模型名' && $channel['label'] === '租户模型名', 'PC and mobile must receive configured display name');
check($channel['code'] === $option['value'] && $channel['model'] === 'xiaojiayu2.0' && $channel['locked_params'] === $option['locked_params'], 'Display mapping must preserve selection and upstream model');
check($channel['channel_source'] === 'unknown', 'Name mapping must not invent official source');
$option['locked_params']['model'] = 'future-model'; $option['label']='新模型展示名';
check(DigitalHuman::channel($option,$names)['name'] === '新模型展示名', 'Unconfigured new models must retain catalogue display name');
$metadata = ['params_schema'=>['model'=>['options'=>[['value'=>'future-model','label'=>'模型元数据名称']]]]];
$option['model_display_name'] = \app\common\service\power\MarketPicLipsyncAppRuntimeService::modelDisplayName($metadata,'future-model');
check(DigitalHuman::channel($option,[])['name'] === '模型元数据名称', 'Explicit model metadata must precede raw SKU title');
$option['display_name_overridden']=true; $option['name']='租户市场展示名';
check(DigitalHuman::channel($option,['future-model'=>'平台旧名称'])['name'] === '租户市场展示名', 'Tenant market display override must take priority');
$duplicates = array_merge($platform, [['code'=>'second','provider'=>'xhadmin','model'=>'xiaojiayu1.0','name'=>'较低排序平台名']]);
check(DigitalHuman::modelDisplayNames($duplicates,[['code'=>'second','name'=>'租户重复模型名']])['xiaojiayu1.0'] === '租户重复模型名', 'Tenant override must win duplicate runtime model mappings');
$public = \app\common\service\app\aigc_copywriting\CopywritingModelService::publicModel(['id'=>'5','name'=>'文案模型','channel_source'=>'official','channel_source_label'=>'官方渠道','api_key'=>'private','channel_code'=>'internal-route','config_json'=>['secret'=>'hidden']]);
check($public === ['id'=>'5','name'=>'文案模型','channel_source'=>'official','channel_source_label'=>'官方渠道'], 'Copywriting public projection must preserve badges without leaking route/config/secrets');
echo "PASS stable official-first ordering and configured lipsync display names; runtime identity preserved\n";
