<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
use app\common\service\decorate\agent\DecorationAgentCatalog as C;
use app\common\service\decorate\agent\DecorationAgentDesignContext as D;
$checks=0;
function knowledgeCheck(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$components=C::summary()['components'];
knowledgeCheck(count($components)===46,'Expected the complete current 46-component registry');
foreach($components as $definition){
 $name=$definition['name'];$detail=D::contract($name);
 knowledgeCheck(isset($detail['defaults'])&&$detail['defaults']===$definition['defaults'],$name.': full detailed tool must retain exact creation defaults');
 foreach(['content_fields','style_fields'] as $section){
  knowledgeCheck(isset($detail[$section]),$name.': missing detailed '.$section);
  foreach($definition[$section] as $key=>$schema){
   foreach(['key','label','type','value_type','default','unit','min','max','options','help','show_if'] as $attribute){
    if(!array_key_exists($attribute,$schema))continue;
    knowledgeCheck(array_key_exists($attribute,$detail[$section][$key]??[])&&$detail[$section][$key][$attribute]===$schema[$attribute],$name.'.'.$section.'.'.$key.': detailed '.$attribute.' was lost');
   }
  }
 }
 knowledgeCheck(($detail['lists']??null)===$definition['lists'],$name.': detailed row fields and limits differ from registry');
 knowledgeCheck(($detail['source']??null)===$definition['source'],$name.': detailed source semantics differ from registry');
 knowledgeCheck(($detail['children']??null)===$definition['children'],$name.': detailed container constraints differ from registry');
 knowledgeCheck(isset($detail['semantics'])&&$detail['semantics']!=='按字段/列表/样式契约写入，链接和素材必须来自本轮授权上下文。',$name.': missing component-specific role and behavior');
}
echo "knowledge coverage: $checks checks passed across ".count($components)." complete component contracts\n";
