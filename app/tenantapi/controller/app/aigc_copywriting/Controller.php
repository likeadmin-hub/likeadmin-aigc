<?php
namespace app\tenantapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
abstract class Controller extends \app\tenantapi\controller\BaseAdminController {protected function call(callable $fn){try{return $this->success('操作成功',$fn()??[]);}catch(\Throwable $e){return $this->fail($e->getMessage());}}}
