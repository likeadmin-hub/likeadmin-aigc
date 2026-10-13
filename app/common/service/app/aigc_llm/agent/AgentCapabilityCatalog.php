<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\service\open_platform\{OpenCatalog,OpenContracts,OpenToolRegistry,OpenToolAdapter,OpenSchema,OpenMaterials,OpenToolTasks,OpenPolicy};
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_video\AigcVideoService;

/** An allow-listed view of published creation contracts, never an arbitrary API caller. */
final class AgentCapabilityCatalog
{
    public static function all(int $tenant,int $user): array
    {
        $out=[];
        foreach(OpenCatalog::forUser($tenant,$user) as $app){
            if(!in_array($app['code'],['aigc_image','aigc_video'],true))continue;
            if($app['kind']!=='tool'||empty($app['available'])||in_array($app['code'],['aigc_canvas','aigc_short_drama','aigc_llm'],true))continue;
            foreach($app['operations'] as $op){
                if($op['method']!=='POST'||!str_ends_with($op['path'],'/tasks')||empty($op['published'])||empty($op['execution_ready']))continue;
                if(empty($op['schema']['properties']))continue;
                $out[$app['code']]=['code'=>$app['code'],'name'=>$app['name'],'description'=>$op['description'],'schema'=>$op['schema'],'example'=>$op['example'],'version'=>$op['version'],'path'=>$op['path'],'limitations'=>$app['limitations']??[]];
            }
        }
        return $out;
    }
    public static function models(int $tenant): array
    {
        return [
            'text'=>AigcLlmService::config($tenant)['option_config']??[],
            'image'=>AigcImageService::config($tenant)['option_config']??[],
            'video'=>AigcVideoService::config($tenant)['option_config']??[],
        ];
    }
    public static function prepare(array $run,string $code,array $params): array
    {
        $cap=self::all((int)$run['tenant_id'],(int)$run['user_id'])[$code]??null;
        if(!$cap)throw new \InvalidArgumentException('此创作工具暂不可用或参数契约未完善');
        foreach(['tenant_id','user_id','api_key','billing_override','price_snapshot','callback_url','webhook_url'] as $key)if(array_key_exists($key,$params))throw new \InvalidArgumentException('工具包含不允许的参数');
        $preferences=AgentStore::json($run['preferences']);
        $type=$code==='aigc_video'?'video':($code==='aigc_music'?'music':'image');
        $model=(string)($preferences['models'][$type]??'');
        // Specialized apps keep their own configured model; generic creators must honor the selected model.
        if(in_array($code,['aigc_image','aigc_video'],true)){
            if($model==='')throw new \InvalidArgumentException('请先在模型偏好中选择'.$type.'模型');
            unset($params['model'],$params['model_code'],$params['market_product_id'],$params['market_sku_id']);
            $params['model_id']=$model;$params['channel']=$model;
        }
        $op=OpenContracts::tool('POST',$cap['path']);
        OpenSchema::validateRequest($params,$op['input'],true);
        $params=OpenMaterials::resolve((int)$run['tenant_id'],(int)$run['user_id'],OpenMaterials::nativeInputs($code,$params));
        $quote=OpenToolAdapter::budget(request(),$op,$params);
        return ['capability'=>$cap,'operation'=>$op,'params'=>$params,'quote'=>$quote];
    }
    public static function submit(array $prepared): array
    {
        $op=$prepared['operation'];
        return AgentToolContext::within($prepared,static fn()=>OpenToolAdapter::call(request(),$op,$prepared['params']));
    }
    public static function query(array $run,string $code,int $taskId): array
    {
        $tool=OpenToolRegistry::get($code);$op=$tool['operations']['GET tasks/{task_id}']??null;
        if(!$op)throw new \InvalidArgumentException('工具暂不支持任务查询');
        return OpenToolAdapter::call(request(),$op+['app_code'=>$code,'route'=>'GET tasks/{task_id}','params'=>['task_id'=>$taskId],'internal'=>true,'history'=>true],[]);
    }
}
