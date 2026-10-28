<?php
namespace app\api\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
abstract class Controller extends \app\api\controller\BaseApiController {
 protected function call(callable $fn){try{return $this->success('操作成功',$fn());}catch(\Throwable $e){return $this->fail($e->getMessage());}}
 protected function tenant():int{return (int)$this->request->tenantId;}
 protected function terminal():string{$t=(string)$this->request->param('render_terminal','h5');return in_array($t,['pc','h5','mp-weixin'],true)?$t:'h5';}
}
