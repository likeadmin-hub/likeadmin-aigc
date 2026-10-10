<?php
/** Explicit paid evaluation. No invocation without --execute and scoped IDs.
 * Creates a separate canvas/thread; never rewrites existing conversations.
 * Every run is a normal metered conversation run, restricted to one Kimi model.
 */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationService as Service;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationAgentDecision as Decision;
use app\common\service\app\aigc_short_drama\canvas_agent\MarketTextConversationProvider as Provider;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
$options=getopt('', ['execute','tenant:','user:','model:','max-turns:','resume-thread:','start-turn:']);
if(!isset($options['execute'])) {echo "Paid evaluation requires --execute --tenant=N --user=N --model=ID --max-turns=N (1..12).\n";exit(0);}
$tenant=(int)($options['tenant']??0);$user=(int)($options['user']??0);$model=(string)($options['model']??'');$max=(int)($options['max-turns']??0);
if($tenant<=0 || $user<=0 || $model==='' || $max<1 || $max>12) throw new RuntimeException('Explicit evaluation scope required');
$selected=[];
foreach(AigcShortDramaService::canvasModelGroups($tenant) as $group) if(($group['key']??'')==='script_plan') foreach($group['options'] as $item) {
    if(($item['id']??'')===$model && stripos(($item['model_code']??'').' '.($item['name']??''),'kimi')!==false) $selected=$item;
}
if(!$selected) throw new RuntimeException('Only an authorized tenant Kimi text model is allowed');
$start=(int)($options['start-turn']??1)-1;
if($start<0 || $start+$max>12) throw new RuntimeException('Evaluation budget exceeds 12 turns');
if(isset($options['resume-thread'])) {
    $row=Db::name(Store::PREFIX.'thread')->where(['id'=>(int)$options['resume-thread'],'tenant_id'=>$tenant,'user_id'=>$user])->find();
    if(!$row || $row['title']!=='Agent 多轮验收' || (json_decode($row['settings_json'],true)['dialogue_engine']??'')!==Decision::ENGINE) throw new RuntimeException('Not an evaluation thread');
    $thread=(int)$row['id'];$canvas=(int)$row['canvas_id'];
    $used=Db::name(Store::PREFIX.'run')->where('thread_id',$thread)->count();
    if($used<$start || $used+$max>12) throw new RuntimeException('Resume exceeds actual remaining budget');
} else {
    if($start!==0) throw new RuntimeException('Start offset requires an evaluation thread');
    $canvas=Canvas::create($tenant,$user,['title'=>'Agent 多轮验收 '.date('m-d H:i')])['id'];
    $thread=Store::create($tenant,$user,$canvas,'eval-'.bin2hex(random_bytes(8)),'Agent 多轮验收')['id'];
    Db::name(Store::PREFIX.'thread')->where(['id'=>$thread,'tenant_id'=>$tenant,'user_id'=>$user])->update(['settings_json'=>json_encode(['dialogue_engine'=>Decision::ENGINE])]);
}
$cases=[
    ['你好',['respond']],
    ['后面回答尽量简短一些',['respond']],
    ['我想生成短剧',['respond','start_project']],
    ['故事是程序员大战孙悟空',['start_project','update_requirements']],
    ['你觉得竖屏好还是横屏好？先给建议，不要替我选',['respond']],
    ['一集，60秒，先别生成',['update_requirements']],
    ['你好',['respond']],
    ['我们刚才确定的故事、集数和时长是什么？',['respond']],
    ['把时长改为90秒，其他保留，仍然先别生成',['update_requirements']],
    ['恢复制作',['resume']],
    ['比例用16:9，但视觉风格我还没想好，请展示待确认选项',['update_requirements']],
    ['先聊聊，暂时不要开始生成。你能帮我比较几种画风吗？',['pause','respond']],
];
$report=['canvas_id'=>$canvas,'thread_id'=>$thread,'model'=>$model,'steps'=>[]];$anchor=0;
echo json_encode(['evaluation_started'=>$report],JSON_UNESCAPED_UNICODE)."\n";flush();
foreach(array_slice($cases,$start,$max,true) as $index=>[$text,$actions]) {
    // Freeze the model restriction before a background worker can claim the run.
    $ack=Db::transaction(function() use($tenant,$user,$canvas,$thread,$index,$text,$model) {
        $ack=Service::send($tenant,$user,$canvas,$thread,['request_key'=>'eval-'.$index.'-'.bin2hex(random_bytes(4)),'content'=>$text,
            'selected_node_ids'=>[],'base_revision'=>0,'preferences'=>['reasoning_model'=>$model,'generation_mode'=>'manual']]);
        $context=json_decode(Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('context_snapshot'),true);
        $context['evaluation_model_allowlist']=[$model];
        Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->update(['context_snapshot'=>json_encode($context,JSON_UNESCAPED_UNICODE)]);
        return $ack;
    });
    Worker::process($tenant,$user,$ack['run_id'],new class implements \app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface {
        public function preflight(int $tenant,int $user,array $request): void {(new Provider())->preflight($tenant,$user,$request);}
        public function generate(int $tenant,int $user,array $request): array {
            $validate=$request['result_validator'];
            $request['result_validator']=static function(array $result) use($validate): void {
                try {$validate($result);} catch(\Throwable $e) {
                    echo json_encode(['rejected_decision'=>$result['content']??'', 'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE)."\n";flush();throw $e;
                }
            };
            return (new Provider())->generate($tenant,$user,$request);
        }
    });
    $deadline=time()+130;
    do {
        $run=Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->find();
        if(!in_array($run['status'],['queued','running'],true)) break;
        usleep(250000);
    } while(time()<$deadline);
    $event=Db::name(Store::PREFIX.'event')->where(['run_id'=>$ack['run_id'],'kind'=>'agent.decision'])->value('payload_json');
    $event=json_decode((string)$event,true)?:[];
    $message=Db::name(Store::PREFIX.'message')->where(['run_id'=>$ack['run_id'],'role'=>'assistant'])->value('content_json');
    $state=json_decode(Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('settings_json'),true);
    $workflow=$state['workflow_state']??[];
    $violations=[];
    if($run['status']!=='success') $violations[]='run_not_success';
    if(!in_array($event['action']??'',$actions,true)) $violations[]='unexpected_action';
    if($index===2 && !empty($workflow['intake_presentation']['visible'])) $violations[]='premature_card';
    if($index===4 && !empty($workflow['slot_values']['aspect_ratio'])) $violations[]='suggestion_adopted';
    if(in_array($index,[5,8,11],true) && empty($workflow['execution_paused'])) $violations[]='pause_lost';
    if($index>=5 && !in_array(($workflow['slot_values']['episode_count']??''),['1集','一集','1'],true)) $violations[]='episode_count_lost';
    if($index===10 && (empty($workflow['intake_presentation']['visible']) || ($workflow['slot_values']['aspect_ratio']??'')!=='16:9')) $violations[]='requested_card_or_ratio_missing';
    if($index===8 && !str_contains((string)($workflow['slot_values']['episode_duration']??''),'90')) $violations[]='correction_lost';
    if(!empty($workflow['intake_presentation']['visible'])) {
        $current=(int)($workflow['intake_anchor_run_id']??0);
        if($anchor && $current!==$anchor) $violations[]='card_moved';
        $anchor=$current;
    }
    if(($workflow['stage_state']['key']??'intake')!=='intake') $violations[]='unexpected_production_stage';
    if(Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json')!=='[]') $violations[]='unexpected_canvas_write';
    $step=['turn'=>$index+1,'run_id'=>$ack['run_id'],'input'=>$text,'status'=>$run['status'],'action'=>$event['action']??null,
        'reply'=>json_decode((string)$message,true)['text']??null,'slots'=>$workflow['slot_values']??[],
        'paused'=>!empty($workflow['execution_paused']),'card_anchor'=>$anchor,'memory'=>$state['agent_memory']??[],
        'violations'=>$violations];
    $report['steps'][]=$step;echo json_encode($step,JSON_UNESCAPED_UNICODE)."\n";flush();
    if($violations) break; // Never spend the remaining budget on a failing chain.
}
echo json_encode(['evaluation_finished'=>true,'turns'=>count($report['steps']),'canvas_id'=>$canvas,'thread_id'=>$thread],JSON_UNESCAPED_UNICODE)."\n";
