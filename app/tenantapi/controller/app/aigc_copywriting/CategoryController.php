<?php
namespace app\tenantapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class CategoryController extends Controller {
 public function lists(){return $this->call(fn()=>Categories::lists($this->tenantId));}
 public function save(){return $this->call(fn()=>Categories::save($this->tenantId,$this->adminId,$this->request->post('',null,null)));}
 public function delete(){return $this->call(fn()=>Categories::delete($this->tenantId,$this->adminId,(int)$this->request->post('id'),(int)$this->request->post('target_category_id',0)));}
}
