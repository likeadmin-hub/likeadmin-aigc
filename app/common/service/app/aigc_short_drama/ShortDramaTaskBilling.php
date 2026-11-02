<?php
namespace app\common\service\app\aigc_short_drama;
use think\facade\Db;

/** Display projection from immutable consumption amounts, including paid validation repairs. */
final class ShortDramaTaskBilling
{
    public static function resolve(int $tenant,int $user,string $taskId):?array
    {
        $owner=['tenant_id'=>$tenant,'user_id'=>$user];$task=Db::name('aigc_short_drama_script_task')->where($owner)->where('task_id',$taskId)->find();
        if(!$task)return null;$ids=[];
        foreach(Db::name('aigc_short_drama_planning_unit')->where($owner)->where('task_id',$taskId)->where('status','received')->column('result_json')as$json){
            $r=json_decode((string)$json,true);$r=$r['result']??$r;$id=(int)($r['consumption_id']??0);if($id>0)$ids[$id]=$id;
        }
        if((int)$task['app_task_id']>0)foreach(Db::name('ai_consumption_log')->where($owner)->where('app_task_id',$task['app_task_id'])->column('id')as$id)$ids[(int)$id]=(int)$id;
        if($ids===[])return null;
        $rows=Db::name('ai_consumption_log')->where($owner)->where('app_code','aigc_short_drama')->whereIn('id',array_values($ids))
            ->field('id,billing_status,actual_user_price,actual_tenant_cost,usage_snapshot')->select()->toArray();
        if(count($rows)!==count($ids))return ['billing_status'=>'settlement_failed','projection_error'=>'missing_owned_consumption'];
        $states=array_column($rows,'billing_status');$open=array_diff($states,['settled','deducted','refunded']);
        $status=$open?(in_array('settlement_failed',$states,true)?'settlement_failed':'pending_usage')
            :(array_diff($states,['refunded'])?'settled':'refunded');
        $input=0;$output=0;foreach($rows as$row){if(!in_array($row['billing_status'],['settled','deducted'],true))continue;
            $usage=json_decode((string)$row['usage_snapshot'],true)?:[];if(empty($usage['provider_reported']))continue;
            $input+=(int)($usage['prompt_tokens']??$usage['input_tokens']??0);$output+=(int)($usage['completion_tokens']??$usage['output_tokens']??0);
        }
        return ['tenant_cost_points'=>round(array_sum(array_column($rows,'actual_tenant_cost')),2),
            'user_charge_points'=>round(array_sum(array_column($rows,'actual_user_price')),2),'billing_status'=>$status,
            'prompt_tokens'=>$input,'completion_tokens'=>$output,'consumption_count'=>count($rows),
            'refunded_call_count'=>count(array_filter($states,static fn($s)=>$s==='refunded')),'usage_scope'=>'settled_calls'];
    }
}
