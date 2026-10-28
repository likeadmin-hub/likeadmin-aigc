<?php
namespace app\tenantapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class SchemaController extends Controller {public function catalog(){return $this->call(fn()=>Document::catalog());}public function validate(){return $this->call(fn()=>['errors'=>Document::validate((array)$this->request->post('document',[]),(array)$this->request->post('targets',[]))]);}}
