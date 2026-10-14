<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;
use app\common\service\open_platform\{OpenVault,OpenStore};

/** Process-local, server-only bridge for an already confirmed native price snapshot. */
final class AgentToolContext
{
    private static ?array $prepared=null;
    public static function within(array $prepared,callable $call): array
    {
        if(self::$prepared!==null)throw new \LogicException('Nested Agent submit');
        self::$prepared=$prepared;
        try{return $call();}finally{self::$prepared=null;}
    }
    public static function snapshot(int $tenant,int $user,string $app,string $field): ?array
    {
        if(self::$prepared===null||self::$prepared['capability']['code']!==$app)return null;
        $blob=self::$prepared['quote']['snapshot'][$field]??'';
        if($blob==='')throw new \RuntimeException('创作价格快照缺失');
        $value=OpenStore::json(OpenVault::open($blob));
        if((int)($value['tenant_id']??0)!==$tenant||(int)($value['user_id']??0)!==$user||($value['app_code']??'')!==$app)throw new \RuntimeException('创作价格归属不匹配');
        return $value;
    }
    public static function image(int $tenant,int $user): ?array
    {
        $app=self::$prepared['capability']['code']??'';
        if(!in_array($app,['aigc_image','aigc_fitting'],true))return null;
        $value=self::snapshot($tenant,$user,$app,'native_selection_blob');
        return $value?['selection'=>$value['selection'],'quote'=>self::$prepared['quote']['snapshot']['quote'],'request_id'=>'']:null;
    }
    public static function music(int $tenant,int $user,int $task=0,int $stepId=0): ?array
    {
        $prepared=self::$prepared;
        if($task>0){
            if($stepId<1)return null;
            $step=AgentStore::owner('step',$tenant,$user)->where(['id'=>$stepId,'tool_code'=>'aigc_music','task_id'=>$task])->order('id')->find();
            if(!$step)throw new \RuntimeException('音乐任务价格记录不存在，请人工核对');
            $prepared=AgentStore::json($step['prepared']);
        }
        if(($prepared['capability']['code']??'')!=='aigc_music')return null;
        $value=OpenStore::json(OpenVault::open($prepared['quote']['snapshot']['native_selection_blob']??''));
        if((int)($value['tenant_id']??0)!==$tenant||(int)($value['user_id']??0)!==$user||($value['app_code']??'')!=='aigc_music')throw new \RuntimeException('音乐价格归属不匹配');
        return ['selection'=>$value['selection'],'quote'=>$prepared['quote']['snapshot']['quote'],'request_id'=>''];
    }
    public static function musicStepId(): int
    {
        return (self::$prepared['capability']['code']??'')==='aigc_music'?(int)(self::$prepared['step_id']??0):0;
    }
    /** Bind before supplier I/O, so polling and restart always find the approved price. */
    public static function bindMusicTask(int $tenant,int $user,int $task): void
    {
        if((self::$prepared['capability']['code']??'')!=='aigc_music')return;
        self::music($tenant,$user);
        $changed=AgentStore::owner('step',$tenant,$user)->where(['id'=>self::$prepared['step_id']??0,'run_id'=>self::$prepared['run_id']??0,'status'=>'submitting','task_id'=>0])->update(['task_id'=>$task,'update_time'=>time()]);
        if(!$changed)throw new \RuntimeException('音乐任务绑定失败，请核对执行状态');
    }
    public static function priceFingerprint(array $quote): string
    {
        // Encrypted captures use random IVs. Compare their contents, never ciphertext.
        foreach($quote['snapshot']??[] as $key=>$value)if(str_ends_with($key,'_blob'))$quote['snapshot'][$key]=OpenStore::json(OpenVault::open($value));
        return hash('sha256',AgentStore::encode(self::canonical($quote)));
    }
    private static function canonical($value)
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as &$item)$item=self::canonical($item);
        return $value;
    }
}
