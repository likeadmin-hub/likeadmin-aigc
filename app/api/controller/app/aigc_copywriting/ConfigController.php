<?php
namespace app\api\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\{CopywritingStore as Store,CopywritingToolService as Tools,CopywritingCategoryService as Categories,CopywritingPageService as Pages,CopywritingDocument as Document,CopywritingPromptService as Prompts,CopywritingModelService as Models,CopywritingTaskService as Tasks,CopywritingEventService as Events,CopywritingRecovery as Recovery};
class ConfigController extends Controller {
 public array $notNeedLogin=['detail','preview'];
 public function preview(){return $this->call(fn()=>Pages::preview($this->tenant(),(string)$this->request->get('token','')));}
 public function detail(){return $this->call(function(){$t=$this->tenant();$page=Store::table('page')->where(['tenant_id'=>$t,'page_code'=>'home'])->find();$home=[];if($page&&$page['published_version_id'])$home=Document::publicDocument(Store::decode(Store::owned('version',$t,(int)$page['published_version_id'])['document_json']));return ['name'=>'智能文案','categories'=>array_values(array_filter(Categories::lists($t),fn($c)=>$c['is_visible'])),'tools'=>Tools::lists($t,true,$this->terminal()),'home'=>$home,'models'=>Models::options($t)];});}
}
