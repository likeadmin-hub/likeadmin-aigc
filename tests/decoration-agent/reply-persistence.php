<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\decorate\agent\DecorationAgentService as S;
use app\common\service\decorate\agent\DecorationAgentStream as Stream;
$n=0;function verifyPersistentReply($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
Db::startTrans();
try {
    $session=(int)Db::name('decorate_agent_session')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'template_id'=>1,'page_id'=>1,'scope'=>'page','preferences'=>'{}','facts'=>'[]','create_time'=>time(),'update_time'=>time()]);
    $id=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session,'status'=>'running','input_json'=>'{}','context_json'=>'{"receipts":[],"calling":true}','result_json'=>'{}','events_json'=>'[]','idempotency_key'=>'reply-fixture:'.bin2hex(random_bytes(8)),'lease_token'=>'unchanged-lease','lease_expire_time'=>time()+600,'create_time'=>time(),'update_time'=>time()]);
    S::event($id,'assistant',['text'=>'建议先调整留白。','step'=>1,'complete'=>false]);
    $snapshot=S::run(1,1,$id);
    verifyPersistentReply($snapshot['assistant_reply']==='建议先调整留白。'&&$snapshot['assistant_step']===1,'snapshot rehydrates cumulative reply');
    for($i=0;$i<205;$i++)S::event($id,'tool',['message'=>'fixture']);
    $snapshot=S::run(1,1,$id);
    verifyPersistentReply(count($snapshot['events'])===200&&$snapshot['assistant_reply']==='建议先调整留白。','reply survives event history truncation');
    foreach([[2,1],[1,2]] as [$tenant,$admin]) {
        $writes=[];
        try { $initial=S::run($tenant,$admin,$id);(new Stream(fn()=>S::run($tenant,$admin,$id),function($frame)use(&$writes){$writes[]=$frame;}))->send($initial);throw new RuntimeException('cross owner accepted'); }
        catch(RuntimeException $e){verifyPersistentReply(str_contains($e->getMessage(),'无权')&&!$writes,'ownership refuses before frames');}
    }
    $before=Db::name('decorate_agent_run')->where('id',$id)->find();
    $frames=[];$clock=0.0;(new Stream(fn()=>S::run(1,1,$id),function($frame)use(&$frames){$frames[]=$frame;},function()use(&$clock){return $clock;},function($micros)use(&$clock){$clock+=$micros/1000000;},fn()=>false))->send($snapshot,206,0.5);
    verifyPersistentReply(Db::name('decorate_agent_run')->where('id',$id)->find()===$before,'stream read leaves task state, lease, billing and events unchanged');
    verifyPersistentReply(str_contains($frames[0],'id: 206')&&str_contains(end($frames),'event: reconnect'),'durable cursor used after history truncation');
    $finish=new ReflectionMethod(S::class,'finish');$finish->setAccessible(true);
    $finish->invoke(null,$id,'awaiting_input',['reply'=>'需要确认导航位置。','question'=>'修改哪个导航？']);
    $message=Db::name('decorate_agent_message')->where('run_id',$id)->where('role','assistant')->value('content');
    verifyPersistentReply($message==="需要确认导航位置。\n\n修改哪个导航？",'natural conversation persisted, no JSON');
    $terminalFrames=[];$terminal=S::run(1,1,$id);(new Stream(fn()=>S::run(1,1,$id),function($frame)use(&$terminalFrames,$id){if(str_contains($frame,'event: done'))verifyPersistentReply(Db::name('decorate_agent_message')->where('run_id',$id)->where('role','assistant')->count()===1,'terminal frame sees persisted assistant message');$terminalFrames[]=$frame;}))->send($terminal);
    $snapshot=S::run(1,1,$id);verifyPersistentReply($snapshot['status']==='awaiting_input'&&$snapshot['candidate_id']===0,'clarification finishes without a candidate');
    verifyPersistentReply(Db::name('decorate_agent_run')->where('id',$id)->value('lease_token')==='unchanged-lease','reply handling preserves lease');
} finally { Db::rollback(); }
echo "reply persistence: $n checks passed (rolled back)\n";
