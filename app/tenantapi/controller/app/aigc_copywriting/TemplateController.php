<?php
namespace app\tenantapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class TemplateController extends Controller {
 public function export(){return $this->call(function(){$p=Pages::detail($this->tenantId,(int)$this->request->get('id'));return ['schema_version'=>1,'document'=>$p['document']];});}
 public function import(){return $this->call(function(){$d=Document::normalize((array)$this->request->post('document',[]));Document::assertValid($d);return Tools::create($this->tenantId,$this->adminId,['name'=>(string)$this->request->post('name','导入的文案功能'),'category_id'=>(int)$this->request->post('category_id'),'document'=>$d]);});}
}
