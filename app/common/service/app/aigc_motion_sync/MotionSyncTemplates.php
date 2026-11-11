<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_motion_sync;
use app\common\model\app\AppCase;
use app\common\service\app\AppCaseService;
use app\common\service\ai\StudioMediaService as Media;
use InvalidArgumentException;
use think\facade\Db;
use app\common\service\app\StudioBuiltinTemplates as Builtins;

final class MotionSyncTemplates
{
    private static function row(int $scope,int $id,bool $public=false): array
    {
        Builtins::sync(MotionSyncService::APP);
        $q=AppCase::where(['id'=>$id,'app_code'=>MotionSyncService::APP,'delete_time'=>0]);
        if($public)$q->where('status',1)->whereIn('tenant_id',[0,$scope]);elseif($scope>0)$q->where(static fn($a)=>$a->where('tenant_id',$scope)->whereOr(static fn($b)=>$b->where(['tenant_id'=>0,'status'=>1])));
        $row=$q->find();if(!$row)throw new InvalidArgumentException('模板不存在或无权访问');return $row->toArray();
    }
    private static function storedBody(array $r): array{return (array)($r['config_json']['generation_request']['advanced_params']['motion_sync']??[]);}
    public static function dto(array $r,bool $public=false,int $viewer=0): array
    {
        $body=self::storedBody($r);$preview=(array)($r['config_json']['generation_request']['advanced_params']['motion_sync_media']??[]);
        $view=MotionSyncService::publicBody(['configuration'=>$body,'preview'=>$preview],(int)$r['tenant_id']);
        $image=$view['configuration']['image']??[];$video=$view['preview'];
        $w=(int)($video['width']??0);$h=(int)($video['height']??0);
        $dto=['id'=>(int)$r['id'],'title'=>$r['title'],'configuration'=>$view['configuration'],'preview'=>$video,
            'media_type'=>'video','media_url'=>$video['url']??'','cover_url'=>$image['url']??'','width'=>$w,'height'=>$h,'ratio'=>$w>0&&$h>0?$w.':'.$h:'9:16','is_builtin'=>(bool)($builtin=Builtins::info(MotionSyncService::APP,(int)$r['id'])),'builtin_version'=>(int)($builtin['version']??0)];
        if(!$public&&!$builtin&&($viewer===0||$viewer===(int)$r['tenant_id']))$dto['published_builtin_version']=(int)(Builtins::table()->where(['app_code'=>MotionSyncService::APP,'source_case_id'=>$r['id']])->value('version')?:0);
        if(!$public)$dto+=['tenant_id'=>(int)$r['tenant_id'],'status'=>(int)$r['status'],'sort'=>(int)$r['sort'],'source_task_id'=>(int)$r['source_task_id'],'create_time'=>(int)$r['create_time'],'readonly'=>$viewer>0&&(int)$r['tenant_id']!==$viewer];return $dto;
    }
    public static function lists(int $scope,array $p,bool $public=false): array
    {
        Builtins::sync(MotionSyncService::APP);
        if($public)MotionSyncService::available($scope);
        $q=AppCase::where(['app_code'=>MotionSyncService::APP,'delete_time'=>0]);if($public)$q->where('status',1)->whereIn('tenant_id',[0,$scope]);elseif($scope>0)$q->where(static fn($a)=>$a->where('tenant_id',$scope)->whereOr(static fn($b)=>$b->where(['tenant_id'=>0,'status'=>1])));
        if(!$public&&$scope===0&&isset($p['tenant_id'])&&$p['tenant_id']!=='')$q->where('tenant_id',(int)$p['tenant_id']);
        if(!$public&&isset($p['status'])&&$p['status']!=='')$q->where('status',(int)$p['status']);
        if(($p['keyword']??'')!=='')$q->whereLike('title','%'.addcslashes(MotionSyncService::text($p['keyword'],100),'\\%_').'%');
        $count=(clone $q)->count();$page=max(1,(int)($p['page_no']??1));$size=max(1,min(30,(int)($p['page_size']??20)));
        return ['lists'=>array_map(static fn($r)=>self::dto($r,$public,$scope),$q->order(['sort'=>'desc','id'=>'desc'])->page($page,$size)->select()->toArray()),'count'=>$count];
    }
    public static function galleryRow(array $row): array
    {
        $view=self::dto($row,true);$row['cover_url']=$view['cover_url'];$row['media_url']=$view['media_url'];
        $row['config_json']=MotionSyncService::publicBody((array)$row['config_json'],(int)$row['tenant_id']);
        return $row;
    }
    public static function detail(int $scope,int $id,bool $public=false): array
    {if($public)MotionSyncService::available($scope);return self::dto(self::row($scope,$id,$public),$public,$scope);}
    private static function writable(int $scope,array $row): void
    {if($scope>0&&(int)$row['tenant_id']!==$scope)throw new InvalidArgumentException('平台共享模板仅支持查看和使用，请创建自己的模板');}
    public static function save(int $scope,array $p): array
    {
        if(!empty($p['id'])&&Builtins::info(MotionSyncService::APP,(int)$p['id']))return Builtins::change(MotionSyncService::APP,$scope,(int)$p['id'],$p,'save');
        $old=!empty($p['id'])?self::row($scope,(int)$p['id']):null;$owner=$old?(int)$old['tenant_id']:$scope;
        if($old)self::writable($scope,$old);
        $source=$old?self::storedBody($old):[];$body=MotionSyncService::body((array)($p['configuration']??$source));
        foreach(['image'=>10,'video'=>20] as $key=>$type){
            $input=(array)($body[$key]??[]);$previous=(array)($source[$key]??[]);
            $same=$previous&&(string)($previous['uri']??'')===(string)($input['uri']??'')&&empty($input['file_id']);
            $body[$key]=$same?Media::verify($owner,$previous,$type):Media::adminAsset($owner,$input,$type);
            $body[$key]=array_replace($body[$key],Media::facts($owner,$body[$key],$type));
        }
        $prev=(array)($old['config_json']['generation_request']['advanced_params']['motion_sync_media']??[]);$preview=!empty($p['preview']['file_id'])?Media::adminAsset($owner,(array)$p['preview'],20):$prev;
        if(!$preview)throw new InvalidArgumentException('请添加预览作品');$preview=array_replace($preview,Media::facts($owner,$preview,20));
        MotionSyncService::request($owner,$body);
        return self::persist($owner,$old,$body,$preview,(int)($old['source_task_id']??0),$p);
    }
    private static function persist(int $owner,?array $old,array $body,array $preview,int $sourceId,array $p): array
    {
        $title=MotionSyncService::text($p['title']??$old['title']??'动作同步灵感',100);if($title==='')throw new InvalidArgumentException('请填写模板名称');
        $saved=AppCaseService::save($owner,MotionSyncService::APP,['id'=>$old['id']??0,'title'=>$title,'prompt'=>$body['prompt'],'media_type'=>'video',
            'cover_uri'=>$body['image']['uri'],'media_uri'=>$preview['uri'],'reference_images'=>[$body['image']['uri']],
            'config_json'=>['generation_request'=>['prompt'=>$body['prompt'],'advanced_params'=>['motion_sync'=>$body,'motion_sync_media'=>$preview]]],'source_task_id'=>$sourceId,'source_result_id'=>0,
            'status'=>(int)($p['status']??$old['status']??1)===1?1:0,'sort'=>max(0,min(9999,(int)($p['sort']??$old['sort']??0)))]);
        return self::detail($owner,(int)$saved['id']);
    }
    public static function fromJob(int $scope,int $id,array $p): array
    {
        $job=MotionSyncJobs::owned($scope,0,$id,true);if($job['status']!=='success')throw new InvalidArgumentException('只能将已完成作品设为模板');
        $body=MotionSyncService::validateMedia((int)$job['tenant_id'],(int)$job['user_id'],MotionSyncService::body(MotionSyncService::json($job['request_json'])['body']??[]));
        MotionSyncService::request((int)$job['tenant_id'],$body);
        $result=MotionSyncService::table('result')->where(['task_id'=>$id,'delete_time'=>0])->order('id','asc')->find();if(!$result)throw new InvalidArgumentException('作品已移除');
        $preview=array_intersect_key($result,array_flip(['duration','width','height','storage_scope','storage_engine','storage_domain']));$preview['uri']=$result['video_uri'];
        $file=Db::name('tenant_file')->where(['tenant_id'=>$job['tenant_id'],'source'=>1,'source_id'=>$job['user_id'],'uri'=>$preview['uri'],'type'=>20])->whereRaw('(delete_time IS NULL OR delete_time=0)')->find();
        if(!$file)throw new InvalidArgumentException('作品尚未完整存储，请稍后重试');$preview+=['file_id'=>(int)$file['id'],'tenant_id'=>(int)$job['tenant_id'],'user_id'=>(int)$job['user_id']];
        return self::persist((int)$job['tenant_id'],null,$body,$preview,$id,$p);
    }
    public static function status(int $scope,int $id,int $value,array $params=[]): void
    {if(Builtins::info(MotionSyncService::APP,$id)){Builtins::change(MotionSyncService::APP,$scope,$id,$params+['status'=>$value],'status');return;}$r=self::row($scope,$id);self::writable($scope,$r);AppCaseService::setStatus((int)$r['tenant_id'],MotionSyncService::APP,$id,$value===1?1:0);}
    public static function delete(int $scope,int $id,array $params=[]): void
    {if(Builtins::info(MotionSyncService::APP,$id)){Builtins::change(MotionSyncService::APP,$scope,$id,$params,'delete');return;}$r=self::row($scope,$id);self::writable($scope,$r);AppCaseService::delete((int)$r['tenant_id'],MotionSyncService::APP,$id);}
    public static function remix(int $tenant,int $user,int $id): array
    {
        MotionSyncService::available($tenant,$user);
        return Db::transaction(static function()use($tenant,$user,$id){$r=self::row($tenant,$id,true);$entry=Builtins::definition(MotionSyncService::APP,$id);$body=$entry?Builtins::bodyFor(MotionSyncService::APP,$tenant,$entry):MotionSyncService::body(self::storedBody($r));MotionSyncService::request($tenant,$body);
            foreach(['image'=>10,'video'=>20] as $key=>$type)$body[$key]=Media::grant($tenant,$user,(int)$r['tenant_id'],$body[$key],$type);
            return MotionSyncService::save($tenant,$user,['body'=>$body]);
        });
    }
}
