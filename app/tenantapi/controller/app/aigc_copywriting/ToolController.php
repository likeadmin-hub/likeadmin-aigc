<?php
namespace app\tenantapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class ToolController extends Controller {
 public function lists(){return $this->call(fn()=>Tools::lists($this->tenantId));}
 public function create(){return $this->call(fn()=>Tools::create($this->tenantId,$this->adminId,$this->request->post('',null,null)));}
 public function save(){return $this->call(fn()=>Tools::save($this->tenantId,$this->adminId,$this->request->post('',null,null)));}
 public function copy(){return $this->call(fn()=>Tools::copy($this->tenantId,$this->adminId,(int)$this->request->post('id')));}
 public function delete(){return $this->call(fn()=>Tools::delete($this->tenantId,$this->adminId,(int)$this->request->post('id')));}
 public function restore(){return $this->call(fn()=>Tools::restore($this->tenantId,$this->adminId,(int)$this->request->post('id')));}
}
