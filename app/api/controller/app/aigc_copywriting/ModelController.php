<?php
namespace app\api\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class ModelController extends Controller {public function options(){return $this->call(function(){$code=(string)$this->request->get('code','');$d=$code?Tools::detail($this->tenant(),$code,$this->terminal())['document']:[];return Models::options($this->tenant(),$d);});}}
