<?php
namespace app\tenantapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class TaskController extends Controller {
 public function lists(){return $this->call(fn()=>Tasks::lists($this->tenantId,0,(int)$this->request->get('page_no',1),true));}
 public function detail(){return $this->call(fn()=>Tasks::publicTask(Store::owned('task',$this->tenantId,(int)$this->request->get('id'))));}
 public function recheck(){return $this->call(fn()=>Recovery::reconcile($this->tenantId,(int)$this->request->post('id')));}
}
