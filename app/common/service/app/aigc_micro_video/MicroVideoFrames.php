<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\point\PointService;
use think\facade\Db;
use InvalidArgumentException;

final class MicroVideoFrames
{
    public static function rows(int $task): array{return MicroVideoStore::table('frame')->where(['task_id'=>$task,'is_latest'=>1])->order('boundary_index')->select()->toArray();}
    public static function editable(array $task,array $p,bool $frameRevision=false): void
    {
        if($task['status']!=='awaiting_confirmation'||($task['frame_status']??'off')==='generating'||(int)$task['plan_revision']!==(int)($p['plan_revision']??-1)||($frameRevision&&(int)$task['frames_revision']!==(int)($p['frames_revision']??-1)))throw new InvalidArgumentException('方案或首尾帧正在生成或已变化，请刷新后操作');
    }
    public static function bump(array $task,array $extra=[]): void
    {
        MicroVideoStore::update((int)$task['id'],array_replace(['frames_revision'=>(int)$task['frames_revision']+1,'quote_token'=>'','quote_json'=>'{}','frame_quote_token'=>'','frame_quote_json'=>'{}','frame_quote_expires'=>0],$extra));
    }
    public static function configure(int $tenant,int $user,array $p): array
    {
        MicroVideoService::available($tenant);$id=(int)($p['id']??0);$task=MicroVideoStore::owned($tenant,$user,$id);self::editable($task,$p);$mode=(string)($p['generation_mode']??'');if(!in_array($mode,['reference','start_end'],true))throw new InvalidArgumentException('生成方式无效');
        $c=MicroVideoSettings::get($tenant);$params=MicroVideoStore::json($task['params_json']);$model=MicroVideoStore::json($task['model_snapshot']);
        if(empty(MicroVideoStore::json($task['config_snapshot'])['frame_feature_version']))throw new InvalidArgumentException('历史作品保留原参考图生成方式');
        $values=['generation_mode'=>$mode,'frame_status'=>$mode==='reference'?'off':'idle'];$definitions=[];
        if($mode==='start_end'){
            if(empty($c['frames_enabled'])||empty($model['supports_first_last_frame'])||empty($model['start_end_specs']))throw new InvalidArgumentException('当前模型暂不支持首尾帧');
            $frameModel=(string)($p['frame_model_id']??$task['frame_model_id']?:$c['default_frame_model_id']);MicroVideoPolicy::allowedModel($frameModel,$c['frame_model_ids']);$m=MicroVideoFrameCatalog::model($tenant,$frameModel);
            $quality=(string)($p['frame_quality']??$task['frame_quality']?:$c['default_frame_quality']);MicroVideoFrameCatalog::selection($m,$quality,$params['ratio']);
            $definitions=MicroVideoFramePolicy::definitions($task,MicroVideoStore::segments($id),(int)$m['max_reference_images']);$values+=['frame_model_id'=>$frameModel,'frame_quality'=>$quality];
        }
        Db::transaction(function()use($tenant,$user,$id,$p,$values,$definitions){$task=MicroVideoStore::owned($tenant,$user,$id,true);self::editable($task,$p);$rows=self::rows($id);$changed=$task['generation_mode']!==$values['generation_mode']||($values['generation_mode']==='start_end'&&($task['frame_model_id']!==$values['frame_model_id']||$task['frame_quality']!==$values['frame_quality']));if(!$changed&&$rows)return;
            if($values['generation_mode']==='start_end'){if(!$rows)foreach($definitions as $d)self::insert($task,$d,1);elseif($task['frame_model_id']!==$values['frame_model_id']||$task['frame_quality']!==$values['frame_quality'])self::invalidate($task,0,$definitions);self::bind($id);}
            if($changed)MicroVideoStore::table('frame')->where(['task_id'=>$id,'is_latest'=>1])->update(['confirmed'=>0,'update_time'=>time()]);
            self::bump($task,$values);
        });return MicroVideoService::detail($tenant,$user,$id);
    }
    private static function insert(array $task,array $definition,int $version,string $status='pending'): int
    {
        $version=max($version,1+(int)MicroVideoStore::table('frame')->where(['task_id'=>$task['id'],'boundary_index'=>$definition['boundary_index']])->max('version'));
        $refs=is_array($definition['reference_indices'])?$definition['reference_indices']:MicroVideoStore::json($definition['reference_indices']);$prompt=(string)$definition['prompt'];
        return MicroVideoStore::table('frame')->insertGetId(['tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'task_id'=>$task['id'],'boundary_index'=>$definition['boundary_index'],'version'=>$version,'is_latest'=>1,'prompt'=>$prompt,'reference_indices'=>MicroVideoStore::encode($refs),'prompt_fingerprint'=>MicroVideoPolicy::fingerprint([$prompt,$refs]),'source_fingerprint'=>MicroVideoPolicy::fingerprint(MicroVideoPolicy::stableParams(MicroVideoStore::json($task['params_json']))),'status'=>$status,'create_time'=>time(),'update_time'=>time()]);
    }
    public static function invalidate(array $task,int $from,?array $definitions=null): void
    {
        foreach(self::rows((int)$task['id']) as $row){$i=(int)$row['boundary_index'];if($i<$from)continue;MicroVideoStore::table('frame')->where('id',$row['id'])->update(['is_latest'=>null,'update_time'=>time()]);if($definitions!==null&&!isset($definitions[$i]))continue;self::insert($task,$definitions[$i]??$row,(int)$row['version']+1,'stale');}
        if($definitions!==null){$existing=array_column(self::rows((int)$task['id']),null,'boundary_index');foreach($definitions as $i=>$d)if(!isset($existing[$i]))self::insert($task,$d,1,'stale');}
        self::bind((int)$task['id']);
    }
    public static function bind(int $id): void
    {
        $segments=MicroVideoStore::segments($id);$frames=self::rows($id);if(!$frames)return;foreach(MicroVideoFramePolicy::links($segments,$frames) as $segment=>$links)MicroVideoStore::table('segment')->where('id',$segment)->update($links+['update_time'=>time()]);
    }
    public static function save(int $tenant,int $user,array $p): array
    {
        MicroVideoService::available($tenant);$id=(int)($p['id']??0);$task=MicroVideoStore::owned($tenant,$user,$id);self::editable($task,$p,true);$m=MicroVideoFrameCatalog::model($tenant,(string)$task['frame_model_id']);$params=MicroVideoStore::json($task['params_json']);
        $prompt=trim((string)($p['prompt']??''));if($prompt===''||mb_strlen($prompt)>20000)throw new InvalidArgumentException('请填写完整静态画面提示词，最多20000字');
        Db::transaction(function()use($tenant,$user,$id,$p,$prompt,$m,$params){$task=MicroVideoStore::owned($tenant,$user,$id,true);self::editable($task,$p,true);$frame=MicroVideoStore::table('frame')->where(['id'=>(int)($p['frame_id']??0),'task_id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'is_latest'=>1])->find();if(!$frame)throw new InvalidArgumentException('首尾帧不存在或版本已变化');
            $refs=MicroVideoFramePolicy::references((array)($p['reference_indices']??[]),count($params['images']),(int)$m['max_reference_images'],(int)$frame['boundary_index']>0);if($frame['prompt']===$prompt&&MicroVideoStore::json($frame['reference_indices'])===$refs)return;
            $defs=self::rows($id);$defs[(int)$frame['boundary_index']]['prompt']=$prompt;$defs[(int)$frame['boundary_index']]['reference_indices']=$refs;self::invalidate($task,(int)$frame['boundary_index'],$defs);self::bump($task,['frame_status'=>'review']);
        });return MicroVideoService::detail($tenant,$user,$id);
    }
    public static function selected(array $frames,?array $ids): array
    {
        if($ids===null)$selected=array_values(array_filter($frames,fn($f)=>in_array($f['status'],['pending','stale','failed'],true)));
        else{$ids=array_values(array_unique(array_map('intval',$ids)));$selected=array_values(array_filter($frames,fn($f)=>in_array((int)$f['id'],$ids,true)));if(count($selected)!==count($ids))throw new InvalidArgumentException('首尾帧不存在或版本已变化');}
        if(!$selected)throw new InvalidArgumentException('没有需要生成的首尾帧');foreach($selected as $f)if($f['status']==='running')throw new InvalidArgumentException('图片结果待核对，请稍后查看');return $selected;
    }
    private static function quote(array $task,?array $ids): array
    {
        $tenant=(int)$task['tenant_id'];$c=MicroVideoSettings::get($tenant);if(empty($c['frames_enabled'])||$task['generation_mode']!=='start_end')throw new InvalidArgumentException('请先启用首尾帧方式');MicroVideoPolicy::allowedModel($task['frame_model_id'],$c['frame_model_ids']);$m=MicroVideoFrameCatalog::model($tenant,$task['frame_model_id']);$params=MicroVideoStore::json($task['params_json']);$selection=MicroVideoFrameCatalog::selection($m,$task['frame_quality'],$params['ratio']);$q=MicroVideoFrameCatalog::quote($tenant,$selection);$frames=self::rows((int)$task['id']);$selected=self::selected($frames,$ids);$byIndex=array_column($frames,null,'boundary_index');$selectedIds=array_map('intval',array_column($selected,'id'));foreach($selected as $f){$i=(int)$f['boundary_index'];if($i>0&&($byIndex[$i-1]['status']??'')!=='success'&&!in_array((int)($byIndex[$i-1]['id']??0),$selectedIds,true))throw new InvalidArgumentException('请同时选择尚未完成的前序连续性锚图');}
        $minimum=min(array_column($selected,'boundary_index'));$selectedIndices=array_map('intval',array_column($selected,'boundary_index'));foreach($selected as $f)for($i=(int)$minimum;$i<(int)$f['boundary_index'];$i++)if(!in_array($i,$selectedIndices,true))throw new InvalidArgumentException('生成多张帧图时请包含它们之间的连续性锚图');
        foreach($selected as $f)MicroVideoFramePolicy::references(MicroVideoStore::json($f['reference_indices']),count($params['images']),(int)$m['max_reference_images'],(int)$f['boundary_index']>0);
        return ['identity'=>MicroVideoFramePolicy::identity($task,$frames),'ids'=>array_map('intval',array_column($selected,'id')),'selection'=>$selection,'per_frame'=>$q,'frame_count'=>count($selected),'user_charge_points'=>round(count($selected)*$q['user_charge_points'],4),'tenant_cost_points'=>round(count($selected)*$q['tenant_cost_points'],4)];
    }
    public static function estimate(int $tenant,int $user,array $p): array
    {
        MicroVideoService::available($tenant);$id=(int)($p['id']??0);$task=MicroVideoStore::owned($tenant,$user,$id);self::editable($task,$p);$q=self::quote($task,array_key_exists('frame_ids',$p)?(array)$p['frame_ids']:null);$token=bin2hex(random_bytes(24));
        Db::transaction(function()use($tenant,$user,$id,$p,$q,$token){$task=MicroVideoStore::owned($tenant,$user,$id,true);self::editable($task,$p);if($q['identity']!==MicroVideoFramePolicy::identity($task,self::rows($id)))throw new InvalidArgumentException('首尾帧已变化，请重新估价');MicroVideoStore::update($id,['frame_quote_json'=>MicroVideoStore::encode($q),'frame_quote_token'=>$token,'frame_quote_expires'=>time()+600]);});
        return ['user_charge_points'=>$q['user_charge_points'],'frame_count'=>$q['frame_count'],'frames_revision'=>(int)$task['frames_revision'],'quote_token'=>$token,'approximate'=>false];
    }
    public static function generate(int $tenant,int $user,array $p): array
    {
        $id=(int)($p['id']??0);$key=trim((string)($p['idempotency_key']??''));if(!preg_match('/^[a-zA-Z0-9_.:-]{8,90}$/D',$key))throw new InvalidArgumentException('请求标识无效');$hash=MicroVideoPolicy::fingerprint(array_intersect_key($p,array_flip(['id','plan_revision','frames_revision','quote_token','frame_ids'])));
        $task=MicroVideoStore::owned($tenant,$user,$id);$batch=MicroVideoStore::table('frame_batch')->where(['task_id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();if($batch){if(!hash_equals($batch['request_hash'],$hash))throw new InvalidArgumentException('请求标识已用于其他图片生成');return MicroVideoService::detail($tenant,$user,$id);}
        MicroVideoService::available($tenant);self::editable($task,$p,true);$q=self::quote($task,array_key_exists('frame_ids',$p)?(array)$p['frame_ids']:null);self::assertQuote($task,$p,$q);PointService::assertCanConsumeAmounts($tenant,$user,$q['tenant_cost_points'],$q['user_charge_points']);
        Db::transaction(function()use($tenant,$user,$id,$p,$q,$key,$hash){$task=MicroVideoStore::owned($tenant,$user,$id,true);$old=MicroVideoStore::table('frame_batch')->where(['task_id'=>$id,'idempotency_key'=>$key])->find();if($old){if($old['request_hash']!==$hash)throw new InvalidArgumentException('请求标识已被使用');return;}self::editable($task,$p,true);self::assertQuote($task,$p,$q);
            $frames=self::rows($id);$selected=self::selected($frames,$q['ids']);$first=min(array_column($selected,'boundary_index'));
            // A new anchor changes only itself and later dependent boundary versions.
            foreach($frames as $f){if((int)$f['boundary_index']<(int)$first)continue;$isSelected=in_array((int)$f['id'],$q['ids'],true);if($f['status']==='success'||(int)$f['attempt_id']>0||(!$isSelected&&$f['status']!=='stale')){MicroVideoStore::table('frame')->where('id',$f['id'])->update(['is_latest'=>null,'update_time'=>time()]);self::insert($task,$f,(int)$f['version']+1,$isSelected?'pending':'stale');}}
            $current=self::rows($id);$indices=array_map('intval',array_column($selected,'boundary_index'));$admitted=array_values(array_filter($current,fn($f)=>in_array((int)$f['boundary_index'],$indices,true)));$admittedIds=array_map('intval',array_column($admitted,'id'));MicroVideoStore::table('frame')->whereIn('id',$admittedIds)->update(['status'=>'pending','confirmed'=>0,'error'=>'','update_time'=>time()]);$frozen=$q;$frozen['frames_revision']=(int)$task['frames_revision']+1;
            $batchId=MicroVideoStore::table('frame_batch')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$id,'idempotency_key'=>$key,'request_hash'=>$hash,'quote_json'=>MicroVideoStore::encode($frozen),'frame_ids'=>MicroVideoStore::encode($admittedIds),'status'=>'pending','create_time'=>time(),'update_time'=>time()]);
            self::bind($id);self::bump($task,['frame_status'=>'generating','frame_batch_id'=>$batchId,'next_run_at'=>time()]);
        });return MicroVideoService::detail($tenant,$user,$id);
    }
    private static function assertQuote(array $task,array $p,array $q): void
    {
        if($task['frame_quote_token']===''||!hash_equals($task['frame_quote_token'],(string)($p['quote_token']??''))||(int)$task['frame_quote_expires']<time()||MicroVideoPolicy::fingerprint(MicroVideoStore::json($task['frame_quote_json']))!==MicroVideoPolicy::fingerprint($q)||$q['identity']!==MicroVideoFramePolicy::identity($task,self::rows((int)$task['id'])))throw new InvalidArgumentException('首尾帧或报价已变化，请重新估价');
    }
    public static function confirm(int $tenant,int $user,array $p): array
    {
        $id=(int)($p['id']??0);MicroVideoService::available($tenant);Db::transaction(function()use($tenant,$user,$id,$p){$task=MicroVideoStore::owned($tenant,$user,$id,true);self::editable($task,$p,true);if($task['generation_mode']!=='start_end')throw new InvalidArgumentException('请先启用首尾帧方式');$frames=self::rows($id);$ready=array_map(fn($f)=>array_replace($f,['confirmed'=>1]),$frames);MicroVideoFramePolicy::ready($task,$ready,count(MicroVideoStore::segments($id)));MicroVideoStore::table('frame')->where(['task_id'=>$id,'is_latest'=>1])->update(['confirmed'=>1,'update_time'=>time()]);self::bump($task,['frame_status'=>'ready']);});return MicroVideoService::detail($tenant,$user,$id);
    }
    public static function ready(array $task): array
    {
        $frames=MicroVideoFramePolicy::ready($task,self::rows((int)$task['id']),count(MicroVideoStore::segments((int)$task['id'])));foreach($frames as $f)MicroVideoMedia::validateImages((int)$task['tenant_id'],(int)$task['user_id'],[['uri'=>$f['image_uri']]],1);return $frames;
    }
    public static function dto(array $task,bool $admin=false): array
    {
        $rows=[];foreach(self::rows((int)$task['id']) as $f){$url='';if($f['status']==='success'&&$f['image_uri']!=='')try{$url=MicroVideoMedia::validateImages((int)$task['tenant_id'],(int)$task['user_id'],[['uri'=>$f['image_uri']]],1)[0]['url'];}catch(\Throwable){}$row=['id'=>(int)$f['id'],'boundary_index'=>(int)$f['boundary_index'],'version'=>(int)$f['version'],'prompt'=>$f['prompt'],'reference_indices'=>MicroVideoStore::json($f['reference_indices']),'status'=>$f['status'],'image_url'=>$url,'confirmed'=>(bool)$f['confirmed'],'user_charge_points'=>(float)$f['user_charge_points']];if($admin)$row+=array_intersect_key($f,array_flip(['attempt_id','error','image_uri','dependency_fingerprint']));$rows[]=$row;}return ['generation_mode'=>$task['generation_mode']??'reference','frame_status'=>$task['frame_status']??'off','frame_model_id'=>$task['frame_model_id']??'','frame_quality'=>$task['frame_quality']??'','frames_revision'=>(int)($task['frames_revision']??0),'frames'=>$rows];
    }
}
