<?php
namespace app\api\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class ToolController extends Controller {
 public array $notNeedLogin=['lists','detail'];
 public function lists(){return $this->call(fn()=>Tools::lists($this->tenant(),true,$this->terminal()));}
 public function detail(){return $this->call(fn()=>Tools::detail($this->tenant(),(string)$this->request->get('code'),$this->terminal()));}
}
