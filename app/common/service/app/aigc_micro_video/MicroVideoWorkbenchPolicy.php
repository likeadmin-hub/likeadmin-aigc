<?php
namespace app\common\service\app\aigc_micro_video;
use InvalidArgumentException;

/** Pure workbench rules; no runtime DDL or paid side effects. */
final class MicroVideoWorkbenchPolicy
{
    public const KINDS=['template','product','project','role'];
    public const ACTIONS=['rewrite','analyze_roles','analyze_scenes','role_image','scene_image'];
    public static function kind(string $kind): string
    {if(!in_array($kind,self::KINDS,true))throw new InvalidArgumentException('资料类型无效');return $kind;}
    public static function action(string $action): string
    {if(!in_array($action,self::ACTIONS,true))throw new InvalidArgumentException('准备操作无效');return $action;}
    public static function revision(array $row,int $revision): void
    {if((int)$row['revision']!==$revision)throw new InvalidArgumentException('版本已变化，请刷新后重新编辑');}
    public static function key(string $key): string
    {if(!preg_match('/^[a-zA-Z0-9_.:-]{8,90}$/D',$key))throw new InvalidArgumentException('请求标识无效');return $key;}
    public static function hash(mixed $value): string
    {return hash('sha256',MicroVideoStore::encode(self::canonical($value)));}
    private static function canonical(mixed $v): mixed
    {if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as &$x)$x=self::canonical($x);return $v;}
    /** Canonicalize only declared workbench semantics; arbitrary facts and prose remain byte-for-byte values. */
    public static function normalizeValues(array $value,string $context='body'): array
    {
        $integerFields=match($context){
            'segment'=>['sort','duration'], 'scene'=>['sort'], 'brief'=>['target_duration'],
            'reference'=>['id','revision'], 'scene_reset'=>['previous_revision','previous_scene_count'],
            'beat'=>['sort'], 'body'=>['target_duration','default_duration','task_id','direction_id','cover_index'], default=>[]
        };
        $booleanFields=match($context){
            'body'=>['script_stale','fixed_identity'], 'role'=>['fixed_identity'],
            'scene'=>['confirmed','product_visible'], 'segment'=>['product_visible'],
            'scene_reset'=>['requires_reassignment'], default=>[]
        };
        foreach($integerFields as $field)if(array_key_exists($field,$value))$value[$field]=self::integerValue($value[$field]);
        foreach($booleanFields as $field)if(array_key_exists($field,$value))$value[$field]=self::booleanValue($value[$field]);
        if($context==='beat')foreach(['start','end'] as $field)if(array_key_exists($field,$value))$value[$field]=self::numberValue($value[$field]);
        if($context==='body'){
            foreach(['roles'=>'role','scenes'=>'scene','beats'=>'beat','segments'=>'segment'] as $field=>$itemContext)if(isset($value[$field])&&is_array($value[$field]))foreach($value[$field] as $index=>$item)if(is_array($item))$value[$field][$index]=self::normalizeValues($item,$itemContext);
            foreach(['script'=>'script','brief'=>'brief','template'=>'reference','product'=>'reference','variant_source'=>'reference','duplicate_source'=>'reference','scene_reset'=>'scene_reset'] as $field=>$itemContext)if(isset($value[$field])&&is_array($value[$field]))$value[$field]=self::normalizeValues($value[$field],$itemContext);
            if(isset($value['durations'])&&is_array($value['durations']))$value['durations']=array_map([self::class,'integerValue'],$value['durations']);
            foreach(['template_snapshot','product_snapshot'] as $field)if(isset($value[$field])&&is_array($value[$field])){
                foreach(['id','revision'] as $number)if(array_key_exists($number,$value[$field]))$value[$field][$number]=self::integerValue($value[$field][$number]);
                if(isset($value[$field]['body'])&&is_array($value[$field]['body']))$value[$field]['body']=self::normalizeValues($value[$field]['body']);
            }
        }
        if($context==='script'&&isset($value['segments'])&&is_array($value['segments']))foreach($value['segments'] as $index=>$segment)if(is_array($segment))$value['segments'][$index]=self::normalizeValues($segment,'segment');
        if($context==='segment'&&isset($value['image_indices'])&&is_array($value['image_indices']))$value['image_indices']=array_map([self::class,'integerValue'],$value['image_indices']);
        return $value;
    }
    private static function booleanValue(mixed $value): bool
    {
        if(is_bool($value))return $value;
        if($value===1||$value===1.0)return true;if($value===0||$value===0.0)return false;
        if(is_string($value)){return match(strtolower(trim($value))){'1','true'=>true,'','0','false'=>false,default=>throw new InvalidArgumentException('创作资料布尔字段格式无效，请使用true/false或0/1')};}
        throw new InvalidArgumentException('创作资料布尔字段格式无效，请使用true/false或0/1');
    }
    private static function integerValue(mixed $value): int
    {
        if(is_int($value))return $value;if($value==='')return 0;
        if(is_float($value)&&is_finite($value)&&floor($value)===$value&&$value<=PHP_INT_MAX&&$value>=PHP_INT_MIN)return (int)$value;
        if(is_string($value)&&preg_match('/^-?\d+$/D',trim($value))){$parsed=filter_var(trim($value),FILTER_VALIDATE_INT);if($parsed!==false)return $parsed;}
        throw new InvalidArgumentException('创作资料数字字段必须是有效整数');
    }
    private static function numberValue(mixed $value): int|float
    {
        if($value==='')return 0;if(!is_int($value)&&!is_float($value)&&!is_string($value))throw new InvalidArgumentException('叙事节拍时间必须是有效数字');
        if(!is_numeric($value)||!is_finite((float)$value))throw new InvalidArgumentException('叙事节拍时间必须是有效数字');$number=(float)$value;
        return floor($number)===$number&&$number<=PHP_INT_MAX&&$number>=PHP_INT_MIN?(int)$number:$number;
    }
    public static function body(string $kind,array $body): array
    {
        self::kind($kind);$body=self::normalizeValues($body);if(strlen(MicroVideoStore::encode($body))>262144)throw new InvalidArgumentException('创作资料过长，请减少内容');
        if($kind==='project'){
            $stage=(string)($body['stage']??'draft');if(!in_array($stage,['draft','script_ready','assets_ready','ready'],true))throw new InvalidArgumentException('项目阶段无效');$body['stage']=$stage;
            $ids=[];foreach((array)($body['roles']??[]) as $role){$id=(string)($role['role_id']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)||isset($ids[$id]))throw new InvalidArgumentException('角色标识缺失或重复');$ids[$id]=true;}
            $scenes=[];$sorts=[];$segmentCount=count((array)($body['script']['segments']??[]));foreach((array)($body['scenes']??[]) as $scene){$sort=filter_var($scene['sort']??null,FILTER_VALIDATE_INT);if($sort===false||$sort<1||$sort>60||isset($sorts[$sort])||($segmentCount>0&&$sort>$segmentCount))throw new InvalidArgumentException('分镜段落序号须唯一并对应现有剧本段落');$sorts[$sort]=true;$id=(string)($scene['scene_id']??'');if($id===''||isset($scenes[$id]))throw new InvalidArgumentException('分镜标识缺失或重复');$scenes[$id]=true;foreach((array)($scene['role_ids']??[]) as $rid)if(!isset($ids[$rid]))throw new InvalidArgumentException('分镜引用了不存在的角色');}
            if(count($ids)>60||count($scenes)>60)throw new InvalidArgumentException('角色或分镜数量超过限制');
        }
        return $body;
    }
    private static function dependencyShape(array $value): array
    {
        foreach($value as $key=>$item){
            if($key==='images'&&is_array($item))$value[$key]=array_map(static fn($image)=>array_intersect_key((array)$image,array_flip(['uri','storage_scope','storage_engine','storage_domain','role'])),$item);
            elseif(is_array($item))$value[$key]=self::dependencyShape($item);
        }
        return $value;
    }
    private static function sceneContent(array $scene): array
    {unset($scene['confirmed'],$scene['status'],$scene['dependency_hash']);return self::dependencyShape($scene);}
    public static function dependencies(array $body,array $old): array
    {
        $body=self::normalizeValues($body);$old=self::normalizeValues($old);
        $script=self::hash($body['script']??[])!==self::hash($old['script']??[]);
        $product=self::hash(self::dependencyShape($body['product_snapshot']??[]))!==self::hash(self::dependencyShape($old['product_snapshot']??[]));
        $template=self::hash(self::dependencyShape($body['template_snapshot']??[]))!==self::hash(self::dependencyShape($old['template_snapshot']??[]));
        $prior=array_column((array)($old['roles']??[]),null,'role_id');$current=array_column((array)($body['roles']??[]),null,'role_id');$changed=[];
        foreach($current as $id=>$role)if(self::hash(self::dependencyShape($role))!==self::hash(self::dependencyShape($prior[$id]??[])))$changed[]=$id;
        $oldScenes=array_column((array)($old['scenes']??[]),null,'scene_id');$chain=false;
        foreach($body['scenes']??[] as $i=>$scene){
            $oldScene=$oldScenes[$scene['scene_id']]??[];$sceneChanged=self::hash(self::sceneContent($scene))!==self::hash(self::sceneContent($oldScene));
            $dirty=$script||$template||($product&&!empty($scene['product_visible']))||$sceneChanged||array_intersect($changed,(array)($scene['role_ids']??[]));
            if(($scene['mode']??'')==='start_end')$chain=$chain||(bool)$dirty;else $chain=false;
            $roles=[];foreach((array)($scene['role_ids']??[]) as $id)$roles[$id]=$current[$id]??[];
            $body['scenes'][$i]['dependency_hash']=self::hash(self::dependencyShape(['script'=>$body['script']??[],'template'=>$body['template_snapshot']??[],'product'=>!empty($scene['product_visible'])?($body['product_snapshot']??[]):[],'roles'=>$roles,'scene'=>self::sceneContent($scene)]));
            if($dirty||$chain){$body['scenes'][$i]['confirmed']=false;$body['scenes'][$i]['status']='stale';}
            elseif(!empty($scene['confirmed']))$body['scenes'][$i]['status']='ready';
            elseif(($oldScene['status']??'')!=='stale')$body['scenes'][$i]['status']='pending_review';
        }
        if($product||$template){$body['stage']='draft';$body['script_stale']=true;}elseif($script){$body['stage']=trim((string)($body['script']['text']??''))!==''?'script_ready':'draft';$body['script_stale']=false;}elseif($changed)$body['stage']=trim((string)($body['script']['text']??''))!==''?'script_ready':'draft';return $body;
    }
    public static function retryable(array $job): void
    {if($job['status']==='unknown'||($job['status']==='failed'&&(int)$job['consumption_id']>0&&!MicroVideoStore::json($job['receipt_json']??'')))throw new InvalidArgumentException('模型受理状态需先核对，请勿重复付费；重新创作需重新报价');if(!in_array($job['status'],['queued','failed'],true))throw new InvalidArgumentException('当前作业无需重试');}
    public static function brief(array $p): array
    {
        $goal=trim((string)($p['goal']??''));if($goal===''||mb_strlen($goal)>1000)throw new InvalidArgumentException('请输入1000字以内的创作目标');$duration=(int)($p['target_duration']??30);if($duration<4||$duration>600)throw new InvalidArgumentException('目标时长需在4至600秒');
        return ['brief'=>['goal'=>$goal,'output_type'=>mb_substr((string)($p['output_type']??'video'),0,40),'requirements'=>mb_substr((string)($p['requirements']??''),0,5000),'target_duration'=>$duration],'suggested_workflow'=>preg_match('/房|探盘/u',$goal)?'property':(preg_match('/商品|带货|产品/u',$goal)?'commerce':'story')];
    }
}
