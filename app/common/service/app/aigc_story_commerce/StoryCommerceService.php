<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_story_commerce;
use app\common\service\app\{AppAccessService,AppDisplayConfigService};
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\storage\StorageSignedUrlService;
use app\common\service\FileService;
use app\common\service\ai\ReferenceMaterialSlot;
use InvalidArgumentException;
use think\facade\Db;
final class StoryCommerceService
{
    public const APP=StoryCommercePolicy::APP;
    public static function table(string $s){return Db::name(self::APP.'_'.$s);}
    public static function available(int $t,int $u=0): void {if(!AppAccessService::tenantCanUse($t,self::APP)||(int)(self::settings($t)['status']??1)!==1)throw new InvalidArgumentException('剧情带货暂未开放');if($u>0&&!\app\common\service\membership\MembershipService::userCanUseApp($t,$u,self::APP))throw new InvalidArgumentException('当前套餐尚未开放剧情带货');}
    public static function defaultGenres(): array {return json_decode(file_get_contents(dirname(__DIR__,4).'/apps/'.self::APP.'/data/genres.json'),true,64,JSON_THROW_ON_ERROR);}
    public static function settings(int $t): array {
        $global=StoryCommercePolicy::json(self::table('config')->where('tenant_id',0)->value('body_json')??'{}');
        $local=$t>0?StoryCommercePolicy::json(self::table('config')->where('tenant_id',$t)->value('body_json')??'{}'):[];
        return array_replace(['status'=>1,'planning_model_id'=>'','default_video_model_id'=>'','default_style_id'=>'','genres'=>self::defaultGenres()],$global,$local);
    }
    public static function setup(int $t,array $p): array {
        $old=self::settings($t);$data=array_intersect_key(array_replace($old,$p),array_flip(['status','planning_model_id','default_video_model_id','default_style_id','genres']));
        $data['status']=!empty($data['status'])?1:0;$seen=[];$genres=[];
        foreach((array)$data['genres'] as $i=>$row){$key=StoryCommercePolicy::text($row['code']??'',80);if(!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$key)||isset($seen[$key])||$key==='custom')throw new InvalidArgumentException('剧情类型标识为空、重复或无效');$seen[$key]=true;
            if(trim((string)($row['name']??''))===''||trim((string)($row['instruction']??''))==='')throw new InvalidArgumentException('请填写剧情类型名称和叙事规则');
            $genres[]=['code'=>$key,'name'=>StoryCommercePolicy::text($row['name']??'',80),'instruction'=>StoryCommercePolicy::text($row['instruction']??'',2000),'version'=>StoryCommercePolicy::hash([$row['name']??'',$row['instruction']??'']),'status'=>!empty($row['status'])?1:0,'sort'=>(int)($row['sort']??0)];}
        $data['genres']=$genres;
        if($data['planning_model_id']!==''&&!in_array((string)$data['planning_model_id'],array_column(MarketTextModelRuntimeService::mediaOptions($t,false),'id'),true))throw new InvalidArgumentException('所选分析模型已不可用');
        if($data['default_video_model_id']!==''&&!StoryCommerceCatalog::models($t,(string)$data['default_video_model_id']))throw new InvalidArgumentException('默认视频模型不可用');
        if($data['default_style_id']!==''&&!in_array((string)$data['default_style_id'],array_column(StoryCommerceCatalog::styles($t),'id'),true))throw new InvalidArgumentException('默认画风不可用');
        return Db::transaction(static function()use($t,$p,$data){AppDisplayConfigService::saveFromConfigPayload($t,self::APP,$p);$q=self::table('config')->where('tenant_id',$t);$v=['body_json'=>StoryCommercePolicy::encode($data),'update_time'=>time()];if($q->find())$q->update($v);else self::table('config')->insert($v+['tenant_id'=>$t,'create_time'=>time()]);return $data;});
    }
    public static function config(int $t): array {
        $c=self::settings($t);$c['genres']=array_values(array_filter($c['genres'],static fn($r)=>!empty($r['status'])));usort($c['genres'],static fn($a,$b)=>$b['sort']<=>$a['sort']);
        return AppDisplayConfigService::appendToConfig($t,self::APP,$c+['styles'=>StoryCommerceCatalog::styles($t),'planning_models'=>MarketTextModelRuntimeService::mediaOptions($t,false)]);
    }
    public static function models(int $t,string $kind,string $id=''): array {return ['models'=>$kind==='planning'?MarketTextModelRuntimeService::mediaOptions($t,false):StoryCommerceCatalog::models($t,$id)];}
    public static function normalize(int $t,int $u,array $b): array {
        $b=StoryCommercePolicy::body($b);$b['images']=MicroVideoMedia::validateImages($t,$u,$b['images'],15);$used=[];
        foreach($b['images'] as $i=>&$img){$slot=ReferenceMaterialSlot::id($img)?:'sc_'.substr(hash('sha256',$img['uri'].'|'.$i),0,24);if(isset($used[$slot]))throw new InvalidArgumentException('商品素材槽位重复');$used[$slot]=true;$img['reference_slot_id']=$slot;$img['mention_name']='图片'.($i+1);}unset($img);
        $b['genre_snapshot']=self::genre($t,$b);$b['style_snapshot']=StoryCommerceCatalog::style($t,$b);return $b;
    }
    public static function genre(int $t,array $b): array {
        if($b['story_type']==='custom'){if($b['custom_story']==='')throw new InvalidArgumentException('请填写自定义剧情类型');return ['code'=>'custom','name'=>'自定义','instruction'=>$b['custom_story'],'version'=>1];}
        foreach(self::settings($t)['genres'] as $row)if($row['code']===$b['story_type']&&!empty($row['status']))return $row;
        throw new InvalidArgumentException('所选剧情类型已停用，请重新选择');
    }
    public static function publicBody(array $b,int $t): array {
        $walk=static function($v)use(&$walk,$t){if(!is_array($v))return $v;$uri=$v['uri']??$v['video_uri']??'';
            if(is_string($uri)&&$uri!==''&&isset($v['storage_engine'])){$v['url']=StorageSignedUrlService::resolve($uri,(string)($v['storage_scope']??'tenant'),(string)$v['storage_engine'],(string)($v['storage_domain']??''),$t)?:FileService::format((string)($v['storage_domain']??''),$uri);if(isset($v['video_uri']))$v['video_url']=$v['url'];}
            unset($v['tenant_id'],$v['user_id'],$v['file_id']);foreach($v as $k=>$x)if(is_array($x))$v[$k]=$walk($x);return $v;};return $walk($b);
    }
    public static function owned(int $t,int $u,int $id,bool $lock=false): array {$q=self::table('project')->where(['tenant_id'=>$t,'user_id'=>$u,'id'=>$id,'delete_time'=>0]);if($lock)$q->lock(true);$r=$q->find();if(!$r)throw new InvalidArgumentException('草稿不存在或无权访问');return $r;}
    public static function projectDto(array $r): array {$b=StoryCommercePolicy::json($r['body_json']);return ['id'=>(int)$r['id'],'revision'=>(int)$r['revision'],'body'=>self::publicBody($b,(int)$r['tenant_id']),'script_fresh'=>!empty($b['script'])&&($b['script_input_key']??'')===StoryCommercePolicy::inputKey($b)];}
    public static function save(int $t,int $u,array $p): array {
        self::available($t,$u);$b=self::normalize($t,$u,(array)($p['body']??[]));
        if(!empty($p['confirm_script'])){$b['script']=StoryCommercePolicy::script($b['script'],(int)($b['video_selection']['duration']??0),$b);$b['script_input_key']=StoryCommercePolicy::inputKey($b);}
        return Db::transaction(static function()use($t,$u,$p,$b){$id=(int)($p['id']??0);$v=['body_json'=>StoryCommercePolicy::encode($b),'update_time'=>time()];if($id){$old=self::owned($t,$u,$id,true);if((int)($p['revision']??0)!==(int)$old['revision'])throw new InvalidArgumentException('草稿已更新，请刷新后继续');self::table('project')->where('id',$id)->update($v+['revision'=>(int)$old['revision']+1]);}else $id=self::table('project')->insertGetId($v+['tenant_id'=>$t,'user_id'=>$u,'revision'=>1,'create_time'=>time()]);return self::projectDto(self::owned($t,$u,$id));});
    }
    public static function adopt(int $t,int $u,array $p): array {return Db::transaction(static function()use($t,$u,$p){$j=StoryCommerceJobs::owned($t,$u,(int)($p['job_id']??0));$r=self::owned($t,$u,(int)$j['project_id'],true);if($j['action']!=='analysis'||$j['status']!=='success')throw new InvalidArgumentException('分析完成后才能回填');if((int)$r['revision']!==(int)($p['revision']??0)||(int)$r['revision']!==(int)$j['project_revision'])throw new InvalidArgumentException('草稿已有修改，分析结果已保留');$b=StoryCommercePolicy::json($r['body_json']);$result=StoryCommercePolicy::json($j['result_json']);$b['product_info']=$result['product_info'];$b['script']=$result['script'];$b['script_input_key']=StoryCommercePolicy::inputKey($b);self::table('project')->where('id',$r['id'])->update(['body_json'=>StoryCommercePolicy::encode($b),'revision'=>$r['revision']+1,'update_time'=>time()]);return self::projectDto(self::owned($t,$u,(int)$r['id']));});}
    /** Historical copies retain the approved creative rules if a library entry later changes. */
    public static function historicalBody(int $tenant,array $body): array
    {
        $oldGenre=$body['genre_snapshot']??[];$oldStyle=$body['style_snapshot']??[];
        try{$current=self::genre($tenant,$body);}catch(\InvalidArgumentException){$current=[];}
        if(($current['name']??'')!==($oldGenre['name']??'')||($current['instruction']??'')!==($oldGenre['instruction']??'')){
            $body['story_type']='custom';$body['custom_story']=trim(($oldGenre['name']??'')."\n".($oldGenre['instruction']??''));
        }
        try{$current=StoryCommerceCatalog::style($tenant,$body);}catch(\InvalidArgumentException){$current=[];}
        if(($current['name']??'')!==($oldStyle['name']??'')||($current['prompt']??'')!==($oldStyle['prompt']??'')){
            $body['style_id']='';$body['custom_style']=trim(($oldStyle['name']??'')."\n".($oldStyle['prompt']??''));
        }
        return $body;
    }
    public static function fromJob(int $t,int $u,int $id,bool $includeResult=false): array {$j=StoryCommerceJobs::owned($t,$u,$id);if(in_array($j['status'],['queued','running','unknown'],true))throw new InvalidArgumentException('任务状态待核对，暂不可重新编辑');$b=StoryCommercePolicy::json($j['request_json'])['snapshot']??[];if($includeResult&&$j['action']==='analysis'&&$j['status']==='success'){$result=StoryCommercePolicy::json($j['result_json']);$b['product_info']=$result['product_info'];$b['script']=$result['script'];}return self::save($t,$u,['body'=>self::historicalBody($t,$b),'confirm_script'=>$j['action']==='video'||$includeResult]);}
}
