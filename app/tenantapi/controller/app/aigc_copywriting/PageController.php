<?php
namespace app\tenantapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class PageController extends Controller {
 public function detail(){return $this->call(fn()=>Pages::detail($this->tenantId,(int)$this->request->get('id',0),(string)$this->request->get('code','')));}
 public function saveDraft(){return $this->call(fn()=>Pages::saveDraft($this->tenantId,$this->adminId,(int)$this->request->post('id'),(int)$this->request->post('expected_revision'),(array)$this->request->post('document',[],null)));}
 public function publish(){return $this->call(fn()=>Pages::publish($this->tenantId,$this->adminId,(int)$this->request->post('id'),(int)$this->request->post('expected_revision'),(array)$this->request->post('targets',['pc','h5','mp-weixin'])));}
 public function previewToken(){return $this->call(fn()=>Pages::previewToken($this->tenantId,(int)$this->request->post('id'),(int)$this->request->post('expected_revision')));}
 public function versions(){return $this->call(fn()=>Pages::versions($this->tenantId,(int)$this->request->get('id')));}
 public function rollback(){return $this->call(fn()=>Pages::rollback($this->tenantId,$this->adminId,(int)$this->request->post('id'),(int)$this->request->post('version_id'),(int)$this->request->post('expected_revision')));}
}
