<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_motion_sync;

use app\common\service\app\{AppAccessService,AppDisplayConfigService};
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use app\common\service\power\{MarketMotionSyncRuntimeService as Runtime,MarketGenerationMedia,MarketGenerationSchema};
use app\common\service\storage\StorageSignedUrlService;
use app\common\service\FileService;
use InvalidArgumentException;
use think\facade\Db;

final class MotionSyncService
{
    public const APP = 'aigc_motion_sync';
    public static function table(string $name) { return Db::name(self::APP.'_'.$name); }
    public static function json($v): array { return MarketGenerationSchema::array($v); }
    public static function encode(array $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    public static function text($v,int $max): string
    {
        if(!is_string($v) || mb_strlen($v)>$max) throw new InvalidArgumentException('输入文字格式或长度不正确');
        return trim($v);
    }
    public static function available(int $tenant,int $user=0): void
    {
        if(!AppAccessService::tenantCanUse($tenant,self::APP)||!self::settings($tenant)['status'])throw new InvalidArgumentException('动作同步应用当前不可用');
        if($user>0&&!\app\common\service\membership\MembershipService::userCanUseApp($tenant,$user,self::APP))throw new InvalidArgumentException('当前套餐尚未开放动作同步，请先开通应用');
    }
    public static function settings(int $tenant): array
    {
        $global=self::json(self::table('config')->where('tenant_id',0)->value('body_json')??[]);
        $local=$tenant>0?self::json(self::table('config')->where('tenant_id',$tenant)->value('body_json')??[]):[];
        return array_intersect_key(array_replace(['status'=>1,'default_model_key'=>''],$global,$local),array_flip(['status','default_model_key']));
    }
    public static function config(int $tenant): array
    { return AppDisplayConfigService::appendToConfig($tenant,self::APP,self::settings($tenant)+['models'=>Runtime::options($tenant)]); }
    public static function setup(int $tenant,array $p): array
    {
        $data=['status'=>empty($p['status'])?0:1,'default_model_key'=>self::text($p['default_model_key']??'',100)];
        if($data['default_model_key']!==''&&!in_array($data['default_model_key'],array_column(Runtime::options($tenant),'model_key'),true))throw new InvalidArgumentException('默认动作模型不存在，请先同步算力超市');
        return Db::transaction(static function()use($tenant,$p,$data){
        AppDisplayConfigService::saveFromConfigPayload($tenant,self::APP,$p);
        $q=self::table('config')->where('tenant_id',$tenant);$v=['body_json'=>self::encode($data),'update_time'=>time()];
        if($q->find())$q->update($v);else self::table('config')->insert($v+['tenant_id'=>$tenant,'create_time'=>time()]);
        return $data;
        });
    }
    public static function body(array $p): array
    {
        $orientation=self::text($p['orientation']??'video',10);
        if(!in_array($orientation,['image','video'],true))throw new InvalidArgumentException('请选择有效的人物朝向');
        foreach(['image','video'] as $key)if(isset($p[$key])&&!is_array($p[$key]))throw new InvalidArgumentException('素材格式无效');
        return ['prompt'=>self::text($p['prompt']??'',2500),'image'=>$p['image']??null,'video'=>$p['video']??null,
            'model_key'=>self::text($p['model_key']??'',100),'quality'=>self::text($p['quality']??'',30),'orientation'=>$orientation];
    }
    public static function validateMedia(int $tenant,int $user,array $body): array
    {
        try { return MotionSyncMedia::validate($tenant,$user,$body); }
        catch(InvalidArgumentException $e){throw $e;}
        catch(\Throwable $e){\think\facade\Log::warning('Motion sync material: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());throw new InvalidArgumentException('素材暂时无法读取，请重新选择或联系管理员',0,$e);}
    }
    public static function model(int $tenant,array $body): array
    {
        foreach(Runtime::options($tenant) as $model)if($model['model_key']===$body['model_key']){
            if(empty($model['available']))throw new InvalidArgumentException('所选动作模型暂无上架规格，请联系管理员');
            foreach($model['specs'] as $spec)if($spec['quality']===$body['quality'])return [$model,$spec];
            throw new InvalidArgumentException('当前清晰度不可用，请重新选择');
        }
        throw new InvalidArgumentException('请选择已同步的动作模型，不会自动替换其他模型');
    }
    public static function request(int $tenant,array $body): array
    {
        MotionSyncMedia::assertFacts($body);
        if(!$body['image']||!$body['video'])throw new InvalidArgumentException('请添加一张角色图片和一个动作视频');
        $seconds=(float)($body['video']['duration']??0);$max=$body['orientation']==='image'?10:30;
        if(!is_finite($seconds)||$seconds<3||$seconds>$max+0.1)throw new InvalidArgumentException('当前朝向的动作视频须为 3～'.$max.' 秒');
        [$model,$spec]=self::model($tenant,$body);$mode=$model['material_modes'][0]??null;
        if(!$mode)throw new InvalidArgumentException('模型素材参数尚未同步，请联系管理员');
        $assets=[];foreach(['image','video'] as $type)$assets[]=array_replace($body[$type],['type'=>$type,'role'=>'reference_'.$type]);
        $selection=['model_id'=>$model['id'],'market_sku_id'=>(int)$spec['market_sku_id'],'resource_type'=>'model','duration'=>0,
            'material_mode'=>$mode['key'],'generation_method'=>$mode['method'],'provider_params'=>['character_orientation'=>$body['orientation']]];
        return $selection+['prompt'=>$body['prompt'],'reference_assets'=>$assets];
    }
    public static function measured(array $body,callable $operation): mixed
    { return MarketGenerationMedia::withVerifiedVideo((string)$body['video']['url'],(float)$body['video']['duration'],$operation); }
    public static function publicBody(array $body,int $tenant): array
    {
        $walk=static function($v)use(&$walk,$tenant){if(!is_array($v))return $v;
            $uri=$v['uri']??$v['video_uri']??'';
            if(is_string($uri)&&$uri!==''&&isset($v['storage_engine'])){
                $v['url']=StorageSignedUrlService::resolve($uri,(string)($v['storage_scope']??'tenant'),(string)$v['storage_engine'],(string)($v['storage_domain']??''),$tenant)
                    ?:FileService::format((string)($v['storage_domain']??''),$uri);
                if(isset($v['video_uri']))$v['video_url']=$v['url'];
            }
            unset($v['tenant_id'],$v['user_id'],$v['file_id']);
            foreach($v as $k=>$x)if(is_array($x))$v[$k]=$walk($x);return $v;
        };return $walk($body);
    }
    public static function owned(int $tenant,int $user,int $id,bool $lock=false): array
    {
        $q=self::table('project')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$id,'delete_time'=>0]);if($lock)$q->lock(true);
        $r=$q->find();if(!$r)throw new InvalidArgumentException('创作草稿不存在或无权访问');return $r;
    }
    public static function projectDto(array $r): array
    { return ['id'=>(int)$r['id'],'revision'=>(int)$r['revision'],'body'=>self::publicBody(self::json($r['body_json']),(int)$r['tenant_id'])]; }
    public static function save(int $tenant,int $user,array $p): array
    {
        self::available($tenant,$user);$body=self::validateMedia($tenant,$user,self::body((array)($p['body']??[])));
        return Db::transaction(static function()use($tenant,$user,$p,$body){
            $id=(int)($p['id']??0);$v=['body_json'=>self::encode($body),'update_time'=>time()];
            if($id){$old=self::owned($tenant,$user,$id,true);if((int)($p['revision']??0)!==(int)$old['revision'])throw new InvalidArgumentException('草稿已更新，请刷新后继续');self::table('project')->where('id',$id)->update($v+['revision'=>(int)$old['revision']+1]);}
            else $id=self::table('project')->insertGetId($v+['tenant_id'=>$tenant,'user_id'=>$user,'revision'=>1,'create_time'=>time()]);
            return self::projectDto(self::owned($tenant,$user,$id));
        });
    }
    public static function fromJob(int $tenant,int $user,int $id): array
    {
        $job=MotionSyncJobs::owned($tenant,$user,$id);
        if(!in_array($job['status'],['success','failed','canceled'],true))throw new InvalidArgumentException('任务状态待确认，暂不能再次编辑生成');
        return self::save($tenant,$user,['body'=>self::json($job['request_json'])['body']??[]]);
    }
}
