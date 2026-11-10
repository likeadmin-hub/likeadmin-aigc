<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;

use app\common\model\app\AppCase;
use app\common\service\app\AppCaseService;
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use app\common\service\SubmitLockService;
use InvalidArgumentException;
use think\facade\Db;

/** Published cases carry editable creation inputs, never job receipts, quotes or credentials. */
final class ProductVideoTemplates
{
    public static function snapshot(array $input): array
    {
        $body=ProductVideoPolicy::body($input);
        $fresh=!empty($body['plan'])&&($body['plan']['input_key']??'')===ProductVideoPolicy::planKey($body);
        $body['images']=array_map(static fn($image)=>array_intersect_key((array)$image,array_flip(['uri','name','role','reference_slot_id','mention_name','storage_scope','storage_engine','storage_domain'])), $body['images']);
        $body['video_selection']=array_intersect_key($body['video_selection'],array_flip(['model_id','quality','ratio','duration']));
        $body['normal_source']='prompt';$body['requirements']='';$body['reference_video']=null;$body['reference_analysis']=null;$body['generation_assets']=[];
        $body['analysis_model_id']='';$body['planning_model_id']='';
        $body['prompt_review_images']=array_column($body['images'],'uri');
        $body['selected_mentions']=array_map(static fn($image)=>['name'=>$image['mention_name']??'','type'=>'image','reference_slot_id'=>$image['reference_slot_id']??'','uri'=>$image['uri']],$body['images']);
        if(is_array($body['plan'])){
            $data=array_intersect_key((array)($body['plan']['data']??[]),array_flip(['summary','shots','duration','video_prompt','selling_points']));
            if(($body['mode']??'normal')==='advanced')$body['prompt']=(string)($data['video_prompt']??$body['prompt']);
            $body['plan']=['data'=>$data,'input_key'=>$fresh?ProductVideoPolicy::planKey($body):''];
        }
        return $body;
    }

    public static function configuration(array $job): array
    {
        $request=ProductVideoPolicy::json($job['request_json']);
        $snapshot=self::snapshot((array)($request['snapshot']??[]));
        if($snapshot['prompt']==='')$snapshot['prompt']=ProductVideoPolicy::text($request['prompt']??'',12000);
        return $snapshot;
    }

    private static function row(int $scope,int $id,bool $public=false): array
    {
        $q=AppCase::where(['id'=>$id,'app_code'=>ProductVideoPolicy::APP,'delete_time'=>0]);
        if($scope>0||$public)$q->where('tenant_id',$scope);
        if($public)$q->where('status',1);
        $row=$q->find();if(!$row)throw new InvalidArgumentException('灵感模板不存在或已停用');return $row->toArray();
    }

    public static function lists(int $scope,array $params,bool $public=false): array
    {
        if($public)ProductVideoService::available($scope);
        $q=AppCase::where(['app_code'=>ProductVideoPolicy::APP,'delete_time'=>0]);if($scope>0||$public)$q->where('tenant_id',$scope);
        if($public)$q->where('status',1);elseif(isset($params['status'])&&$params['status']!=='')$q->where('status',(int)$params['status']);
        if(trim((string)($params['keyword']??''))!=='')$q->whereLike('title','%'.addcslashes(mb_substr(trim($params['keyword']),0,100),'\\%_').'%');
        $page=max(1,(int)($params['page_no']??1));$size=max(1,min(30,(int)($params['page_size']??20)));$count=(clone $q)->count();
        return ['lists'=>array_map(static fn($r)=>self::present($r,$public),$q->order(['sort'=>'desc','id'=>'desc'])->page($page,$size)->select()->toArray()),'count'=>$count,'page_no'=>$page,'page_size'=>$size];
    }

    public static function detail(int $scope,int $id,bool $public=false): array
    {if($public)ProductVideoService::available($scope);return self::present(self::row($scope,$id,$public),$public);}

    private static function body(array $row): array
    {return self::snapshot((array)($row['config_json']['generation_request']['advanced_params']['product_video']??[]));}

