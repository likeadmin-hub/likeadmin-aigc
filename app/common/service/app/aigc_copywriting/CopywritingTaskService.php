<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;
use think\facade\Db;
use app\common\service\power\TextModelExecutionPolicy;
final class CopywritingTaskService
{
    public static function create(int $tenantId,int $userId,array $input): array
    {
        if($tenantId<=0||$userId<=0)throw new \InvalidArgumentException('请登录后使用');
        $key=(string)($input['idempotency_key']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{16,100}$/D',$key))throw new \InvalidArgumentException('请求标识不正确');
        $shape=array_intersect_key($input,array_flip(['tool_id','version_id','values','model_selection','source_task_id']));
        $hash=self::requestHash($shape);
        $where=['tenant_id'=>$tenantId,'user_id'=>$userId,'idempotency_key'=>$key];
        $old=CopywritingStore::table('task')->where($where)->find();
        if($old)return self::sameRequest($old,$hash);
        $tool=CopywritingStore::owned('tool',$tenantId,(int)($input['tool_id']??0));
        if(!$tool['status'])throw new \InvalidArgumentException('功能已下架');
        $page=CopywritingStore::table('page')->where(['tenant_id'=>$tenantId,'tool_id'=>$tool['id']])->find();
        if(!$page||!$page['published_version_id']||(int)$page['published_version_id']!==(int)($input['version_id']??0))throw new \InvalidArgumentException('VERSION_CHANGED');
        $version=CopywritingStore::owned('version',$tenantId,(int)$page['published_version_id']);
        $document=CopywritingStore::decode($version['document_json']);
        $source=(int)($input['source_task_id']??0);
        if($source){$original=self::owned($tenantId,$userId,$source);if((int)$original['tool_id']!==(int)$tool['id'])throw new \InvalidArgumentException('原任务不属于当前功能');if(in_array($original['status'],['pending','running'],true))throw new \InvalidArgumentException('请先等待或停止当前生成');}
        $prompt=CopywritingPromptService::render($document,(array)($input['values']??[]));
        $model=CopywritingModelService::resolve($tenantId,$document,$input['model_selection']??'');
        $data=$where+['tool_id'=>$tool['id'],'version_id'=>$version['id'],'source_task_id'=>$source,'request_hash'=>$hash,'input_snapshot'=>CopywritingStore::json($prompt['normalized_values']),'prompt_snapshot'=>CopywritingStore::json($prompt),'model_snapshot'=>CopywritingStore::json($model),'execution_fingerprint'=>TextModelExecutionPolicy::fingerprint($model),'result_text'=>''];
        try{$id=CopywritingStore::insert('task',$data);}catch(\Throwable $e){$old=CopywritingStore::table('task')->where($where)->find();if($old)return self::sameRequest($old,$hash);throw $e;}
        return self::detail($tenantId,$userId,$id);
    }
    public static function requestHash(array $input): string
    {
        $shape=['tool_id'=>(int)($input['tool_id']??0),'version_id'=>(int)($input['version_id']??0),'values'=>(array)($input['values']??[]),'model_selection'=>(string)($input['model_selection']??''),'source_task_id'=>(int)($input['source_task_id']??0)];
        $sort=function(&$v)use(&$sort){if(!is_array($v))return;if(!array_is_list($v))ksort($v);foreach($v as &$item)$sort($item);};
        $sort($shape);return hash('sha256',CopywritingStore::json($shape));
    }
    private static function sameRequest(array $old,string $hash): array
    {
        if(!hash_equals($old['request_hash'],$hash))throw new \RuntimeException('同一请求标识的内容已变化，请重新提交',409);
        return self::publicTask($old);
    }
    public static function owned(int $tenantId,int $userId,int $id): array
    {
        $row=CopywritingStore::table('task')->where(['tenant_id'=>$tenantId,'user_id'=>$userId,'id'=>$id])->find();
        if(!$row)throw new \InvalidArgumentException('任务不存在或无权访问');return $row;
    }
    public static function publicTask(array $row): array
    {
        $public=array_intersect_key($row,array_flip(['id','tool_id','version_id','source_task_id','status','submission_state','billing_state','result_text','error','cancel_requested','last_seq','started_at','finished_at','create_time','provider_request_id']));
        $public['values']=CopywritingStore::decode($row['input_snapshot']);
        $public['model']=CopywritingModelService::publicModel(CopywritingStore::decode($row['model_snapshot']));
        $version=CopywritingStore::owned('version',(int)$row['tenant_id'],(int)$row['version_id']);
        $d=CopywritingStore::decode($version['document_json']);$public['result_mode']=$d['result']['mode']??'article';
        return $public;
    }
    public static function detail(int $tenantId,int $userId,int $id): array{return self::publicTask(self::owned($tenantId,$userId,$id));}
    public static function lists(int $tenantId,int $userId,int $page=1,bool $admin=false): array
    {
        $q=CopywritingStore::table('task')->where('tenant_id',$tenantId);if(!$admin)$q->where('user_id',$userId);
        $count=(clone $q)->count();$rows=$q->order('id','desc')->page(max(1,$page),20)->select()->toArray();
        return ['lists'=>array_map([self::class,'publicTask'],$rows),'count'=>$count];
    }
    public static function cancel(int $tenantId,int $userId,int $id): array
    {
        self::owned($tenantId,$userId,$id);
        Db::transaction(function()use($tenantId,$userId,$id){$row=CopywritingStore::table('task')->where(['id'=>$id,'tenant_id'=>$tenantId,'user_id'=>$userId])->lock(true)->find();if(!in_array($row['status'],['pending','running'],true))return;$update=['cancel_requested'=>1];if($row['status']==='pending')$update+=['status'=>'canceled','finished_at'=>time()];CopywritingStore::table('task')->where('id',$id)->update($update);});
        return self::detail($tenantId,$userId,$id);
    }
}
