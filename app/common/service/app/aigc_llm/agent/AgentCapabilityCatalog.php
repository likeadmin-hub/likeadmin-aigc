<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\open_platform\{OpenToolPricing,OpenSchema,OpenMaterials,OpenPolicy,OpenSafeHttp,OpenImagePricing,OpenToolAdapter,OpenToolRequest,OpenVault,OpenStore};

/** Reviewed native adapters. Public API publication and API keys are separate from logged-in users. */
final class AgentCapabilityCatalog
{
    private const SERVICES=['aigc_image'=>AigcImageService::class,'aigc_video'=>AigcVideoService::class,'aigc_music'=>\app\common\service\app\aigc_music\AigcMusicService::class,'aigc_fitting'=>\app\common\service\app\aigc_fitting\AigcFittingService::class];
    public const TYPES=['aigc_image'=>'image','aigc_video'=>'video','aigc_music'=>'music','aigc_fitting'=>'image',AgentSpeechTool::CODE=>'voice'];
    private const NAMES=['aigc_image'=>'图片创作','aigc_video'=>'视频创作','aigc_music'=>'音乐创作','aigc_fitting'=>'AI试衣'];
    private static function creationConfig(int $tenant,string $code): array
    {
        return $code==='aigc_video' ? AigcVideoService::creationConfig($tenant) : self::SERVICES[$code]::config($tenant);
    }
    public static function schema(string $code): array
    {
        self::assertSupported($code);
        if($code===AgentSpeechTool::CODE)return AgentSpeechTool::schema();
        if($code===AgentTranscriptionTool::CODE)return AgentTranscriptionTool::schema();
        if(in_array($code,['aigc_music','aigc_fitting'],true))return AgentExtendedTools::schema($code);
        $properties=['prompt'=>['type'=>'string','minLength'=>1,'maxLength'=>10000],'negative_prompt'=>['type'=>'string','maxLength'=>4000],'ratio'=>['type'=>'string'],'reference_images'=>['type'=>'array','maxItems'=>8,'items'=>['type'=>'string','pattern'=>'^of_[a-f0-9]{32}$'],'description'=>'用户素材的 file_id；禁止构造或猜测地址']];
        if($code==='aigc_image')$properties+=['quantity'=>['type'=>'integer','minimum'=>1,'maximum'=>4],'quality'=>['type'=>'string']];
        else $properties+=['duration'=>['type'=>'integer','minimum'=>1,'maximum'=>60],'resolution'=>['type'=>'string']];
        return ['type'=>'object','properties'=>$properties,'required'=>['prompt'],'additionalProperties'=>false];
    }
    public static function assertSupported(string $code): void
    {
        // Deliberate homepage allowlist: never import the studio or public API tool catalogue wholesale.
        if(!in_array($code,[AgentTranscriptionTool::CODE,AgentSpeechTool::CODE],true)&&!isset(self::SERVICES[$code]))throw new \InvalidArgumentException('首页 Agent 不支持此工具；工作室应用不在可调用范围内');
    }
    private static function authorize(int $tenant,int $user,string $code): void
    {
        self::assertSupported($code);
        $denied=AppAccessService::assertTenantCanUse($tenant,$code,$user,true,false);
        if($denied||(int)(self::creationConfig($tenant,$code)['status']??0)!==1)throw new \InvalidArgumentException('当前账号不可使用此工具');
    }
    public static function all(int $tenant,int $user): array
    {
        $out=[];
        foreach(self::SERVICES as $code=>$service){
            try{self::authorize($tenant,$user,$code);}catch(\InvalidArgumentException){continue;}
            $out[$code]=['code'=>$code,'name'=>self::NAMES[$code],'description'=>match($code){'aigc_image'=>'文字或参考图生成图片','aigc_video'=>'文字或参考图生成视频','aigc_music'=>'生成歌曲或纯音乐，不是朗读配音 TTS','aigc_fitting'=>'用户上传服装图和模特图生成试衣图片'},'schema'=>self::schema($code),'version'=>'home-1','model_options'=>self::creationConfig($tenant,$code)['option_config']??[]];
        }
        try{AgentTranscriptionTool::quote($tenant);$out[AgentTranscriptionTool::CODE]=['code'=>AgentTranscriptionTool::CODE,'name'=>'语音转文字','description'=>'识别音频或视频音轨中的语音，转写完成后继续总结或回答；按次收费，需要先保存方案','schema'=>AgentTranscriptionTool::schema(),'version'=>'home-stt-1'];}catch(\InvalidArgumentException){}
        if(AgentSpeechTool::models($tenant)['models'])$out[AgentSpeechTool::CODE]=['code'=>AgentSpeechTool::CODE,'name'=>'文字配音','description'=>'把正文朗读为MP3；同步服务最多500字符、异步服务最多10000字符，以用户所选配音服务为准。使用默认模型和音色，不是音乐或音色克隆','schema'=>AgentSpeechTool::schema(),'version'=>'home-speech-2'];
        return $out;
    }
    public static function models(int $tenant): array
    {
        $text=AigcLlmService::config($tenant)['option_config']??[];
        $text['models']=array_values(array_filter($text['models']??[],static function($model)use($tenant){try{\app\common\service\power\MarketTextModelRuntimeService::resolveAgentModel($tenant,(string)$model['code']);return true;}catch(\Exception){return false;}}));
        return ['text'=>$text,'image'=>AigcImageService::config($tenant)['option_config']??[],'video'=>self::creationConfig($tenant,'aigc_video')['option_config']??[],'music'=>self::SERVICES['aigc_music']::config($tenant)['option_config']??[],'voice'=>AgentSpeechTool::models($tenant)];
    }
    public static function describe(array $run,string $code): array
    {
        if($code===AgentSpeechTool::CODE)return AgentSpeechTool::describe($run);
        if($code===AgentTranscriptionTool::CODE)return ['code'=>$code,'schema'=>AgentTranscriptionTool::schema(),'quote'=>AgentTranscriptionTool::quote((int)$run['tenant_id']),'instruction'=>'只支持本话题音频或视频中的语音识别，不识别音色身份或音乐音效。plan_creation 保存收费转写方案，完成后系统自动恢复当前回答；不要猜测未识别的内容。'];
        self::authorize((int)$run['tenant_id'],(int)$run['user_id'],$code);
        $type=self::TYPES[$code];
        $selection=(string)(AgentStore::json($run['preferences'])['models'][$type]??'');
        return ['code'=>$code,'schema'=>self::schema($code),'constraints'=>AgentExtendedTools::constraints($code),'selected_model'=>AgentCreationContext::selectedModel(
            self::creationConfig((int)$run['tenant_id'],$code)['option_config']??[],$selection),
            'instruction'=>'只能使用 selected_model；不自行替换。费用只能引用 plan_creation 成功返回的估价。素材字段仅传用户 file_id；试衣分别传 garment_images 服装图、model_images 模特图，不能猜测角色。音乐只支持歌曲或纯音乐，不支持配音/TTS；必须向用户说明 constraints 中的能力限制，不能承诺精确时长，也不能把计费规格说成输出时长保证。参数不兼容时解释具体原因，不能声称方案已经保存。'];
    }
    public static function prepare(array $run,string $code,array $params): array
    {
        if($code===AgentSpeechTool::CODE)return AgentSpeechTool::prepare($run,$params);
        if($code===AgentTranscriptionTool::CODE)return AgentTranscriptionTool::prepare($run,$params);
        $tenant=(int)$run['tenant_id'];$user=(int)$run['user_id'];self::authorize($tenant,$user,$code);
        // Only fields declared by this native adapter are accepted from the model.
        unset($params['model_id'],$params['channel']);
        OpenSchema::validateRequest($params,self::schema($code),true);
        OpenPolicy::validateInput($params);$inputs=$params;
        $params=OpenMaterials::resolve($tenant,$user,$params);OpenSafeHttp::checkInputs($params);
        $type=self::TYPES[$code];$model=(string)(AgentStore::json($run['preferences'])['models'][$type]??'');
        if($model==='')throw new \InvalidArgumentException('请先在模型偏好中选择'.$type.'模型');
        $params['model_id']=$model;$params['channel']=$model;
        if($code==='aigc_music')$quote=OpenToolPricing::applicationMusicBudget($tenant,$user,$params);
        elseif($code==='aigc_fitting'){
            $params['mode']='custom';$quote=OpenImagePricing::applicationFittingBudget($tenant,$user,$params);
            $recipe=OpenStore::json(OpenVault::open($quote['snapshot']['native_prepared_blob']))['prepared']['image_payload'];
            if((string)$recipe['model_id']!==$model)throw new \InvalidArgumentException('所选图片模型不支持试衣，请在模型偏好中选择支持的模型');
            foreach(['quality','ratio'] as $field)if(!empty($params[$field])&&(string)$params[$field]!== (string)$recipe[$field])throw new \InvalidArgumentException('所选试衣模型不支持此规格，请调整画质或比例');
            $params=array_replace($params,array_intersect_key($recipe,array_flip(['quality','ratio','quantity'])));
        }
        elseif($code==='aigc_image')$quote=OpenImagePricing::applicationBudget($tenant,$user,$params);
        else{
            $capture=AigcVideoService::openPlatformAdmission($tenant,$params);$quote=OpenToolAdapter::estimateBudget($capture['quote']);
            if(!$quote)throw new \InvalidArgumentException('当前视频模型无法提供可靠估价');
            $quote['snapshot']['native_video_blob']=OpenVault::seal(OpenStore::encode(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$code]+$capture));
        }
        return ['run_id'=>(int)$run['id'],'capability'=>['code'=>$code,'name'=>self::NAMES[$code],'version'=>'home-1'],'inputs'=>$inputs,'params'=>$params,'quote'=>$quote,'constraints'=>AgentExtendedTools::constraints($code)];
    }
    public static function submit(array $prepared): array
    {
        if($prepared['capability']['code']===AgentSpeechTool::CODE)return AgentSpeechTool::submit($prepared);
        if($prepared['capability']['code']===AgentTranscriptionTool::CODE)return AgentTranscriptionTool::submit($prepared);
        $request=request();$code=$prepared['capability']['code'];self::authorize((int)$request->tenantId,(int)$request->userId,$code);
        $scoped=OpenToolRequest::from($request,$prepared['params'],['app_code'=>$code,'route'=>'POST tasks']);
        app()->instance('request',$scoped);
        try{return AgentToolContext::within($prepared,static fn()=>self::SERVICES[$code]::generate((int)$request->tenantId,(int)$request->userId,$prepared['params']));}
        finally{app()->instance('request',$request);}
    }
    public static function query(array $run,string $code,int $taskId): array
    {
        if($code===AgentSpeechTool::CODE)return AgentSpeechTool::query((int)$run['tenant_id'],(int)$run['user_id'],$taskId);
        if($code===AgentTranscriptionTool::CODE)return AgentTranscriptionTool::query((int)$run['tenant_id'],(int)$run['user_id'],$taskId);
        if(!isset(self::SERVICES[$code]))throw new \InvalidArgumentException('未知任务类型');
        return self::SERVICES[$code]::taskDetail((int)$run['tenant_id'],$taskId,(int)$run['user_id']);
    }
}
