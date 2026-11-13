<?php
declare(strict_types=1);
namespace app\common\service;

use app\common\service\app\aigc_store_visit\StoreVisitPolicy;
use app\common\service\app\aigc_store_visit\StoreVisitService;
use InvalidArgumentException;
use think\facade\Db;

/** Project existing final videos without duplicating files, tasks or model charges. */
final class WorkspaceStoreVisitAssetService
{
    private static function query(int $tenant,int $user)
    {
        if($tenant<1||$user<1)throw new InvalidArgumentException('请先登录');
        return Db::name('aigc_store_visit_job')->where(['tenant_id'=>$tenant,'user_id'=>$user,'action'=>'video','delete_time'=>0])
            ->whereIn('status',['success','partial']);
    }

    public static function lists(int $tenant,int $user,int $page): array
    {
        $rows=self::query($tenant,$user)->order('id','desc')->page(max(1,$page),30)->select()->toArray();
        return ['lists'=>self::rows($tenant,$user,$rows),'has_more'=>count($rows)===30,'page_size'=>30];
    }

    public static function owned(int $tenant,int $user,string $ref): ?array
    {
        if(!preg_match('/^store_visit:task:([1-9][0-9]{0,10}):([1-9][0-9]{0,4})$/D',$ref,$parts))return null;
        $row=self::query($tenant,$user)->where('id',(int)$parts[1])->find();
        if(!$row)return null;
        foreach(self::rows($tenant,$user,[$row]) as $item)if($item['ref']===$ref)return $item;
        return null;
    }

    /** Input references are resolved in one batch across the whole result page. */
    public static function rows(int $tenant,int $user,array $rows): array
    {
        $requests=$groups=[];
        foreach($rows as $index=>$row){
            $requests[$index]=StoreVisitPolicy::json($row['request_json']??'{}');
            $snapshot=$requests[$index]['snapshot']??[];
            $groups[$index]=array_merge((array)($snapshot['images']??[]),(array)($snapshot['generation_assets']??[]));
        }
        $references=WorkspaceAssetReferenceService::resolveGroups($tenant,$user,$groups);
        $items=[];
        foreach($rows as $index=>$row){
            if((int)($row['tenant_id']??0)!==$tenant||(int)($row['user_id']??0)!==$user||($row['action']??'')!=='video'
                ||!in_array($row['status']??'',['success','partial'],true)||!empty($row['delete_time']))continue;
            $request=$requests[$index];$snapshot=$request['snapshot']??[];
            $selection=array_intersect_key((array)($snapshot['video_selection']??$request['selection']??[]),array_flip(['model_id','quality','resolution','ratio','duration','generate_audio']));
            $prompt=StoreVisitPolicy::prompt($snapshot);
            $result=StoreVisitPolicy::json($row['result_json']??'{}');
            if(!is_array($result['videos']??null)||!array_is_list($result['videos']))continue;
            foreach(array_slice($result['videos'],0,100) as $output=>$video){
                if(!is_array($video))continue;
                $uri=trim((string)($video['video_uri']??$video['uri']??$video['video_url']??$video['url']??''));
                if(!preg_match('~^(?:https?://|/?(?:uploads|storage)/)~i',$uri))continue;
                $public=StoreVisitService::publicBody($video,$tenant);
                $url=(string)($public['video_url']??$public['url']??$uri);
                $meta=array_intersect_key($video,array_flip(['storage_scope','storage_engine','storage_domain','width','height','duration','mime_type','file_size']));
                $raw=$meta+[
                    'id'=>$row['id'].'_'.($output+1),'task_id'=>(int)$row['id'],'project_id'=>(int)$row['project_id'],
                    'asset_type'=>'video','status'=>'success','title'=>'探店视频','prompt'=>$prompt,
                    'video_url'=>$url,'url'=>$url,'uri'=>$uri,'cover_url'=>(string)($public['cover_url']??''),
                    'create_time'=>(int)$row['create_time'],'params'=>$selection,
                    'reference_assets'=>$references[$index]??[],
                    'creation_source'=>['app_code'=>'aigc_store_visit','task_id'=>(string)$row['id']],
                ];
                $items[]=['source'=>'store_visit','ref'=>'store_visit:task:'.$row['id'].':'.($output+1),'raw'=>$raw];
            }
        }
        return $items;
    }

    public static function pickerSource(bool $saved): array
    {
        $request="IF(JSON_VALID(r.request_json),r.request_json,'{}')";
        $result="IF(JSON_VALID(r.result_json),r.result_json,'{}')";
        return ['table'=>'aigc_store_visit_job','type'=>"'video'",
            'name'=>"COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($request,'$.snapshot.script.summary')),''),'探店视频')",
            'extra'=>"JSON_OBJECT('request',JSON_EXTRACT($request,'$'),'result',JSON_EXTRACT($result,'$'),'project_id',r.project_id)",
            'where'=>"r.action='video' AND r.status IN ('success','partial') AND JSON_LENGTH(JSON_EXTRACT($result,'$.videos'))>0",
            'stored'=>false,'saved'=>$saved];
    }

    public static function pickerOutputs(int $tenant,int $user,array $row): array
    {
        $extra=StoreVisitPolicy::json($row['extra']??'{}');
        $job=['id'=>$row['id'],'tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$extra['project_id']??0,'action'=>'video','status'=>'success','delete_time'=>0,
            'create_time'=>$row['stamp'],'request_json'=>$extra['request']??[],'result_json'=>$extra['result']??[]];
        return array_map(static function($item){
            $raw=$item['raw'];
            return ['id'=>$item['ref'],'type'=>'video','name'=>$raw['prompt']?:$raw['title'],'created_at'=>$raw['create_time'],
                'url'=>$raw['video_url'],'uri'=>$raw['uri'],'cover'=>$raw['cover_url'],'mime'=>$raw['mime_type']??'',
                'size'=>(int)($raw['file_size']??0),'width'=>(int)($raw['width']??0),'height'=>(int)($raw['height']??0),
                'duration'=>(float)($raw['duration']??0)]+array_intersect_key($raw,array_flip(['storage_scope','storage_engine','storage_domain']));
        },self::rows($tenant,$user,[$job]));
    }
}
