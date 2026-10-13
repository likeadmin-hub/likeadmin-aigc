<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\open_platform\{OpenSchema,OpenMaterials,OpenPolicy,OpenSafeHttp,OpenImagePricing,OpenToolAdapter,OpenToolRequest,OpenVault,OpenStore};

/** Reviewed native adapters. Public API publication and API keys are separate from logged-in users. */
final class AgentCapabilityCatalog
{
    private const SERVICES=['aigc_image'=>AigcImageService::class,'aigc_video'=>AigcVideoService::class];
    public static function schema(string $code): array
    {
        $properties=['prompt'=>['type'=>'string','minLength'=>1,'maxLength'=>10000],'negative_prompt'=>['type'=>'string','maxLength'=>4000],'ratio'=>['type'=>'string'],'reference_images'=>['type'=>'array','maxItems'=>8,'items'=>['type'=>'string'],'description'=>'用户素材的 file_id；禁止构造或猜测地址']];
        if($code==='aigc_image')$properties+=['quantity'=>['type'=>'integer','minimum'=>1,'maximum'=>4],'quality'=>['type'=>'string']];
        else $properties+=['duration'=>['type'=>'integer','minimum'=>1,'maximum'=>60],'resolution'=>['type'=>'string']];
        return ['type'=>'object','properties'=>$properties,'required'=>['prompt'],'additionalProperties'=>false];
    }
    private static function authorize(int $tenant,int $user,string $code): void
    {
        if(!isset(self::SERVICES[$code]))throw new \InvalidArgumentException('此创作工具尚未接入');
        $denied=AppAccessService::assertTenantCanUse($tenant,$code,$user,true,false);
        if($denied||(int)(self::SERVICES[$code]::config($tenant)['status']??0)!==1)throw new \InvalidArgumentException('当前账号不可使用此工具');
    }
    public static function all(int $tenant,int $user): array
    {
        $out=[];
        foreach(self::SERVICES as $code=>$service){
            try{self::authorize($tenant,$user,$code);}catch(\InvalidArgumentException){continue;}
            $out[$code]=['code'=>$code,'name'=>$code==='aigc_image'?'图片创作':'视频创作','description'=>$code==='aigc_image'?'文字或参考图生成图片':'文字或参考图生成视频','schema'=>self::schema($code),'version'=>'home-1','model_options'=>$service::config($tenant)['option_config']??[]];
        }
        return $out;
    }
    public static function models(int $tenant): array
    {
        $text=AigcLlmService::config($tenant)['option_config']??[];
        $text['models']=array_values(array_filter($text['models']??[],static function($model)use($tenant){try{\app\common\service\power\MarketTextModelRuntimeService::resolveAgentModel($tenant,(string)$model['code']);return true;}catch(\Exception){return false;}}));
        return ['text'=>$text,'image'=>AigcImageService::config($tenant)['option_config']??[],'video'=>AigcVideoService::config($tenant)['option_config']??[]];
    }
    public static function prepare(array $run,string $code,array $params): array
    {
        $tenant=(int)$run['tenant_id'];$user=(int)$run['user_id'];self::authorize($tenant,$user,$code);
        // Only fields declared by this native adapter are accepted from the model.
        unset($params['model_id'],$params['channel']);
        OpenSchema::validateRequest($params,self::schema($code),true);
        OpenPolicy::validateInput($params);
        $params=OpenMaterials::resolve($tenant,$user,$params);OpenSafeHttp::checkInputs($params);
        $type=$code==='aigc_image'?'image':'video';$model=(string)(AgentStore::json($run['preferences'])['models'][$type]??'');
        if($model==='')throw new \InvalidArgumentException('请先在模型偏好中选择'.$type.'模型');
        $params['model_id']=$model;$params['channel']=$model;
        if($code==='aigc_image')$quote=OpenImagePricing::applicationBudget($tenant,$user,$params);
        else{
            $capture=AigcVideoService::openPlatformAdmission($tenant,$params);$quote=OpenToolAdapter::estimateBudget($capture['quote']);
            if(!$quote)throw new \InvalidArgumentException('当前视频模型无法提供可靠估价');
            $quote['snapshot']['native_video_blob']=OpenVault::seal(OpenStore::encode(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$code]+$capture));
        }
        return ['capability'=>['code'=>$code,'name'=>$type==='image'?'生成图片':'生成视频','version'=>'home-1'],'params'=>$params,'quote'=>$quote];
    }
    public static function submit(array $prepared): array
    {
        $request=request();$code=$prepared['capability']['code'];self::authorize((int)$request->tenantId,(int)$request->userId,$code);
        $scoped=OpenToolRequest::from($request,$prepared['params'],['app_code'=>$code,'route'=>'POST tasks']);
        app()->instance('request',$scoped);
        try{return AgentToolContext::within($prepared,static fn()=>self::SERVICES[$code]::generate((int)$request->tenantId,(int)$request->userId,$prepared['params']));}
        finally{app()->instance('request',$request);}
    }
    public static function query(array $run,string $code,int $taskId): array
    {
        if(!isset(self::SERVICES[$code]))throw new \InvalidArgumentException('未知任务类型');
        return self::SERVICES[$code]::taskDetail((int)$run['tenant_id'],$taskId,(int)$run['user_id']);
    }
}
