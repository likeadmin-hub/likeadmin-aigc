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
    public static function body(string $kind,array $body): array
    {
        self::kind($kind);if(strlen(MicroVideoStore::encode($body))>262144)throw new InvalidArgumentException('创作资料过长，请减少内容');
        if($kind==='project'){
            $stage=(string)($body['stage']??'draft');if(!in_array($stage,['draft','script_ready','assets_ready','ready'],true))throw new InvalidArgumentException('项目阶段无效');$body['stage']=$stage;
            $ids=[];foreach((array)($body['roles']??[]) as $role){$id=(string)($role['role_id']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id)||isset($ids[$id]))throw new InvalidArgumentException('角色标识缺失或重复');$ids[$id]=true;}
            $scenes=[];$sorts=[];$segmentCount=count((array)($body['script']['segments']??[]));foreach((array)($body['scenes']??[]) as $scene){$sort=filter_var($scene['sort']??null,FILTER_VALIDATE_INT);if($sort===false||$sort<1||$sort>60||isset($sorts[$sort])||($segmentCount>0&&$sort>$segmentCount))throw new InvalidArgumentException('分镜段落序号须唯一并对应现有剧本段落');$sorts[$sort]=true;$id=(string)($scene['scene_id']??'');if($id===''||isset($scenes[$id]))throw new InvalidArgumentException('分镜标识缺失或重复');$scenes[$id]=true;foreach((array)($scene['role_ids']??[]) as $rid)if(!isset($ids[$rid]))throw new InvalidArgumentException('分镜引用了不存在的角色');}
            if(count($ids)>60||count($scenes)>60)throw new InvalidArgumentException('角色或分镜数量超过限制');
        }
        return $body;
    }
    public static function dependencies(array $body,array $old): array
    {
        $script=self::hash($body['script']??[])!==self::hash($old['script']??[]);
        $product=self::hash($body['product_snapshot']??[])!==self::hash($old['product_snapshot']??[]);
        $template=self::hash($body['template_snapshot']??[])!==self::hash($old['template_snapshot']??[]);
        $prior=array_column((array)($old['roles']??[]),null,'role_id');$changed=[];
        foreach((array)($body['roles']??[]) as $r)if(self::hash($r)!==self::hash($prior[$r['role_id']]??[]))$changed[]=$r['role_id'];
        $chain=false;foreach($body['scenes']??[] as $i=>$s){$dirty=$script||$template||($product&&!empty($s['product_visible']))||array_intersect($changed,(array)($s['role_ids']??[]));if(($s['mode']??'')==='start_end')$chain=$chain||(bool)$dirty;else $chain=false;
            if($dirty||$chain){$body['scenes'][$i]['confirmed']=false;$body['scenes'][$i]['status']='stale';}}
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
