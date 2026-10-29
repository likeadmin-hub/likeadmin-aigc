<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use app\common\service\power\TextModelExecutionPolicy;
final class CopywritingTextRuntime
{
    public static function generate(array $task,?callable $onEvent=null): array
    {
        $prompt=CopywritingStore::decode($task['prompt_snapshot']);$model=CopywritingStore::decode($task['model_snapshot']);
        return Runtime::generate((int)$task['tenant_id'],(int)$task['user_id'],[
            'content'=>$prompt['user_prompt'],'system_prompt'=>$prompt['system_prompt'],'model_selection'=>(string)$model['id'],
            'source_app_code'=>'aigc_copywriting','app_code'=>'aigc_copywriting','business_table'=>'aigc_copywriting_task','business_id'=>(int)$task['id'],
            'action_code'=>'copywriting_generate','_disable_model_fallback'=>true,'_disable_transient_retry'=>true,'_disable_compatibility_retry'=>true,
        ],$onEvent,false,null,new TextModelExecutionPolicy('aigc_copywriting',$task['execution_fingerprint']));
    }
}
