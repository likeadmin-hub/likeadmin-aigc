<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_story_commerce;
use app\common\model\app\AppCase;
use app\common\service\app\AppCaseService;
use app\common\service\ai\StudioMediaService as Media;
use InvalidArgumentException;
use think\facade\Db;
use app\common\service\SubmitLockService;
final class StoryCommerceTemplates
{
    private static function row(int $scope,int $id,bool $public=false): array {$q=AppCase::where(['id'=>$id,'app_code'=>StoryCommercePolicy::APP,'delete_time'=>0]);if($public)$q->where('status',1)->whereIn('tenant_id',[0,$scope]);elseif($scope>0)$q->whereIn('tenant_id',[0,$scope]);$r=$q->find();if(!$r)throw new InvalidArgumentException('模板不存在或无权访问');return $r->toArray();}
    private static function stored(array $r): array {return (array)($r['config_json']['generation_request']['advanced_params']['story_commerce']??[]);}
    public static function dto(array $r,bool $public=false,int $viewer=0): array {$b=self::stored($r);$media=(array)($r['config_json']['generation_request']['advanced_params']['story_commerce_media']??[]);$v=StoryCommerceService::publicBody(['configuration'=>$b,'preview'=>$media],(int)$r['tenant_id']);$out=['id'=>(int)$r['id'],'title'=>$r['title'],'configuration'=>$v['configuration'],'preview'=>$v['preview'],'media_url'=>$v['preview']['url']??'','cover_url'=>$v['configuration']['images'][0]['url']??'','media_type'=>'video','ratio'=>$b['video_selection']['ratio']??'9:16','is_builtin'=>false];if(!$public)$out+=['tenant_id'=>(int)$r['tenant_id'],'status'=>(int)$r['status'],'sort'=>(int)$r['sort'],'source_task_id'=>(int)$r['source_task_id'],'readonly'=>$viewer>0&&(int)$r['tenant_id']!==$viewer];return $out;}
    public static function lists(int $scope,array $p,bool $public=false): array {if($public)StoryCommerceService::available($scope);$q=AppCase::where(['app_code'=>StoryCommercePolicy::APP,'delete_time'=>0]);if($public)$q->where('status',1)->whereIn('tenant_id',[0,$scope]);elseif($scope>0)$q->whereIn('tenant_id',[0,$scope]);if(!$public&&$scope===0&&isset($p['tenant_id'])&&$p['tenant_id']!=='')$q->where('tenant_id',(int)$p['tenant_id']);if(!$public&&isset($p['status'])&&$p['status']!=='')$q->where('status',(int)$p['status']);if(($p['keyword']??'')!=='')$q->whereLike('title','%'.addcslashes(StoryCommercePolicy::text($p['keyword'],100),'\\%_').'%');$count=(clone $q)->count();return ['lists'=>array_map(static fn($r)=>self::dto($r,$public,$scope),$q->order(['sort'=>'desc','id'=>'desc'])->page(max(1,(int)($p['page_no']??1)),max(1,min(30,(int)($p['page_size']??20))))->select()->toArray()),'count'=>$count];}
    public static function detail(int $scope,int $id,bool $public=false): array {if($public)StoryCommerceService::available($scope);return self::dto(self::row($scope,$id,$public),$public,$scope);}
    public static function adminDetail(int $scope,int $id): array {$r=StoryCommerceJobs::owned($scope,0,$id,true);return StoryCommerceJobs::dto($r,true);}
    private static function writable(int $scope,array $r): void {if($scope>0&&(int)$r['tenant_id']!==$scope)throw new InvalidArgumentException('平台共享模板仅供查看和使用');}
    public static function save(int $scope,array $p): array {
        $old=!empty($p['id'])?self::row($scope,(int)$p['id']):null;if($old)self::writable($scope,$old);$owner=$old?(int)$old['tenant_id']:$scope;$source=$old?self::stored($old):[];$b=StoryCommercePolicy::body((array)($p['configuration']??$source));
        foreach($b['images'] as $i=>&$image){$slot=$image['reference_slot_id']??'sc_template_'.$i;$previous=[];foreach($source['images']??[] as $saved)if(($saved['reference_slot_id']??'')===$slot){$previous=$saved;break;}$image=!empty($image['file_id'])?Media::adminAsset($owner,$image,10):(($previous&&$previous['uri']===($image['uri']??''))?Media::verify($owner,$previous,10):throw new InvalidArgumentException('请通过素材库选择有权使用的商品图片'));$image['reference_slot_id']=$slot;$image['mention_name']='图片'.($i+1);}unset($image);
        $b['genre_snapshot']=StoryCommerceService::genre($owner,$b);$b['style_snapshot']=StoryCommerceCatalog::style($owner,$b);StoryCommerceCatalog::selection($owner,$b);$b['script']=StoryCommercePolicy::script($b['script'],(int)$b['video_selection']['duration'],$b);$b['script_input_key']=StoryCommercePolicy::inputKey($b);
        $previous=(array)($old['config_json']['generation_request']['advanced_params']['story_commerce_media']??[]);$media=!empty($p['preview']['file_id'])?Media::adminAsset($owner,(array)$p['preview'],20):$previous;if(!$media)throw new InvalidArgumentException('请添加真实预览作品');Media::verify($owner,$media,20);
        return self::persist($owner,$old,$b,$media,(int)($old['source_task_id']??0),$p);
    }
    private static function persist(int $owner,?array $old,array $b,array $media,int $job,array $p): array {
        $title=StoryCommercePolicy::text($p['title']??$old['title']??'剧情带货灵感',100);if($title==='')throw new InvalidArgumentException('请填写模板名称');
        $clean=static function($v)use(&$clean){if(!is_array($v))return $v;unset($v['url']);foreach($v as $k=>$x)if(is_array($x))$v[$k]=$clean($x);return $v;};$b=$clean($b);$media=$clean($media);
        $r=AppCaseService::save($owner,StoryCommercePolicy::APP,['id'=>$old['id']??0,'title'=>$title,'prompt'=>StoryCommercePolicy::prompt($b),'media_type'=>'video','cover_uri'=>$b['images'][0]['uri'],'media_uri'=>$media['uri']??$media['video_uri'],'reference_images'=>array_column($b['images'],'uri'),'config_json'=>['generation_request'=>['advanced_params'=>['story_commerce'=>$b,'story_commerce_media'=>$media]]],'source_task_id'=>$job,'source_result_id'=>0,'status'=>(int)($p['status']??$old['status']??1)===1?1:0,'sort'=>max(0,min(9999,(int)($p['sort']??$old['sort']??0)))]);return self::detail($owner,(int)$r['id']);
    }
    public static function fromJob(int $scope,int $id,array $p): array
    {
        $j=StoryCommerceJobs::owned($scope,0,$id,true);
        if($j['action']!=='video'||$j['status']!=='success')throw new InvalidArgumentException('仅已完成视频可以设为模板');
        $owner=(int)$j['tenant_id'];$b=StoryCommercePolicy::json($j['request_json'])['snapshot'];
        $media=StoryCommercePolicy::json($j['result_json'])['videos'][0]??[];
        $media['uri']=$media['video_uri']??$media['uri']??'';
        if($media['uri']===''||empty($media['storage_engine']))throw new InvalidArgumentException('作品尚未保存到系统存储');
        // Generation receipts do not carry upload IDs. Resolve the exact owned stored object.
        $where=['tenant_id'=>$owner,'source'=>1,'source_id'=>(int)$j['user_id'],'type'=>20];
        foreach(['uri','storage_scope','storage_engine','storage_domain'] as $key)$where[$key]=(string)($media[$key]??'');
        $file=Db::name('tenant_file')->where($where)->whereRaw('(delete_time IS NULL OR delete_time=0)')->find();
        if(!$file)throw new InvalidArgumentException('作品素材已不可用，请重新保存作品');
        $media['file_id']=(int)$file['id'];$media['user_id']=(int)$j['user_id'];
        Media::verify($owner,$media,20);
        foreach($b['images'] as $img)Media::verify($owner,$img,10);
        $lock=SubmitLockService::acquire('story_commerce_case_'.$id,$owner);
        try{
            $old=AppCase::where(['tenant_id'=>$owner,'app_code'=>StoryCommercePolicy::APP,'source_task_id'=>$id,'delete_time'=>0])->find();
            return self::persist($owner,$old?$old->toArray():null,$b,$media,$id,$p);
        }finally{SubmitLockService::release($lock);}
    }
    public static function remix(int $t,int $u,int $id): array {
        StoryCommerceService::available($t,$u);$r=self::row($t,$id,true);$b=self::stored($r);$owner=(int)$r['tenant_id'];
        foreach($b['images'] as &$image)$image=Media::grant($t,$u,$owner,$image,10);unset($image);
        if($owner!==$t){$b['style_id']='';$b['custom_style']=trim(($b['style_snapshot']['name']??'')."\n".($b['style_snapshot']['prompt']??''));$b['story_type']='custom';$b['custom_story']=$b['genre_snapshot']['instruction']??'';}
        return StoryCommerceService::save($t,$u,['body'=>$b,'confirm_script'=>true]);
    }
    public static function status(int $s,int $id,int $v): void {$r=self::row($s,$id);self::writable($s,$r);AppCaseService::setStatus((int)$r['tenant_id'],StoryCommercePolicy::APP,$id,$v===1?1:0);}
    public static function delete(int $s,int $id): void {$r=self::row($s,$id);self::writable($s,$r);AppCaseService::delete((int)$r['tenant_id'],StoryCommercePolicy::APP,$id);}
    public static function galleryRow(array $r): array {$v=self::dto($r,true);$r['cover_url']=$v['cover_url'];$r['media_url']=$v['media_url'];return $r;}
}
