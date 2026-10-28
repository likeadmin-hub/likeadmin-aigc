<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;

use app\common\service\open_platform\OpenVault;
use think\facade\Db;

/** Private, encrypted provider evidence; never exposed by the public event feed. */
final class CopywritingReceipt
{
    public static function prepare(): string
    {
        $dir=runtime_path().'copywriting-receipts';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('无法准备文案回执存储');
        if(!is_writable($dir))throw new \RuntimeException('文案回执存储不可写');
        OpenVault::seal('copywriting-receipt-check');
        return $dir;
    }

    public static function record(array $log,array $appTask,array $result,bool $complete): void
    {
        if(($log['app_code']??'')!=='aigc_copywriting'||($appTask['business_table']??'')!=='aigc_copywriting_task')throw new \InvalidArgumentException('文案回执归属不匹配');
        $body=['tenant_id'=>(int)$log['tenant_id'],'user_id'=>(int)$log['user_id'],'task_id'=>(int)$appTask['business_id'],'consumption_id'=>(int)$log['id'],'app_task_id'=>(int)$appTask['id'],'complete'=>$complete&&($result['content_source']??'content')==='content','result'=>$result,'received_at'=>time()];
        $path=self::prepare().'/'.(int)$log['id'].'.receipt';$tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $sealed=OpenVault::seal(CopywritingStore::json($body));$fp=fopen($tmp,'xb');if(!$fp)throw new \RuntimeException('文案回执写入失败');
        try {
            if(!chmod($tmp,0600)||fwrite($fp,$sealed)!==strlen($sealed)||!fflush($fp)||(function_exists('fsync')&&!fsync($fp)))throw new \RuntimeException('文案回执写入失败');
            fclose($fp);$fp=null;if(!rename($tmp,$path))throw new \RuntimeException('文案回执保存失败');
        } finally {if(is_resource($fp))fclose($fp);if(is_file($tmp))unlink($tmp);}
    }

    public static function read(array $task): ?array
    {
        $cid=(int)$task['consumption_id'];if(!$cid)return null;
        $log=Db::name('ai_consumption_log')->where(['id'=>$cid,'tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'app_code'=>'aigc_copywriting','app_task_id'=>$task['app_task_id']])->find();
        if(!$log)throw new \InvalidArgumentException('文案回执消费记录不匹配');
        $path=runtime_path().'copywriting-receipts/'.$cid.'.receipt';if(!is_file($path))return null;
        $value=CopywritingStore::decode(OpenVault::open((string)file_get_contents($path)));
        foreach(['tenant_id','user_id','consumption_id','app_task_id'] as $key)if((int)($value[$key]??0)!==(int)$task[$key])throw new \RuntimeException('文案回执归属不匹配');
        if((int)($value['task_id']??0)!==(int)$task['id']||!is_array($value['result']??null))throw new \RuntimeException('文案回执归属不匹配');
        return $value;
    }
}
