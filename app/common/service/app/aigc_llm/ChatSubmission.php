<?php
namespace app\common\service\app\aigc_llm;

use app\common\service\ai\GenerationSubmission;
use app\common\model\app\aigc_llm\{AigcLlmMessage,AigcLlmSession};

/** Stream admission is JSON internally; only the first owner may call the provider. */
final class ChatSubmission
{
    public static function admit(int $tenant, int $user, string $key, array $params, callable $prepare): array
    {
        $response=GenerationSubmission::run($tenant,$user,'app.aigc_llm.chat/stream',$key,$params,static function()use($prepare){
            try{$context=$prepare();}
            catch(\Exception $e){if(get_class($e)!==\Exception::class && !$e instanceof \InvalidArgumentException)throw $e;return json(['code'=>0,'msg'=>$e->getMessage(),'data'=>[]]);}
            $message=$context['assistant_message'];$session=$context['session'];
            return json(['code'=>1,'data'=>['session_id'=>(int)$session['id'],'assistant_message_id'=>(int)$message['id'],'parent_user_message_id'=>(int)$message['parent_user_message_id']]]);
        })->getData();
        if(($response['code']??0)!==1)throw new \InvalidArgumentException($response['msg']??'本次回复正在受理，请使用原请求重试');
        return $response['data']+['replayed'=>(bool)($response['generation_submission']['replayed']??false)];
    }

    /** Local snapshots only: no generation, billing, provider query or terminal mutation. */
    public static function follow(int $tenant,int $user,array $receipt,callable $emit,float $seconds=10): void
    {
        $session=AigcLlmSession::where(['id'=>$receipt['session_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find();
        if(!$session)throw new \InvalidArgumentException('会话不存在');
        $emit('receipt',$receipt);
        $emit('session',['session_id'=>(int)$session['id'],'title'=>$session['title'],'model_code'=>$session['model_code']]);
        $emit('message',['assistant_message_id'=>$receipt['assistant_message_id'],'user_message_id'=>$receipt['parent_user_message_id'],'parent_user_message_id'=>$receipt['parent_user_message_id']]);
        $until=microtime(true)+max(0,min(10,$seconds));$previous=null;
        do {
            $message=AigcLlmMessage::where(['id'=>$receipt['assistant_message_id'],'session_id'=>$receipt['session_id'],'tenant_id'=>$tenant,'user_id'=>$user,'role'=>'assistant','delete_time'=>0])->find();
            if(!$message)throw new \InvalidArgumentException('回复已删除或不可用');
            $usage=(array)$message['token_usage_json'];$state=['message_id'=>(int)$message['id'],'content'=>(string)$message['content'],'status'=>(string)$message['status']];
            if($state!==$previous){$emit('snapshot',$state);$previous=$state;}
            if(in_array($message['status'],['done','stopped'],true)){$emit('done',$state+['finish_reason'=>$message['finish_reason'],'usage'=>$usage,'billing'=>$usage['billing']??[]]);return;}
            if($message['status']==='error'){$emit('error',$state+['terminal'=>!in_array($usage['billing']['billing_status']??'', ['pending_usage','settlement_failed','needs_review'],true),'message'=>'本次回复已结束，请查看历史记录；重新生成会建立新请求']);return;}
            if(microtime(true)>=$until)break;
            usleep(100000);
        }while(true);
        $emit('pending',['message_id'=>$receipt['assistant_message_id'],'message'=>'原回复仍在生成或等待核对，请刷新历史记录，勿重复新建']);
    }
}
