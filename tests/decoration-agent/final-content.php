<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentDecision as D;
use app\common\service\decorate\agent\DecorationAgentDecisionNode as N;
use NeuronAI\Workflow\Workflow;
$n=0;function verifyFinalContent($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
// Same response envelope as the market runtime's reasoning-only fallback. Even valid
// decision JSON in reasoning must never become a visible reply or actionable edit.
$reasoning=['content'=>'{"reply":"内部分析：不要公开","kind":"final","operations":[{"type":"delete_component","widget_id":"w0"}]}','content_source'=>'reasoning','billing'=>['billing_status'=>'settled']];
$visible=[];$operations=[];$calls=0;
try {
    $workflow=new Workflow('decoration-reasoning-fixture');
    $workflow->setMaxSteps(1)->addNode(new N(function()use($reasoning,&$calls){$calls++;return $reasoning;}));
    $result=$workflow->run()->get('result');
    $text=D::finalContent($result);$decision=D::parse($text);$visible[]=$decision['reply'];$operations=$decision['operations'];
    throw new RuntimeException('reasoning accepted');
} catch (RuntimeException $e) { verifyFinalContent($e->getMessage()==='模型未返回正式回复，请检查模型输出配置','reasoning result rejected with safe error'); }
verifyFinalContent($calls===1&&!$visible&&!$operations,'no retry, visible reasoning or reasoning operations');
foreach(['reasoning','reasoning_content','analysis',''] as $source){try{$result=$reasoning;$result['content_source']=$source;D::finalContent($result);throw new RuntimeException('non-content accepted');}catch(RuntimeException $e){verifyFinalContent($e->getMessage()==='模型未返回正式回复，请检查模型输出配置','non-content refused');}}
$final=['content'=>'{"reply":"建议先调整留白。","kind":"final","operations":[]}','content_source'=>'content'];
verifyFinalContent(D::parse(D::finalContent($final))['reply']==='建议先调整留白。','official content retained');
verifyFinalContent(D::finalContent(['content'=>$final['content']])===$final['content'],'legacy response without source stays compatible');
echo "final content: $n checks passed\n";
