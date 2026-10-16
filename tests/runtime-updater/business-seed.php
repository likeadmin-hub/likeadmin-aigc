<?php
declare(strict_types=1);
if(getenv('SYSTEM_SERVICE_DISPOSABLE')!=='1'||getenv('PHP_DATABASE_DATABASE')!=='x_cn')throw new RuntimeException('Private disposable seed only');
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,(string)$e);exit(1);});
use think\facade\Db;
function fixtureRow(string $table,array $values):int {
    if(isset($values['id']) && Db::name($table)->where('id',$values['id'])->count()){Db::name($table)->where('id',$values['id'])->update($values);return (int)$values['id'];}
    foreach(Db::query('SHOW COLUMNS FROM la_'.$table) as $column) {
        if(array_key_exists($column['Field'],$values)||$column['Null']==='YES'||$column['Default']!==null||str_contains($column['Extra'],'auto_increment'))continue;
        $values[$column['Field']]=preg_match('/int|decimal|float|double/',$column['Type'])?0:'';
    }
    return (int)Db::name($table)->insertGetId($values);
}
fixtureRow('tenant',['id'=>1,'sn'=>'offline_fixture','name'=>'Offline tenant','point_balance'=>1000000]);
fixtureRow('user',['id'=>1,'sn'=>1,'tenant_id'=>1,'account'=>'offline_fixture','user_money'=>1000000]);
foreach(['aigc_llm','aigc_image','aigc_video','aigc_music','aigc_pic_lipsync'] as $code) {
    if(Db::name('app')->where('code',$code)->value('status')!=='installed')app\common\service\app\AppRegistryService::installFromLocal($code);
    Db::name('tenant_app')->where(['tenant_id'=>1,'app_code'=>$code])->delete();
    fixtureRow('tenant_app',['tenant_id'=>1,'app_code'=>$code,'buy_status'=>'paid','enable_status'=>'enabled','shelf_status'=>'on','expire_time'=>time()+31536000]);
}
foreach(['text','image','video','tts','tts_async','stt'] as $type) {
    $model=in_array($type,['text','image','video'],true);
    $id=fixtureRow('power_market_product',['source_code'=>'offline','upstream_resource_key'=>$type,'product_code'=>'offline_'.$type,'name'=>'Offline '.$type,'resource_type'=>$model?'model':'app_api','model_type'=>$model?$type:'','upstream_model_code'=>match($type){'text'=>'gpt-fixture','image'=>'qwen-image',default=>'wan2.2'},'upstream_channel_code'=>'fixture','upstream_app_code'=>$model?'':'voice_tts','upstream_api_code'=>$model?'':$type,'source_payload'=>json_encode(['market_metadata'=>['protocol'=>'openai_chat','protocols'=>['openai_chat'],'default_params'=>[],'params_schema'=>[]]])]);
    foreach($type==='text'?['input_token','output_token']:['default'] as $key)fixtureRow('power_market_sku',['product_id'=>$id,'sku_key'=>$key,'title'=>'Offline '.$key,'locked_params'=>'{}','selectable_params'=>'{}','usage_unit'=>$type==='text'?'token':(str_starts_with($type,'tts')?'character':'per_call'),'usage_unit_size'=>$type==='text'?1000:1,'sale_points'=>1,'source_payload'=>'{}']);
}
fixtureRow('aigc_short_drama_config',['tenant_id'=>1,'status'=>1,'config_json'=>'{"canvas_agent":{"enabled":true}}']);
$id=fixtureRow('power_market_product',['source_code'=>'offline','upstream_resource_key'=>'pic_lipsync','product_code'=>'offline_pic','name'=>'Offline lipsync','resource_type'=>'app_api','upstream_app_code'=>'pic_lipsync','upstream_api_code'=>'submit','source_payload'=>'{}']);
foreach(['fast','standard','max'] as $quality)fixtureRow('power_market_sku',['product_id'=>$id,'sku_key'=>$quality,'title'=>$quality,'locked_params'=>json_encode(['quality'=>$quality]),'selectable_params'=>'{}','usage_unit'=>'input_second','usage_unit_size'=>1,'sale_points'=>1,'source_payload'=>'{}']);
echo "PASS deterministic offline catalogue and funded disposable wallets seeded\n";
