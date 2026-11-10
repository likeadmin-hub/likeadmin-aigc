<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;

use app\common\model\app\AppCase;
use app\common\service\app\AppCaseService;
use app\common\service\storage\StorageConfigService;
use app\common\service\SubmitLockService;
use InvalidArgumentException;
use think\facade\Db;
use think\facade\Cache;

/** One platform case per source definition; tenants receive references, never media copies. */
final class ProductVideoBuiltins
{
    private static bool $writing=false;
    public static function table(){return Db::name('aigc_product_video_builtin');}
    public static function writing(): bool{return self::$writing;}
    private static function internal(callable $call): mixed
    {$old=self::$writing;self::$writing=true;try{return $call();}finally{self::$writing=$old;}}
    public static function info(int $caseId): ?array
    {return self::table()->where('case_id',$caseId)->find()?:null;}
    public static function ids(): array {return array_map('intval',self::table()->whereNotNull('case_id')->column('case_id'));}
    public static function sync(bool $force=false): void
    {
        if(self::$writing)return;$all=ProductVideoBuiltinCatalog::all();if(!$all)return;
        $key='product_video_builtin:'.hash('sha256',ProductVideoBuiltinCatalog::directory().'|'.config('database.connections.mysql.prefix'));$hash=ProductVideoPolicy::hash($all);
        if(!$force&&Cache::get($key)===$hash)return;
        self::internal(static function()use($all){foreach($all as $entry)Db::transaction(static function()use($entry){
            self::table()->extra('IGNORE')->insert(['builtin_key'=>$entry['key'],'tenant_id'=>0,'version'=>0,'source_hash'=>'','create_time'=>time(),'update_time'=>time()]);
            $link=self::table()->where('builtin_key',$entry['key'])->lock(true)->find();$hash=ProductVideoPolicy::hash($entry);
            if($link['source_hash']===$hash&&$link['case_id']&&AppCase::where('id',$link['case_id'])->count())return;
            $body=self::bodyFor(0,$entry,false);$media=$entry['media'];$selection=$body['video_selection'];
            $request=['model_id'=>$selection['model_id']??'','prompt'=>$body['prompt'],'quality'=>$selection['quality'],'ratio'=>$selection['ratio'],'duration'=>$selection['duration'],
                'reference_images'=>array_column($body['images'],'uri'),'reference_assets'=>$body['images'],'advanced_params'=>['product_video'=>$body,'product_video_media'=>$media]];
            if($link['case_id'])AppCase::where(['id'=>$link['case_id'],'tenant_id'=>0,'app_code'=>ProductVideoPolicy::APP])->update(['delete_time'=>0]);
            $saved=AppCaseService::save(0,ProductVideoPolicy::APP,['id'=>$link['case_id']??0,'title'=>$entry['title'],'prompt'=>$body['prompt'],'media_type'=>'video','cover_uri'=>$body['images'][0]['uri'],
                'media_uri'=>$media['uri'],'reference_images'=>array_column($body['images'],'uri'),'config_json'=>['model_name'=>$entry['model_name'],'generation_request'=>$request],
                'status'=>$entry['deleted']?0:$entry['status'],'sort'=>$entry['sort'],'source_task_id'=>0,'source_result_id'=>0]);
            self::table()->where('builtin_key',$entry['key'])->update(['case_id'=>(int)$saved['id'],'version'=>$entry['version'],'source_hash'=>$hash,'update_time'=>time()]);
            if($entry['deleted'])AppCase::where('id',$saved['id'])->update(['delete_time'=>time()]);
        });});Cache::set($key,$hash,60);
    }
    private static function modelId(array $identity): string
    {
        $p=Db::name('power_market_product')->where(['source_code'=>$identity['source_code'],'upstream_resource_key'=>$identity['upstream_resource_key'],'model_type'=>'video'])->find();
        if(!$p)throw new InvalidArgumentException('内置模板的视频模型尚未同步，请先在算力市场同步并上架');
        return $p['resource_type']==='model'?'market_video_model:'.$p['id']:'market_video_app:'.$p['upstream_app_code'].':'.$p['id'];
    }
    public static function bodyFor(int $tenant,array $entry,bool $strict=true): array
    {
        $body=ProductVideoTemplates::snapshot($entry['configuration']);$fresh=!empty($body['plan'])&&($body['plan']['input_key']??'')===ProductVideoPolicy::planKey($body);
        try{$body['video_selection']['model_id']=self::modelId($entry['model_identity']);if($strict){$m=ProductVideoCatalog::model($tenant,$body);ProductVideoCatalog::spec($m,$body);}}
        catch(InvalidArgumentException $e){if($strict)throw $e;$body['video_selection']['model_id']='';}
        if($fresh)$body['plan']['input_key']=ProductVideoPolicy::planKey($body);return $body;
    }
    public static function definition(int $caseId): ?array
    {$link=self::info($caseId);return $link?(ProductVideoBuiltinCatalog::all()[$link['builtin_key']]??null):null;}
    private static function shared(array $asset,int $sourceTenant): array
    {
        $engine=(string)($asset['storage_engine']??'');
        if(($asset['storage_scope']??'')==='platform'&&ProductVideoBuiltinCatalog::mediaUrl((string)($asset['uri']??$asset['video_uri']??''),$engine,(string)($asset['storage_domain']??'')))return ProductVideoBuiltinCatalog::publicAsset($asset);
        $config=StorageConfigService::getStoredFileConfig($sourceTenant,(string)($asset['storage_scope']??''),$engine);$settings=$config['engine'][$engine]??[];
        if(!isset($config['engine'][$engine])||rtrim((string)($settings['domain']??''),'/')!==rtrim((string)($asset['storage_domain']??''),'/')||!empty($settings['private_access'])||!empty($settings['private']))throw new InvalidArgumentException('内置模板需要公开 OSS 素材，私有素材不能直接共享；请先准备公开素材');
        return ProductVideoBuiltinCatalog::publicAsset($asset);
    }
    private static function identity(string $id): array
    {
        if(!preg_match('/:(\d+)$/D',$id,$match))throw new InvalidArgumentException('请选择算力市场的视频模型');
        $p=Db::name('power_market_product')->where(['id'=>(int)$match[1],'model_type'=>'video'])->find();
        if(!$p)throw new InvalidArgumentException('视频模型尚未同步到算力市场');
        return array_intersect_key($p,array_flip(['source_code','upstream_resource_key','resource_type','upstream_app_code']));
    }
    public static function publish(int $sourceId,array $params): array
    {
        if(empty($params['confirm_public_media']))throw new InvalidArgumentException('请确认将商品素材作为平台共享素材');
        $lock=SubmitLockService::acquire('product_video_builtin_source');
        try{return Db::transaction(static function()use($sourceId,$params){
            $source=AppCase::where(['id'=>$sourceId,'app_code'=>ProductVideoPolicy::APP,'delete_time'=>0])->lock(true)->find();
            if(!$source||(int)$source['tenant_id']<=0)throw new InvalidArgumentException('请选择租户已有的商品视频模板');
            $source=$source->toArray();$existing=self::table()->where('source_case_id',$sourceId)->find();$all=ProductVideoBuiltinCatalog::all();$prior=$existing?($all[$existing['builtin_key']]??null):null;
            if((int)($params['expected_version']??0)!==(int)($prior['version']??0))throw new InvalidArgumentException('内置模板已更新，请刷新后重试');
            $extra=$source['config_json']['generation_request']['advanced_params'];$body=ProductVideoTemplates::snapshot($extra['product_video']);
            $body['images']=\app\common\service\app\aigc_micro_video\MicroVideoMedia::validateImages((int)$source['tenant_id'],self::sourceUser($source),$body['images'],15);
            $model=ProductVideoCatalog::model((int)$source['tenant_id'],$body);ProductVideoCatalog::spec($model,$body);
            $fresh=!empty($body['plan'])&&($body['plan']['input_key']??'')===ProductVideoPolicy::planKey($body);$identity=self::identity((string)$body['video_selection']['model_id']);
            $body['images']=array_map(static fn($a)=>self::shared($a,(int)$source['tenant_id']),$body['images']);$body=ProductVideoTemplates::snapshot($body);$body['video_selection']['model_id']='';
            if($fresh)$body['plan']['input_key']=ProductVideoPolicy::planKey($body);
            $entry=ProductVideoBuiltinCatalog::write(['schema_version'=>1,'key'=>$prior['key']??'builtin-'.bin2hex(random_bytes(12)),'version'=>(int)($prior['version']??0)+1,
                'title'=>$source['title'],'status'=>(int)$source['status'],'sort'=>(int)$source['sort'],'deleted'=>false,'model_name'=>$model['label'],'model_identity'=>$identity,'configuration'=>$body,
                'media'=>self::shared($extra['product_video_media'],(int)$source['tenant_id'])]);
            self::sync(true);$link=self::table()->where('builtin_key',$entry['key'])->find();self::table()->where('builtin_key',$entry['key'])->update(['source_case_id'=>$sourceId,'source_tenant_id'=>(int)$source['tenant_id']]);
            return ProductVideoTemplates::detail(0,(int)$link['case_id']);
        });}finally{SubmitLockService::release($lock);}
    }
    private static function sourceUser(array $case): int
    {
        $job=ProductVideoJobs::owned((int)$case['tenant_id'],0,(int)$case['source_task_id'],true);return (int)$job['user_id'];
    }
    public static function change(int $scope,int $id,array $params,string $action='save'): array
    {
        if($scope!==0)throw new InvalidArgumentException('平台内置模板由平台统一维护，租户只能查看和使用');
        $lock=SubmitLockService::acquire('product_video_builtin_source');
        try{return Db::transaction(static function()use($id,$params,$action){
            $entry=self::definition($id);if(!$entry)throw new InvalidArgumentException('内置模板定义不存在');
            if((int)($params['builtin_version']??0)!==$entry['version'])throw new InvalidArgumentException('内置模板已更新，请刷新后重试');
            if($action==='save'){
                $saved=self::internal(static fn()=>ProductVideoTemplates::saveBuiltinRow($id,$params));$body=$saved['configuration'];
                $fresh=!empty($body['plan'])&&($body['plan']['input_key']??'')===ProductVideoPolicy::planKey($body);$entry['model_identity']=self::identity((string)$body['video_selection']['model_id']);$body['video_selection']['model_id']='';
                if($fresh)$body['plan']['input_key']=ProductVideoPolicy::planKey($body);$entry['configuration']=$body;
                foreach(['title','status','sort'] as $field)$entry[$field]=$saved[$field];$entry['model_name']=$saved['model_name'];
            }elseif($action==='status')$entry['status']=empty($params['status'])?0:1;
            elseif($action==='delete'){$entry['deleted']=true;$entry['status']=0;}else throw new InvalidArgumentException('内置模板操作无效');
            $entry['version']++;ProductVideoBuiltinCatalog::write($entry);self::sync(true);return $action==='delete'?[]:ProductVideoTemplates::detail(0,$id);
        });}finally{SubmitLockService::release($lock);}
    }
}
