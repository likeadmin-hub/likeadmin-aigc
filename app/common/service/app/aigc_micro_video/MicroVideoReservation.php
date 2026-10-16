<?php
namespace app\common\service\app\aigc_micro_video;

use RuntimeException;
use think\facade\Db;

/** Reservation and its business link commit together; provider I/O happens later. */
final class MicroVideoReservation
{
    public static function reserve(array $task,array $segment,array $attempt,array $selection,array $request): array
    {
        return Db::transaction(function()use($task,$segment,$attempt,$selection,$request){
            $row=MicroVideoStore::table('attempt')->where(['id'=>$attempt['id'],'task_id'=>$task['id'],'segment_id'=>$segment['id'],'tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'kind'=>'video'])->lock(true)->find();
            if(!$row)throw new RuntimeException('片段执行记录缺失，需核对');
            if((int)$row['consumption_id']>0)return $row;
            if($row['status']!=='prepared')throw new RuntimeException('片段提交结果待核对，请稍后查看');
            $expected=MicroVideoStore::json($task['confirmed_quote'])['segments'][(string)$segment['id']]??null;
            if(!$expected)throw new RuntimeException('当前片段缺少已确认报价，请重新确认费用');
            $runtime=MicroVideoCatalog::runtime($selection);
            // The runtime recovers its deterministic reservation before reading
            // current catalog/prices. Compare its immutable admission amounts,
            // never a new live quote that could strand an older reservation.
            $reserved=$runtime::reserve((int)$task['tenant_id'],(int)$task['user_id'],MicroVideoStore::APP,'video_generate','aigc_micro_video_segment','micro:'.$row['id'],$selection,$request);
            $c=Db::name('ai_consumption_log')->where(['id'=>$reserved['consumption_id'],'tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'app_code'=>MicroVideoStore::APP])->find();
            if(!$c)throw new RuntimeException('片段计费记录缺失，需核对');
            if((int)$c['sku_id']!==(int)$expected['market_sku_id']||abs((float)$c['reserved_user_price']-(float)$expected['user_charge_points'])>0.000001||abs((float)$c['reserved_tenant_cost']-(float)$expected['tenant_cost_points'])>0.000001)throw new RuntimeException('模型售价已变化，请重新确认费用');
            $runtime::linkBusinessTask((int)$reserved['app_task_id'],(int)$segment['id']);
            $values=['consumption_id'=>(int)$reserved['consumption_id'],'app_task_id'=>(int)$reserved['app_task_id'],'update_time'=>time()];
            MicroVideoStore::table('attempt')->where('id',$row['id'])->update($values);
            return array_replace($row,$values);
        });
    }
}
