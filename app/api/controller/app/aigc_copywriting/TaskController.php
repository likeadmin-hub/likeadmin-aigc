<?php
namespace app\api\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class TaskController extends Controller {
 public function create(){return $this->call(fn()=>Tasks::create($this->tenant(),$this->userId,$this->request->post('',null,null)));}
 public function detail(){return $this->call(fn()=>Tasks::detail($this->tenant(),$this->userId,(int)$this->request->get('id')));}
 public function lists(){return $this->call(fn()=>Tasks::lists($this->tenant(),$this->userId,(int)$this->request->get('page_no',1)));}
 public function cancel(){return $this->call(fn()=>Tasks::cancel($this->tenant(),$this->userId,(int)$this->request->post('id')));}
 public function events(){
  $tenant=$this->tenant();$id=(int)$this->request->param('id');$after=(int)$this->request->param('after_seq',0);
  try{Tasks::owned($tenant,$this->userId,$id);}catch(\Throwable $e){return $this->fail($e->getMessage());}
  header('Content-Type: text/event-stream; charset=utf-8');header('Cache-Control: no-cache, no-transform');header('X-Accel-Buffering: no');
  while(ob_get_level()>0)@ob_end_flush();$started=microtime(true);$lastBeat=0;
  do{$rows=Events::read($tenant,$this->userId,$id,$after);foreach($rows as $event){echo 'id: '.$event['seq']."\n".'event: snapshot'."\n".'data: '.$event['payload']."\n\n";$after=(int)$event['seq'];} $snapshot=Tasks::detail($tenant,$this->userId,$id);if(!$rows){echo 'event: snapshot'."\n".'data: '.Store::json($snapshot)."\n\n";}if(time()-$lastBeat>=5){echo ': heartbeat'."\n\n";$lastBeat=time();}@flush();if(in_array($snapshot['status'],['success','failed','canceled'],true))break;usleep(200000);}while(!connection_aborted()&&microtime(true)-$started<25);
  exit;
 }
}
