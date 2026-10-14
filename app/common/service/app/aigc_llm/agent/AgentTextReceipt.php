<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\open_platform\OpenVault;

/** Private recovery evidence only. Reading a receipt never settles, wakes or resubmits a task. */
final class AgentTextReceipt
{
    public static function prepare(): string
    {
        $dir=runtime_path().'home-agent-text-receipts';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('无法准备文本回执存储');
        if(!is_writable($dir))throw new \RuntimeException('文本回执存储不可写');
        // Fail before provider I/O if the encryption key is unavailable.
        OpenVault::seal('receipt-storage-check');
        return $dir;
    }
    private static function path(array $log): string
    {
        if(!preg_match('/^C[0-9A-Fa-f]+$/D',(string)$log['consume_no']))throw new \InvalidArgumentException('文本消费编号无效');
        return runtime_path().'home-agent-text-receipts/'.$log['consume_no'].'.json';
    }
    public static function record(array $log,array $task,array $result,bool $complete): void
    {
        $evidence=['version'=>1,'consumption_id'=>(int)$log['id'],'consume_no'=>$log['consume_no'],
            'app_task_id'=>(int)$task['id'],'tenant_id'=>(int)$log['tenant_id'],'user_id'=>(int)$log['user_id'],
            'run_id'=>(int)$task['business_id'],'complete'=>$complete,'received_at'=>time(),'reading_source'=>AgentStore::json($task['request_summary']??[])['agent_reading_source']??null,'result'=>$result];
        $sealed=OpenVault::seal(AgentStore::encode($evidence));
        $path=self::path($log);$tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $fp=fopen($tmp,'xb');if(!$fp)throw new \RuntimeException('无法保存文本回执');
        try{
            if(!chmod($tmp,0600)||fwrite($fp,$sealed)!==strlen($sealed)||!fflush($fp)||(function_exists('fsync')&&!fsync($fp)))throw new \RuntimeException('文本回执写入失败');
            fclose($fp);$fp=null;
            if(!rename($tmp,$path))throw new \RuntimeException('文本回执保存失败');
        }finally{if(is_resource($fp))fclose($fp);if(is_file($tmp))unlink($tmp);}
    }
    public static function read(int $tenant,int $user,int $consumption): ?array
    {
        $log=AiConsumptionLog::where(['id'=>$consumption,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm'])->find();
        if(!$log)throw new \InvalidArgumentException('文本消费记录不存在');
        $task=AiAppTask::where(['id'=>$log['app_task_id'],'tenant_id'=>$tenant,'user_id'=>$user,'business_table'=>'aigc_llm_agent_run'])->find();
        if(!$task)throw new \InvalidArgumentException('文本任务归属不匹配');
        $path=self::path($log->toArray());if(!is_file($path))return null;
        $value=AgentStore::json(OpenVault::open((string)file_get_contents($path)));
        foreach(['consumption_id'=>$consumption,'tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>(int)$task['id'],'run_id'=>(int)$task['business_id'],'consume_no'=>$log['consume_no']] as $key=>$expected){
            if(($value[$key]??null)!==$expected)throw new \RuntimeException('文本回执归属不匹配');
        }
        if(($value['version']??0)!==1||!is_array($value['result']??null))throw new \RuntimeException('文本回执格式无效');
        if(!empty($value['reading_source']['file_id'])){
            AgentContextService::authorizedAsset(AgentStore::run($tenant,$user,(int)$task['business_id']),$value['reading_source']['file_id']);
        }
        return $value;
    }
}
