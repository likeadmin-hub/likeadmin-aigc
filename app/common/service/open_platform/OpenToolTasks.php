<?php
namespace app\common\service\open_platform;
use app\common\model\ai\AiConsumptionLog;
final class OpenToolTasks
{
    public static function encode(string $app,int|string $native): string {return 'ot_'.$app.'_'.$native;}
    public static function decode(string $app,string $id): string
    {
        $prefix='ot_'.$app.'_';if(!str_starts_with($id,$prefix)||!preg_match('/^[1-9][0-9]*$/D',substr($id,strlen($prefix))))throw new OpenException('invalid_task_id','任务 ID 与当前工具不匹配',404);return substr($id,strlen($prefix));
    }
    public static function publicResult(string $app,array $op,array $data): array
    {
        if(isset($data['task_id'])&&is_numeric($data['task_id']))$data['task_id']=self::encode($app,(string)$data['task_id']);
        if(($op['route']??'')==='GET tasks/{task_id}'&&isset($data['id']))$data['task_id']=self::encode($app,(string)$data['id']);
        if(($op['route']??'')==='GET tasks'&&isset($data['lists']))foreach($data['lists'] as &$item)if(isset($item['id']))$item['task_id']=self::encode($app,(string)$item['id']);
        return OpenPolicy::publicData($data);
    }
    public static function billing(array $row): string
    {
        $ids=OpenStore::json($row['consumption_ids']);if(!$ids){if($row['status']==='pending_confirmation')return 'pending_confirmation';if(in_array($row['status'],['accepted','running','queued','submitting','pending'],true)&&$row['billing_status']!=='none')return 'pending';return (float)($row['actual_user']??0)>0?'settled':'none';}
        $logs=AiConsumptionLog::where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']])->whereIn('id',$ids)->field('billing_status,run_status')->select()->toArray();
        if(array_filter($logs,fn($c)=>$c['run_status']==='pending_confirmation'))return 'pending_confirmation';
        $states=array_column($logs,'billing_status');
        if(count($states)!==count($ids))return 'settlement_failed';foreach($states as $state)if(!in_array($state,['settled','refunded','none'],true))return in_array($state,['pending_usage','pending_confirmation','settlement_failed'],true)?$state:'pending';return count(array_filter($states,fn($s)=>$s==='refunded'))===count($states)?'refunded':'settled';
    }
}
