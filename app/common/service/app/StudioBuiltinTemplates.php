<?php
declare(strict_types=1);
namespace app\common\service\app;

use app\common\model\app\AppCase;
use app\common\service\ai\StudioMediaService as Media;
use app\common\service\storage\StorageConfigService;
use app\common\service\SubmitLockService;
use app\common\service\app\aigc_motion_sync\{MotionSyncService as Motion,MotionSyncTemplates as MotionTemplates,MotionSyncJobs};
use app\common\service\app\aigc_story_commerce\{StoryCommerceService as Story,StoryCommercePolicy as Policy,StoryCommerceTemplates as StoryTemplates,StoryCommerceCatalog as StoryCatalog,StoryCommerceJobs};
use app\common\service\power\MarketMotionSyncRuntimeService;
use InvalidArgumentException;
use think\facade\Db;
use think\facade\Cache;

/** Only platform-reviewed definitions travel to other installations; tenant cases stay local. */
final class StudioBuiltinTemplates
{
    private static bool $writing=false;
    private static function policy(string $app): string {return $app==='aigc_store_visit'?\app\common\service\app\aigc_store_visit\StoreVisitPolicy::class:Policy::class;}
    private static function catalog(string $app): string {return $app==='aigc_store_visit'?\app\common\service\app\aigc_store_visit\StoreVisitCatalog::class:StoryCatalog::class;}
    public static function writing(): bool {return self::$writing;}
    public static function table(){return Db::name('app_builtin_inspiration');}
    public static function service(string $app): string {StudioBuiltinCatalog::assertApp($app);return match($app){'aigc_motion_sync'=>MotionTemplates::class,'aigc_store_visit'=>\app\common\service\app\aigc_store_visit\StoreVisitTemplates::class,default=>StoryTemplates::class};}
    public static function info(string $app,int $id): ?array {StudioBuiltinCatalog::assertApp($app);return self::table()->where(['app_code'=>$app,'case_id'=>$id])->find()?:null;}
    public static function definition(string $app,int $id): ?array {$link=self::info($app,$id);return $link?(StudioBuiltinCatalog::all($app)[$link['builtin_key']]??null):null;}
    public static function ids(string $app): array {return array_map('intval',self::table()->where('app_code',$app)->whereNotNull('case_id')->column('case_id'));}
    public static function stored(string $app,array $row): array {return (array)($row['config_json']['generation_request']['advanced_params'][substr($app,5)]??[]);}
    public static function media(string $app,array $row): array {return (array)($row['config_json']['generation_request']['advanced_params'][substr($app,5).'_media']??[]);}
    public static function portableBody(string $app,array $input): array
    {
        StudioBuiltinCatalog::assertApp($app);
        if($app===Motion::APP){
            $b=Motion::body($input);$b['image']=StudioBuiltinCatalog::publicAsset(array_replace((array)$b['image'],['type'=>'image']));$b['video']=StudioBuiltinCatalog::publicAsset(array_replace((array)$b['video'],['type'=>'video']));
            \app\common\service\app\aigc_motion_sync\MotionSyncMedia::assertFacts($b);return $b;
        }
        $b=(self::policy($app))::body($input);$b['images']=array_map(static fn($a)=>StudioBuiltinCatalog::publicAsset(array_replace($a,['type'=>'image'])),$b['images']);
        if($app==='aigc_store_visit')\app\common\service\app\aigc_store_visit\StoreVisitPolicy::assertMaterials($b['images']);
        if(!$b['images'])throw new InvalidArgumentException('内置模板缺少商品图片');
        $b['video_selection']=array_intersect_key($b['video_selection'],array_flip(['quality','ratio','duration']));$b['video_selection']['model_id']='';
        $b['genre_snapshot']=array_intersect_key($b['genre_snapshot'],array_flip(['code','name','instruction','version','status','sort']));
        $b['style_snapshot']=array_intersect_key($b['style_snapshot'],array_flip(['id','name','prompt','mode']));
        if(empty($b['genre_snapshot']['instruction']))throw new InvalidArgumentException('内置模板缺少编剧规则快照');
        $b['script']=(self::policy($app))::scriptValue($b['script'],(int)($b['video_selection']['duration']??0),$b);$b['script_input_key']=(self::policy($app))::inputKey($b);return $b;
    }
    private static function product(string $app,array $identity): ?array
    {
        $where=['model_type'=>$app===Motion::APP?'other':'video'];
        foreach(['source_code','upstream_resource_key','resource_type','upstream_app_code'] as $key)if(isset($identity[$key]))$where[$key]=$identity[$key];
        return Db::name('power_market_product')->where($where)->find()?:null;
    }
    private static function identity(string $app,array $body,int $owner): array
    {
        if($app===Motion::APP){[$m]=Motion::model($owner,$body);$id=(int)$m['market_product_id'];}
        else {if(!preg_match('/:(\d+)$/D',(string)($body['video_selection']['model_id']??''),$match))throw new InvalidArgumentException('请选择算力市场模型');$id=(int)$match[1];}
        $p=Db::name('power_market_product')->where('id',$id)->find();
        if(!$p||($p['model_type']??'')!==($app===Motion::APP?'other':'video'))throw new InvalidArgumentException('模板模型尚未同步到算力市场');
        return array_intersect_key($p,array_flip(['source_code','upstream_resource_key','resource_type','upstream_app_code']));
    }
    public static function bodyFor(string $app,int $tenant,array $entry,bool $strict=true): array
    {
        $b=$entry['configuration'];$p=self::product($app,$entry['model_identity']);
        if($app===Motion::APP){
            if($strict){$found=false;foreach(MarketMotionSyncRuntimeService::options($tenant) as $m)if($p&&(int)$m['market_product_id']===(int)$p['id']&&$m['model_key']===$b['model_key']){$found=true;break;}
                if(!$found)throw new InvalidArgumentException('内置模板的动作模型尚未同步或上架，不会替换为其他模型');Motion::request($tenant,$b);}
            return $b;
        }
        $b['video_selection']['model_id']=$p?($p['resource_type']==='model'?'market_video_model:'.$p['id']:'market_video_app:'.$p['upstream_app_code'].':'.$p['id']):'';
        $b['script_input_key']=(self::policy($app))::inputKey($b);
        if($strict){if(!$p)throw new InvalidArgumentException('内置模板的视频模型尚未同步，请同步并上架后再使用');(self::catalog($app))::selection($tenant,$b);}
        return $b;
    }
    public static function sync(string $app,bool $force=false): void
    {
        $all=StudioBuiltinCatalog::all($app);if(self::$writing||!$all)return;
        $cacheKey='studio_builtin:'.hash('sha256',StudioBuiltinCatalog::directory($app).'|'.config('database.connections.mysql.database').'|'.config('database.connections.mysql.prefix'));
        $sourceHash=Policy::hash($all);if(!$force&&Cache::get($cacheKey)===$sourceHash)return;
        $old=self::$writing;self::$writing=true;
        try{foreach($all as $entry)Db::transaction(static function()use($app,$entry){
            self::table()->extra('IGNORE')->insert(['app_code'=>$app,'builtin_key'=>$entry['key'],'tenant_id'=>0,'version'=>0,'source_hash'=>'','create_time'=>time(),'update_time'=>time()]);
            $link=self::table()->where(['app_code'=>$app,'builtin_key'=>$entry['key']])->lock(true)->find();
            if((int)$link['version']>$entry['version'])throw new InvalidArgumentException('内置模板源版本较旧，请先合并已保存的内置配置');
            $b=self::bodyFor($app,0,$entry,false);$hash=Policy::hash([$entry,$b]);$current=$link['case_id']?AppCase::where('id',$link['case_id'])->find():null;
            if($link['source_hash']===$hash&&$current)return;
            $key=substr($app,5);$image=$app===Motion::APP?$b['image']:$b['images'][0];$images=$app===Motion::APP?[$b['image']]:$b['images'];
            if($current)AppCase::where(['id'=>$link['case_id'],'tenant_id'=>0,'app_code'=>$app])->update(['delete_time'=>0]);
            $saved=AppCaseService::save(0,$app,['id'=>$current?(int)$link['case_id']:0,'title'=>$entry['title'],'prompt'=>$app===Motion::APP?$b['prompt']:(self::policy($app))::prompt($b),
                'media_type'=>'video','cover_uri'=>$image['uri'],'media_uri'=>$entry['media']['uri'],'reference_images'=>array_column($images,'uri'),
                'config_json'=>['generation_request'=>['advanced_params'=>[$key=>$b,$key.'_media'=>$entry['media']]]],
                'status'=>$entry['deleted']?0:$entry['status'],'sort'=>$entry['sort'],'source_task_id'=>0,'source_result_id'=>0]);
            self::table()->where(['app_code'=>$app,'builtin_key'=>$entry['key']])->update(['case_id'=>(int)$saved['id'],'version'=>$entry['version'],'source_hash'=>$hash,'update_time'=>time()]);
            if($entry['deleted'])AppCase::where('id',$saved['id'])->update(['delete_time'=>time()]);
        });Cache::set($cacheKey,$sourceHash,60);}finally{self::$writing=$old;}
    }
    private static function shared(int $owner,array $asset): array
    {
        if(($asset['storage_scope']??'')==='platform'&&StudioBuiltinCatalog::asset((string)($asset['uri']??''),(string)($asset['storage_engine']??''),(string)($asset['storage_domain']??'')))return StudioBuiltinCatalog::publicAsset($asset);
        $engine=(string)($asset['storage_engine']??'');$config=StorageConfigService::getStoredFileConfig($owner,(string)($asset['storage_scope']??''),$engine);$settings=$config['engine'][$engine]??[];
        if(!isset($config['engine'][$engine])||rtrim((string)($settings['domain']??''),'/')!==rtrim((string)($asset['storage_domain']??''),'/')||!empty($settings['private_access'])||!empty($settings['private']))throw new InvalidArgumentException('内置模板须使用持久化的公开 OSS 素材，私有素材不能直接共享');
        return StudioBuiltinCatalog::publicAsset($asset);
    }
    public static function publish(string $app,int $id,array $params,int $scope=0): array
    {
        self::service($app);if(empty($params['confirm_public_media']))throw new InvalidArgumentException('请确认素材可作为跨站点公开共享素材');
        $lock=SubmitLockService::acquire($app.'_builtin_source');
        try{return Db::transaction(static function()use($app,$id,$params,$scope){
            $query=AppCase::where(['id'=>$id,'app_code'=>$app,'delete_time'=>0]);if($scope>0)$query->where('tenant_id',$scope);
            $source=$query->lock(true)->find();if(!$source)throw new InvalidArgumentException('模板不存在或不属于当前租户');$source=$source->toArray();
            if(self::info($app,$id))throw new InvalidArgumentException('该记录已经是内置模板，请在模板列表维护');
            $link=self::table()->where(['app_code'=>$app,'source_case_id'=>$id])->find();$prior=$link?(StudioBuiltinCatalog::all($app)[$link['builtin_key']]??null):null;
            if((int)($params['expected_version']??0)!==(int)($prior['version']??0))throw new InvalidArgumentException('内置模板已更新，请刷新后重试');
            $b=self::stored($app,$source);$owner=(int)$source['tenant_id'];$media=self::media($app,$source);
            foreach($app===Motion::APP?[$b['image'],$b['video']]:$b['images'] as $a)Media::verify($owner,$a,($a===($b['video']??null))?20:10);Media::verify($owner,$media,20);
            if($app===Motion::APP)Motion::request($owner,$b);else {(self::catalog($app))::selection($owner,$b);(self::policy($app))::prompt($b);}
            $identity=self::identity($app,$b,$owner);
            if($app===Motion::APP){$b['image']=self::shared($owner,$b['image']);$b['video']=self::shared($owner,$b['video']);}
            else $b['images']=array_map(static fn($a)=>self::shared($owner,$a),$b['images']);
            $entry=StudioBuiltinCatalog::write($app,['schema_version'=>1,'app_code'=>$app,'key'=>$prior['key']??'builtin-'.bin2hex(random_bytes(12)),'version'=>(int)($prior['version']??0)+1,
                'title'=>$source['title'],'status'=>(int)$source['status'],'sort'=>(int)$source['sort'],'deleted'=>false,'model_identity'=>$identity,'configuration'=>$b,'media'=>self::shared($owner,$media)]);
            self::sync($app);self::table()->where(['app_code'=>$app,'builtin_key'=>$entry['key']])->update(['source_case_id'=>$id,'source_tenant_id'=>$owner]);
            $id=(int)self::table()->where(['app_code'=>$app,'builtin_key'=>$entry['key']])->value('case_id');$service=self::service($app);return $service::detail($scope,$id);
        });}finally{SubmitLockService::release($lock);}
    }
    public static function fromJob(string $app,int $id,array $params,int $scope=0): array
    {
        if(empty($params['confirm_public_media']))throw new InvalidArgumentException('请确认素材可作为跨站点公开共享素材');
        $service=self::service($app);$job=$app===Motion::APP?MotionSyncJobs::owned($scope,0,$id,true):($app==='aigc_store_visit'?\app\common\service\app\aigc_store_visit\StoreVisitJobs::owned($scope,0,$id,true):StoryCommerceJobs::owned($scope,0,$id,true));
        if($job['status']!=='success'||($app!==Motion::APP&&$job['action']!=='video'))throw new InvalidArgumentException('仅已完成视频可以设为内置模板');
        $lock=SubmitLockService::acquire($app.'_builtin_job_'.$id);
        try{return Db::transaction(static function()use($app,$id,$params,$service,$job,$scope){
            $link=self::table()->where(['app_code'=>$app,'source_job_id'=>$id,'source_tenant_id'=>(int)$job['tenant_id']])->find();
            if($link){self::sync($app);return $service::detail($scope,(int)$link['case_id']);}
            $sources=AppCase::where(['app_code'=>$app,'tenant_id'=>$job['tenant_id'],'source_task_id'=>$id])->column('id');
            $linked=$sources?self::table()->where('app_code',$app)->whereIn('source_case_id',$sources)->find():null;
            if($linked){self::table()->where('id',$linked['id'])->update(['source_job_id'=>$id]);self::sync($app);return $service::detail($scope,(int)$linked['case_id']);}
            $case=$service::fromJob($scope,$id,$params);$prior=self::table()->where(['app_code'=>$app,'source_case_id'=>(int)$case['id']])->find();
            if($prior){self::table()->where('id',$prior['id'])->update(['source_job_id'=>$id]);return $service::detail($scope,(int)$prior['case_id']);}
            $out=self::publish($app,(int)$case['id'],$params,$scope);self::table()->where(['app_code'=>$app,'case_id'=>$out['id']])->update(['source_job_id'=>$id]);return $out;
        });}finally{SubmitLockService::release($lock);}
    }
    public static function change(string $app,int $scope,int $id,array $params,string $action): array
    {
        if($scope!==0)throw new InvalidArgumentException('内置模板由平台统一维护');$lock=SubmitLockService::acquire($app.'_builtin_source');
        try{$entry=self::definition($app,$id);if(!$entry)throw new InvalidArgumentException('内置模板定义不存在');
            if((int)($params['builtin_version']??0)!==$entry['version'])throw new InvalidArgumentException('内置模板已更新，请刷新后重试');
            if($action==='save'){
                if(isset($params['configuration'])&&Policy::hash((array)$params['configuration'])!==Policy::hash(self::bodyFor($app,0,$entry,false)))throw new InvalidArgumentException('内置模板的素材与创作配置请通过原模板更新，当前可维护名称、状态和排序');
                foreach(['title','status','sort'] as $key)if(array_key_exists($key,$params))$entry[$key]=$params[$key];
            }elseif($action==='status')$entry['status']=empty($params['status'])?0:1;
            elseif($action==='delete'){$entry['deleted']=true;$entry['status']=0;}else throw new InvalidArgumentException('内置模板操作无效');
            $entry['version']++;StudioBuiltinCatalog::write($app,$entry);self::sync($app);$service=self::service($app);return $action==='delete'?[]:$service::detail(0,$id);
        }finally{SubmitLockService::release($lock);}
    }
}
