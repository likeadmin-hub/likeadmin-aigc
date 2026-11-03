<?php
/** Only the explicit owner-marked mobile actions fixture chooses this reply. */
function marketAgentMobileActionsReply(array $input): array
{
    if(getenv('AIGC_HTTP_AGENT_MOBILE_MEDIA_MATRIX')==='1'){require_once __DIR__.'/MarketAgentMediaBoundaryHttpSupplier.php';return marketAgentMediaBoundaryReply($input);}
    if(empty($input['tools']))return [false,'{"action":"new","topic_id":0}'];
    $db=think\facade\Db::class;$run=$db::name('aigc_llm_agent_run')->where('status','running')->order('id','desc')->find();
    if(!$run)throw new RuntimeException('Mobile supplier has no active owned run');
    if($db::name('aigc_llm_agent_step')->where('run_id',$run['id'])->count())return [false,'隔离素材方案已保存，请核对后确认。'];
    $images=[];foreach(json_decode($run['attachments'],true)?:[] as$asset)if(str_starts_with($asset['mime']??'','image/'))$images[]=$asset['file_id'];
    $params=['tool_code'=>'aigc_image','title'=>'Synthetic mobile reference image','parameters'=>['prompt'=>$run['content'],'quality'=>'1k','ratio'=>'1:1','reference_images'=>$images]];
    return [['index'=>0,'id'=>'call-mobile-actions','type'=>'function','function'=>['name'=>'plan_creation','arguments'=>json_encode(['payload'=>$params],JSON_UNESCAPED_UNICODE)]],''];
}
