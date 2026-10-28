<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;
use think\facade\Db;
final class CopywritingEventService
{
    public static function save(int $taskId,array $changes,string $type='snapshot'): void
    {
        Db::transaction(function()use($taskId,$changes,$type){$row=CopywritingStore::table('task')->where('id',$taskId)->lock(true)->find();if(!$row)return;if(in_array($row['status'],['success','failed','canceled'],true))return;$seq=(int)$row['last_seq']+1;$changes+=['last_seq'=>$seq,'update_time'=>time()];CopywritingStore::table('task')->where('id',$taskId)->update($changes);CopywritingStore::insert('task_event',['tenant_id'=>$row['tenant_id'],'task_id'=>$taskId,'seq'=>$seq,'event_type'=>$type,'payload'=>CopywritingStore::json(CopywritingTaskService::publicTask(array_replace($row,$changes)))]);});
    }
    public static function read(int $tenantId,int $userId,int $id,int $after): array
    {
        CopywritingTaskService::owned($tenantId,$userId,$id);
        return CopywritingStore::table('task_event')->where(['tenant_id'=>$tenantId,'task_id'=>$id])->where('seq','>',$after)->order('seq')->limit(100)->select()->toArray();
    }
}
