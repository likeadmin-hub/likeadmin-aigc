<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\aigc_micro_video\{MicroVideoMedia,MicroVideoCatalog,MicroVideoFrameCatalog};
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\storage\StorageSignedUrlService;
use app\common\service\FileService;
use InvalidArgumentException;
use think\facade\Db;

final class ProductVideoService
{
    public static function table(string $suffix){return Db::name(ProductVideoPolicy::APP.'_'.$suffix);}
    public static function available(int $tenant,int $user=0): void
    {
        if(!AppAccessService::tenantCanUse($tenant,ProductVideoPolicy::APP)||!(int)(self::settings($tenant)['status']))throw new InvalidArgumentException('商品视频应用当前不可用');
        if($user>0&&!\app\common\service\membership\MembershipService::userCanUseApp($tenant,$user,ProductVideoPolicy::APP))throw new InvalidArgumentException('当前套餐尚未开放商品视频，请先开通应用');
    }
    public static function settings(int $tenant): array
    {
        $global=self::table('config')->where('tenant_id',0)->find();$r=$tenant>0?self::table('config')->where('tenant_id',$tenant)->find():null;
        return array_replace(['status'=>1,'analysis_model_id'=>'','default_video_model_id'=>'','default_image_model_id'=>''],ProductVideoPolicy::json($global['body_json']??'{}'),ProductVideoPolicy::json($r['body_json']??'{}'));
    }
    public static function setup(int $tenant,array $p): array
    {
        $data=['status'=>empty($p['status'])?0:1];
        foreach(['analysis_model_id','default_video_model_id','default_image_model_id'] as $key)$data[$key]=ProductVideoPolicy::text($p[$key]??'',100);
        $values=['body_json'=>ProductVideoPolicy::encode($data),'update_time'=>time()];$q=self::table('config')->where('tenant_id',$tenant);
        if($q->find())$q->update($values);else self::table('config')->insert($values+['tenant_id'=>$tenant,'create_time'=>time()]);
        return $data;
    }
    public static function config(int $tenant): array
    {
        return AppDisplayConfigService::appendToConfig($tenant,ProductVideoPolicy::APP,self::settings($tenant)+[
            'templates'=>array_map(static function($t){unset($t['instruction']);return $t;},ProductVideoPrompts::templates()),
            'max_images'=>15,'reference_max_seconds'=>60,'reference_max_mb'=>32,
            'analysis_models'=>MarketTextModelRuntimeService::mediaOptions($tenant,true),
        ]);
    }
    public static function models(int $tenant,string $kind,string $id=''): array
    {
        if($kind==='image')return ['models'=>MicroVideoFrameCatalog::models($tenant,1)];
        if($kind==='analysis')return ['models'=>MarketTextModelRuntimeService::mediaOptions($tenant,true)];
        if($kind!=='video')throw new InvalidArgumentException('模型类型无效');
        return ['models'=>MicroVideoCatalog::models($tenant,false,'reference',$id!==''?[$id]:null,$id==='')];
    }
    public static function owned(int $tenant,int $user,int $id,bool $lock=false): array
    {
        $q=self::table('project')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$id,'delete_time'=>0]);if($lock)$q->lock(true);$r=$q->find();
        if(!$r)throw new InvalidArgumentException('创作草稿不存在或无权访问');return $r;
    }
    public static function projectDto(array $row): array
    {return ['id'=>(int)$row['id'],'title'=>$row['title'],'revision'=>(int)$row['revision'],'body'=>self::publicBody(ProductVideoPolicy::json($row['body_json']), (int)$row['tenant_id']),'update_time'=>(int)$row['update_time']];}
    public static function publicBody(array $body,int $tenant): array
    {
        $walk=static function($v)use(&$walk,$tenant){if(!is_array($v))return $v;
            $uri=$v['uri']??$v['video_uri']??$v['image_uri']??'';
            if(is_string($uri)&&$uri!==''&&isset($v['storage_engine'])){
                $url=StorageSignedUrlService::resolve($uri,(string)($v['storage_scope']??'tenant'),(string)$v['storage_engine'],(string)($v['storage_domain']??''),$tenant);
                $v['url']=$url?:FileService::format((string)($v['storage_domain']??''),$uri);
            }
            foreach(['tenant_id','user_id','file_id'] as $key)unset($v[$key]);
            foreach($v as $k=>$x)if(is_array($x))$v[$k]=$walk($x);return $v;};
        return $walk($body);
    }
    public static function save(int $tenant,int $user,array $p): array
    {
        self::available($tenant,$user);$body=ProductVideoPolicy::body((array)($p['body']??[]));
        $body['images']=MicroVideoMedia::validateImages($tenant,$user,$body['images'],15);
        if($body['reference_video'])$body['reference_video']=MicroVideoMedia::validateVideo($tenant,$user,(string)($body['reference_video']['uri']??''));
        return Db::transaction(static function()use($tenant,$user,$p,$body){
            $id=(int)($p['id']??0);$title=ProductVideoPolicy::text($p['title']??'商品视频',100);
            if($id){$old=self::owned($tenant,$user,$id,true);if((int)($p['revision']??0)!==(int)$old['revision'])throw new InvalidArgumentException('草稿已更新，请刷新后继续');$revision=(int)$old['revision']+1;self::table('project')->where('id',$id)->update(['body_json'=>ProductVideoPolicy::encode($body),'title'=>$title,'revision'=>$revision,'update_time'=>time()]);}
            else $id=self::table('project')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>$title,'revision'=>1,'body_json'=>ProductVideoPolicy::encode($body),'create_time'=>time(),'update_time'=>time()]);
            return self::projectDto(self::owned($tenant,$user,$id));
        });
    }
    public static function adopt(int $tenant,int $user,array $p): array
    {
        return Db::transaction(static function()use($tenant,$user,$p){
            $job=ProductVideoJobs::owned($tenant,$user,(int)($p['job_id']??0));$project=self::owned($tenant,$user,(int)$job['project_id'],true);
            if($job['status']!=='success')throw new InvalidArgumentException('任务完成后才能使用结果');
            if((int)$project['revision']!==(int)($p['revision']??0)||(int)$job['project_revision']!==(int)$project['revision'])throw new InvalidArgumentException('草稿已修改，结果已保留，请查看后手动合并');
            $body=ProductVideoPolicy::json($project['body_json']);$result=ProductVideoPolicy::json($job['result_json']);
            if($job['action']==='product')$body['product_info']=$result['product_info'];
            elseif($job['action']==='reference')$body['reference_analysis']=['data'=>$result,'video_uri'=>$body['reference_video']['uri']??''];
            elseif($job['action']==='plan')$body['plan']=['data'=>$result,'input_key'=>ProductVideoPolicy::planKey($body),'job_id'=>(int)$job['id']];
            elseif($job['action']==='storyboard')$body['storyboard']=['images'=>$result['images'],'input_key'=>ProductVideoPolicy::hash((array)$body['plan']),'job_id'=>(int)$job['id']];
            else throw new InvalidArgumentException('该结果可在历史创作中查看');
            self::table('project')->where('id',$project['id'])->update(['revision'=>(int)$project['revision']+1,'body_json'=>ProductVideoPolicy::encode($body),'update_time'=>time()]);
            return self::projectDto(self::owned($tenant,$user,(int)$project['id']));
        });
    }
}