    private static function present(array $row,bool $public=false): array
    {
        $body=self::body($row);$extra=$row['config_json']['generation_request']['advanced_params']??[];
        $media=ProductVideoService::publicBody((array)($extra['product_video_media']??[]),(int)$row['tenant_id']);
        $cover=ProductVideoService::publicBody($body['images'][0]??[],(int)$row['tenant_id']);
        $dto=['id'=>(int)$row['id'],'title'=>$row['title'],'prompt'=>$body['prompt'],'configuration'=>ProductVideoService::publicBody($body,(int)$row['tenant_id']),
            'media_type'=>'video','media_url'=>$media['url']??$media['video_url']??'','cover_url'=>$cover['url']??'',
            'ratio'=>$body['video_selection']['ratio']??'','model_name'=>$row['config_json']['model_name']??'','create_time'=>(int)$row['create_time']];
        if(!$public)$dto+=['tenant_id'=>(int)$row['tenant_id'],'source_job_id'=>(int)$row['source_task_id'],'status'=>(int)$row['status'],'sort'=>(int)$row['sort']];
        return $dto;
    }

    public static function fromJob(int $scope,int $id,array $params): array
    {
        $job=ProductVideoJobs::owned($scope,0,$id,true);
        if($job['action']!=='video'||!in_array($job['status'],['success','partial'],true))throw new InvalidArgumentException('只有已完成的视频作品可以设为灵感模板');
        $body=self::configuration($job);$body['images']=MicroVideoMedia::validateImages((int)$job['tenant_id'],(int)$job['user_id'],$body['images'],15);
        $media=ProductVideoPolicy::json($job['result_json'])['videos'][0]??[];
        $uri=(string)($media['video_uri']??$media['uri']??'');
        if($uri===''||empty($media['storage_engine']))throw new InvalidArgumentException('作品尚未保存到系统存储，暂不能设为模板');
        $media=array_intersect_key($media,array_flip(['uri','video_uri','storage_scope','storage_engine','storage_domain','duration','width','height']));$media['uri']=$uri;
        $lock=SubmitLockService::acquire('product_video_case_'.$id,(int)$job['tenant_id']);
        try{
            $old=AppCase::where(['tenant_id'=>$job['tenant_id'],'app_code'=>ProductVideoPolicy::APP,'source_task_id'=>$id,'delete_time'=>0])->order('id','desc')->find();
            return self::persist((int)$job['tenant_id'],$old?$old->toArray():null,$body,$media,$id,$params);
        }finally{SubmitLockService::release($lock);}
    }

    public static function save(int $scope,array $params): array
    {
        $old=self::row($scope,(int)($params['id']??0));$extra=$old['config_json']['generation_request']['advanced_params'];
        return self::persist((int)$old['tenant_id'],$old,self::body($old),(array)$extra['product_video_media'],(int)$old['source_task_id'],$params);
    }

    private static function persist(int $tenant,?array $old,array $source,array $media,int $jobId,array $params): array
    {
        $patch=(array)($params['configuration']??[]);$body=$source;
        foreach(['mode','reference_mode','product_info','prompt','market_region','language','audio','analyze_chapters','video_selection'] as $key)if(array_key_exists($key,$patch))$body[$key]=$patch[$key];
        // References can be reordered or removed; only the source's authorized objects can be published.
        if(isset($patch['images'])){
            $allowed=array_column($source['images'],null,'uri');$images=[];
            foreach((array)$patch['images'] as $asset){$uri=(string)($asset['uri']??'');if(!isset($allowed[$uri]))throw new InvalidArgumentException('模板素材必须来自原视频记录');$images[]=$allowed[$uri];}
            $body['images']=$images;
        }
        if(($body['reference_mode']??'reference')==='start_end'&&$body['mode']==='normal')foreach($body['images'] as $i=>&$image)$image['role']=$i===0?'first_frame_image':'last_frame_image';unset($image);
        if(ProductVideoPolicy::planKey($body)!==ProductVideoPolicy::planKey($source)||($body['plan']['input_key']??'')!==ProductVideoPolicy::planKey($source))$body['plan']=null;
        elseif(is_array($body['plan']))$body['plan']['data']['video_prompt']=$body['prompt'];
        $body=self::snapshot($body);
        if(!$body['images']||$body['prompt']==='')throw new InvalidArgumentException('请保留商品素材并填写视频描述');
        $model=ProductVideoCatalog::model($tenant,$body);self::validate($model,$body);
        $title=ProductVideoPolicy::text($params['title']??$old['title']??'商品视频灵感',100);if($title==='')throw new InvalidArgumentException('请填写模板名称');
        $config=['model_name'=>$model['label'],'generation_request'=>['model_id'=>$model['id'],'prompt'=>$body['prompt'],
            'quality'=>$body['video_selection']['quality'],'ratio'=>$body['video_selection']['ratio'],'duration'=>$body['video_selection']['duration'],
            'reference_assets'=>$body['images'],'reference_images'=>array_column($body['images'],'uri'),
            'advanced_params'=>['product_video'=>$body,'product_video_media'=>$media]]];
        $saved=AppCaseService::save($tenant,ProductVideoPolicy::APP,['id'=>$old['id']??0,'title'=>$title,'prompt'=>$body['prompt'],'media_type'=>'video',
            'cover_uri'=>$body['images'][0]['uri'],'media_uri'=>$media['uri'],'reference_images'=>array_column($body['images'],'uri'),'config_json'=>$config,
            'source_task_id'=>$jobId,'source_result_id'=>0,'status'=>(int)($params['status']??$old['status']??1)===1?1:0,'sort'=>max(0,min(9999,(int)($params['sort']??$old['sort']??0)))]);
        return self::detail($tenant,(int)$saved['id']);
    }

