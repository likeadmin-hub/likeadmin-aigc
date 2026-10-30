<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\power\MarketTextModelRuntimeService as Text;
use app\common\service\power\MarketImageModelRuntimeService as Image;
use think\facade\Db;
use RuntimeException;
use InvalidArgumentException;

/** One durable preparation job per tick. Lease plus process flock fence slow HTTP. */
final class MicroVideoWorkbenchWorker
{
    public static function available(): bool
    {
        static $checkedAt=0,$ready=false;if(time()-$checkedAt<30)return $ready;$checkedAt=time();
        try{$prefix=(string)config('database.connections.mysql.prefix','la_');$rows=Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.'aigc_micro_video_workbench_job']);$ready=(int)$rows[0]['n']===1;}catch(\Throwable){$ready=false;}return $ready;
    }
    public static function tick(int $id=0): int
    {
        if(!self::available())return 0;
        $q=MicroVideoWorkbenchJobs::table()->whereIn('status',['queued','running','unknown'])->where('next_run_at','<=',time())->where('lease_until','<',time());if($id)$q->where('id',$id);$rows=$q->order('id')->limit(10)->select()->toArray();
        foreach($rows as $row){$lock=fopen(runtime_path().'micro_workbench_job_'.$row['id'].'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);continue;}$token=bin2hex(random_bytes(16));
            try{$claimed=MicroVideoWorkbenchJobs::table()->where('id',$row['id'])->where('lease_until','<',time())->update(['lease_token'=>$token,'lease_until'=>time()+300,'update_time'=>time()]);if(!$claimed)continue;
                $row=MicroVideoWorkbenchJobs::table()->where('id',$row['id'])->find();self::run($row);return 1;
            }finally{MicroVideoWorkbenchJobs::table()->where(['id'=>$row['id'],'lease_token'=>$token])->update(['lease_token'=>'','lease_until'=>0]);flock($lock,LOCK_UN);fclose($lock);}
        }return 0;
    }
    private static function write(int $id,array $data): void
    {MicroVideoWorkbenchJobs::table()->where('id',$id)->update($data+['update_time'=>time()]);}
    public static function reconcile(array $row): void
    {
        $lock=fopen(runtime_path().'micro_workbench_job_'.$row['id'].'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return;}
        try{if(in_array($row['status'],['success'],true))return;if($row['status']==='queued'&&(int)$row['consumption_id']===0&&!MicroVideoStore::json($row['receipt_json']))return;self::run($row,true);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function consumption(array $row): ?array
    {
        if((int)$row['consumption_id']===0){$a=Db::name('ai_app_task')->where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'app_code'=>MicroVideoStore::APP,'business_table'=>'aigc_micro_video_workbench_job','business_id'=>$row['id']])->order('id','desc')->find();if($a){$c=Db::name('ai_consumption_log')->where(['app_task_id'=>$a['id'],'tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']])->order('id','desc')->find();if($c){self::write((int)$row['id'],['app_task_id'=>$a['id'],'consumption_id'=>$c['id']]);return $c;}}return null;}
        $c=Db::name('ai_consumption_log')->where(['id'=>$row['consumption_id'],'tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'app_code'=>MicroVideoStore::APP])->find();if(!$c)throw new RuntimeException('准备作业计费关联缺失');return $c;
    }
    private static function run(array $row,bool $reconcileOnly=false): void
    {
        $id=(int)$row['id'];$tenant=(int)$row['tenant_id'];$user=(int)$row['user_id'];$request=MicroVideoStore::json($row['request_json']);$image=str_ends_with($row['action'],'_image');
        try{
            $c=self::consumption($row);$receipt=MicroVideoStore::json($row['receipt_json']);
            if($receipt){if(!$c||$c['billing_status']!=='settled'){self::write($id,['status'=>'unknown','error'=>'结果已保留，等待公共运行时核对实际用量和结算','next_run_at'=>time()+30]);return;}self::finish($row,$receipt,$c);return;}
            if($c){
                if(in_array($c['run_status'],['failed','canceled'],true)){self::write($id,['status'=>'failed','error'=>'共享运行时已确认本次未完成，请重新报价后创作','user_charge_points'=>(float)$c['actual_user_price']]);return;}
                if(!$image){self::write($id,['status'=>'unknown','error'=>'文字请求受理状态待核对，不会再次调用','next_run_at'=>time()+30]);return;}
                // A reserved image has not crossed outbound admission. The shared runtime claims before HTTP.
                if($c['run_status']==='reserved'&&$reconcileOnly)return;
                $result=$c['run_status']==='reserved'?Image::submit((int)$c['id'],self::imageRequest($request)):Image::refresh((int)$c['id']);
                self::imageResult($row,$result);return;
            }
            if($row['status']!=='queued'||$reconcileOnly){self::write($id,['status'=>'unknown','error'=>'作业中断，需核对受理记录，不会自动重新付费','next_run_at'=>time()+30]);return;}
            MicroVideoService::available($tenant);
            // Revalidate selected price immediately before public runtime admission. No DB lock waits on I/O.
            if($image){$freshQuote=MicroVideoFrameCatalog::quote($tenant,$request['selection']);$freshQuote['approximate']=false;}
            else{$m=Text::resolveModel($tenant,$request['selection']['model_id']);$freshQuote=$request['quote'];$freshQuote['price_identity']=['model_id'=>$m['id'],'input'=>$m['input'],'output'=>$m['output']];}
            if(MicroVideoWorkbenchPolicy::hash($freshQuote)!==MicroVideoWorkbenchPolicy::hash($request['quote']))throw new InvalidArgumentException('模型价格已变化，请重新估价');
            self::write($id,['status'=>'running','error'=>'']);
            if($image){
                $r=Db::transaction(function()use($id,$tenant,$user,$request){$job=MicroVideoWorkbenchJobs::table()->where('id',$id)->lock(true)->find();if((int)$job['consumption_id']>0)return ['consumption_id'=>$job['consumption_id']];$reserve=Image::reserve($tenant,$user,'workbench_'.$job['action'],(string)$id,$request['selection'],self::imageRequest($request),1,MicroVideoStore::APP,'aigc_micro_video_workbench_job');self::write($id,['consumption_id'=>$reserve['consumption_id'],'app_task_id'=>$reserve['app_task_id']]);return $reserve;});
                self::imageResult($row,Image::submit((int)$r['consumption_id'],self::imageRequest($request)));return;
            }
            $streamRaw='';$lastStreamAt=0.;
            $result=Text::generate($tenant,$user,['content'=>$request['content'],'system_prompt'=>$request['system'],'model_id'=>$request['selection']['model_id'],'source_app_code'=>MicroVideoStore::APP,'business_table'=>'aigc_micro_video_workbench_job','business_id'=>$id,'action_code'=>'workbench_'.$row['action'],'_disable_model_fallback'=>true,'_disable_transient_retry'=>true,'_disable_compatibility_retry'=>true,'_require_final_content'=>true,'_micro_video_preserve_uncertain'=>true,'_micro_video_native_transport'=>true,'request_timeout_seconds'=>180,'model_config'=>['max_tokens'=>8000],
                '_result_validator'=>static function(array $result)use($id){self::write($id,['receipt_json'=>MicroVideoStore::encode($result)]);}],
                static function($event,$data)use($id,$row,$request,&$streamRaw,&$lastStreamAt){
                    if($event==='app_task')self::write($id,['app_task_id'=>(int)$data['app_task_id'],'consumption_id'=>(int)$data['consumption_id']]);
                    if($event==='delta'&&$row['action']==='rewrite'){
                        $streamRaw.=(string)($data['delta']??'');
                        if(microtime(true)-$lastStreamAt>=0.2){$lastStreamAt=microtime(true);self::write($id,['result_json'=>MicroVideoStore::encode(['stream_text'=>MicroVideoScriptStream::preview($streamRaw,$request)])]);}
                    }
                });
            self::write($id,['receipt_json'=>MicroVideoStore::encode($result)]);$fresh=MicroVideoWorkbenchJobs::table()->where('id',$id)->find();$c=self::consumption($fresh);if(!$c||$c['billing_status']!=='settled'){self::write($id,['status'=>'unknown','error'=>'已返回结果，等待实际用量结算','next_run_at'=>time()+30]);return;}self::finish($row,$result,$c);
        }catch(\Throwable $e){
            if($e instanceof \app\common\service\power\TextSettlementPending)self::write($id,['receipt_json'=>MicroVideoStore::encode($e->result)]);
            $fresh=MicroVideoWorkbenchJobs::table()->where('id',$id)->find();$c=self::consumption($fresh);$receipt=MicroVideoStore::json($fresh['receipt_json']);$known=(!$c||in_array($c['run_status'],['failed','canceled'],true)||($receipt&&$c['billing_status']==='settled'));
            self::write($id,['status'=>$known?'failed':'unknown','error'=>mb_substr($e->getMessage(),0,1000),'next_run_at'=>time()+30,'user_charge_points'=>(float)($c['actual_user_price']??0)]);\think\facade\Log::warning('Micro workbench job '.$id.': '.get_class($e));
        }
    }
    private static function imageRequest(array $r): array
    {return $r['selection']+['quantity'=>1,'prompt'=>$r['prompt'],'reference_images'=>MicroVideoMedia::visionInputs($r['images'])];}
    private static function imageResult(array $row,array $result): void
    {
        $fresh=MicroVideoWorkbenchJobs::table()->where('id',$row['id'])->find();$c=self::consumption($fresh);
        if(!empty($result['images'])){self::write((int)$row['id'],['receipt_json'=>MicroVideoStore::encode($result)]);if($c&&$c['billing_status']==='settled'){self::finish($row,$result,$c);return;}}
        self::write((int)$row['id'],['status'=>$c&&MicroVideoSubmission::pending($c)?'unknown':'running','next_run_at'=>time()+15]);
    }
    private static function finish(array $row,array $receipt,array $c): void
    {
        $result=self::parse($row,$receipt);self::write((int)$row['id'],['status'=>'success','result_json'=>MicroVideoStore::encode($result),'user_charge_points'=>(float)$c['actual_user_price'],'error'=>'']);
    }
    public static function parse(array $row,array $receipt): array
    {
        if(str_ends_with($row['action'],'_image')){$images=[];foreach((array)($receipt['images']??[]) as $img)$images[]=['uri'=>(string)($img['image_uri']??$img['uri']??'')];if(!$images)throw new InvalidArgumentException('图片结果尚未入库');return ['images'=>MicroVideoMedia::validateImages((int)$row['tenant_id'],(int)$row['user_id'],$images,1)];}
        $text=trim((string)($receipt['content']??''));$text=preg_replace('/^```(?:json)?\s*|\s*```$/u','',$text);$result=self::decodeContent($text);if(!is_array($result))throw new InvalidArgumentException('模型结果不是有效结构');
        $request=MicroVideoStore::json($row['request_json']);
        MicroVideoWorkbenchRecords::assertNoPrivateEcho($result,$request);
        $result=MicroVideoWorkbenchRecords::publicBody($result);
        if($row['action']==='rewrite')return ['script'=>self::parseScript($row,$result)];
        $field=$row['action']==='analyze_roles'?'roles':'scenes';if(!isset($result[$field])||!is_array($result[$field]))throw new InvalidArgumentException('分析结果缺少角色或分镜');
        foreach($result[$field] as &$item){if(!is_array($item))throw new InvalidArgumentException('分析结果项目格式无效');$item['images']=[];if($field==='scenes')$item['confirmed']=false;}unset($item);
        $request=MicroVideoStore::json($row['request_json']);$body=$request['snapshot']??[];
        if($field==='roles'){
            $old=(array)($body['roles']??[]);$byId=array_column($old,null,'role_id');$byName=[];foreach($old as $role){$name=trim((string)($role['name']??''));if($name!=='')$byName[$name]=$role;}$used=[];
            foreach($result['roles'] as &$role){$prior=$byId[$role['role_id']??'']??$byName[trim((string)($role['name']??''))]??null;if($prior){$role=array_replace($prior,$role,['role_id'=>$prior['role_id'],'images'=>$prior['images']??[]]);if(isset($prior['voice_ref']))$role['voice_ref']=$prior['voice_ref'];}$used[$role['role_id']??'']=true;}unset($role);
            foreach($old as $role)if(!isset($used[$role['role_id']]))$result['roles'][]=$role;
        }
        $body[$field]=$result[$field];$body=MicroVideoWorkbenchPolicy::body('project',$body);return [$field=>$body[$field]];
    }
    /** Recover exactly one unmatched closing delimiter; never change string values or invent content. */
    private static function decodeContent(string $text)
    {
        try { return json_decode($text, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $original) {
            $stack = [];
            $quoted = false;
            $escaped = false;
            for ($i = 0, $length = strlen($text); $i < $length; $i++) {
                $char = $text[$i];
                if ($quoted) {
                    if ($escaped) $escaped = false;
                    elseif ($char === '\\') $escaped = true;
                    elseif ($char === '"') $quoted = false;
                    continue;
                }
                if ($char === '"') { $quoted = true; continue; }
                if ($char === '{' || $char === '[') { $stack[] = $char; continue; }
                if ($char !== '}' && $char !== ']') continue;
                $expected = $char === '}' ? '{' : '[';
                if ($stack && end($stack) === $expected) { array_pop($stack); continue; }
                // Only this first mismatched closer can be removed. Strict decoding of the
                // complete candidate rejects truncation, extra prose and any second defect.
                $candidate = substr($text, 0, $i).substr($text, $i + 1);
                try { return json_decode($candidate, true, 512, JSON_THROW_ON_ERROR); }
                catch (\JsonException $unrecoverable) { throw $original; }
            }
            throw $original;
        }
    }
    /** Reprocess the durable receipt locally; never call or charge a model while validating content. */
    private static function parseScript(array $row,array $result): array
    {
        $script=$result['script']??null;
        if(!is_array($script)||!is_string($script['text']??null)||trim($script['text'])===''||empty($script['segments'])||!is_array($script['segments'])||count($script['segments'])>60)throw new InvalidArgumentException('改写结果缺少完整剧本或分镜');
        $input=MicroVideoStore::json($row['request_json']);$snapshot=(array)($input['snapshot']??[]);
        $script=MicroVideoGeneratedReferences::script($script,$snapshot);$imageCount=count((array)($snapshot['images']??[]));
        $continuity='';
        foreach([$script['continuity']??'', $result['continuity']??'', $input['continuity']??'', $snapshot['script']['continuity']??'', $snapshot['template_snapshot']['body']['reference_rules']??'', MicroVideoSettings::defaults()['continuity_template']] as $candidate){if(is_string($candidate)&&trim($candidate)!==''){$continuity=trim($candidate);break;}}
        if(mb_strlen($continuity)>10000)throw new InvalidArgumentException('整片一致性设定过长');$script['continuity']=$continuity;
        // This is authoring only: an empty binding is valid until executionReferences resolves owned scene assets.
        $sum=0;foreach($script['segments'] as $segment){$duration=(int)($segment['duration']??0);if($duration<1||$duration>120)throw new InvalidArgumentException('分镜时长无效');MicroVideoTimeline::validate((string)($segment['prompt']??''),$duration);$sum+=$duration;}
        if(!empty($input['planning_contract'])){
            // The real SKU contract owns rounding/minimum-duration bounds; a fixed +15s rule can reject a valid 30s-only SKU.
            $script['segments']=MicroVideoContentPlanning::validate(['segments'=>$script['segments'],'continuity'=>$continuity],$input['planning_contract'],$imageCount,(int)$input['video_reference_limit'],true,true);
        }else{
            $script['segments']=MicroVideoPolicy::segments($script['segments'],array_values(array_unique(array_column($script['segments'],'duration'))),$imageCount,15,60,true,true);
            $target=(int)($snapshot['target_duration']??$snapshot['brief']['target_duration']??30);
            if($sum<$target||$sum>$target+15)throw new InvalidArgumentException('改写剧本未覆盖目标时长，请查看保留的结果后重新规划');
        }
        return $script;
    }
}