    private static function validate(array $model,array $body): void
    {
        ProductVideoCatalog::spec($model,$body);
        if($body['audio']!==null&&empty($model['supports_generate_audio']))throw new InvalidArgumentException('模板所选模型不支持声音开关，请改为自动');
        ProductVideoMaterials::assertMentions($body,$body['images']);
    }

    public static function status(int $scope,int $id,int $status): void
    {$row=self::row($scope,$id);AppCaseService::setStatus((int)$row['tenant_id'],ProductVideoPolicy::APP,$id,$status===1?1:0);}
    public static function delete(int $scope,int $id): void
    {$row=self::row($scope,$id);AppCaseService::delete((int)$row['tenant_id'],ProductVideoPolicy::APP,$id);}

    public static function remix(int $tenant,int $user,int $id): array
    {
        ProductVideoService::available($tenant,$user);$row=self::row($tenant,$id,true);$body=self::body($row);
        $model=ProductVideoCatalog::model($tenant,$body);self::validate($model,$body);
        $lock=SubmitLockService::acquire('product_video_remix_'.$id,$tenant,$user);
        try{return Db::transaction(static function()use($tenant,$user,$row,$body){
            $fresh=self::row($tenant,(int)$row['id'],true);
            if($fresh['config_json']!==$row['config_json'])throw new InvalidArgumentException('模板已更新，请刷新后重试');
            foreach($body['images'] as $image){
                $file=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'uri'=>$image['uri'],'type'=>10])->whereRaw('(delete_time IS NULL OR delete_time = 0)')->order('id','desc')->find();
                if(!$file)throw new InvalidArgumentException('模板素材已不可用，请选择其他模板');
                foreach(['storage_scope','storage_engine','storage_domain'] as $key)if((string)($image[$key]??'')!==(string)($file[$key]??''))throw new InvalidArgumentException('模板素材存储信息已变化，请更新模板');
                $owned=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'uri'=>$image['uri'],'source'=>1,'source_id'=>$user,'type'=>10])->whereRaw('(delete_time IS NULL OR delete_time = 0)')->find();
                if(!$owned)Db::name('tenant_file')->insert(['tenant_id'=>$tenant,'uri'=>$file['uri'],'type'=>10,'source'=>1,'source_id'=>$user,'name'=>$file['name'],'storage_scope'=>$file['storage_scope'],'storage_engine'=>$file['storage_engine'],'storage_domain'=>$file['storage_domain'],'create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
            }
            return ProductVideoService::save($tenant,$user,['title'=>mb_substr($row['title'],0,90).' · 同款','body'=>$body]);
        });}finally{SubmitLockService::release($lock);}
    }

    public static function options(int $scope,array $params): array
    {
        $row=!empty($params['job_id'])?ProductVideoJobs::owned($scope,0,(int)$params['job_id'],true):self::row($scope,(int)($params['id']??0));
        return ProductVideoService::models((int)$row['tenant_id'],'video',(string)($params['model_id']??''));
    }

    public static function adminDetail(int $scope,int $id): array
    {
        $job=ProductVideoJobs::owned($scope,0,$id,true);if($job['action']!=='video')throw new InvalidArgumentException('创作记录仅展示视频任务');
        return ProductVideoJobs::dto($job)+['tenant_id'=>(int)$job['tenant_id'],'user_id'=>(int)$job['user_id'],'configuration'=>ProductVideoService::publicBody(self::configuration($job),(int)$job['tenant_id'])];
    }
}
