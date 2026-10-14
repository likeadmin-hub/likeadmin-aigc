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
$partial=['content'=>'{"reply":"已收到页面要求，准备调整标题。","kind":"final","operations":[{"type":"delete_component","widget_id":"w0"}', 'content_source'=>'content'];
$pending=new \app\common\service\power\TextSettlementPending(1,$partial,[],new RuntimeException('fixture stream incomplete'));
verifyFinalContent(D::partialReply($pending)==='已收到页面要求，准备调整标题。','partial terminal snapshot without deltas recovers only root reply');
$partial['content_source']='reasoning';$pending=new \app\common\service\power\TextSettlementPending(1,$partial,[],new RuntimeException('fixture'));
verifyFinalContent(D::partialReply($pending)==='','partial reasoning never becomes reply');
unset($partial['content_source']);$exception=new \app\common\service\power\MarketTextModelProviderException('fixture',false,$partial);
verifyFinalContent(D::partialReply($exception)==='','unknown partial content source fails closed');
verifyFinalContent(D::partialReply(new RuntimeException('fixture'))==='','unrelated exception has no reply');
$rejected=['content'=>'','content_source'=>'content','protocol_diagnostic'=>['provider_type'=>'','provider_code'=>'invalid_request_error','provider_message'=>'Unsupported parameter: input_pricing_sku_key']];
$pending=new \app\common\service\power\TextSettlementPending(1,$rejected,[],new RuntimeException('fixture'));
$details=D::failureDetails($pending);verifyFinalContent($details['error_code']==='provider_parameter_rejected'&&$details['error']==='当前模型不支持此次请求中的参数，本轮未完成。请核对模型配置和本轮费用后再继续。'&&!str_contains($details['error'],'input_pricing_sku_key'),'structured safe parameter rejection reaches run UI');
$rejected['protocol_diagnostic']['provider_message']='Connection interrupted';$pending=new \app\common\service\power\TextSettlementPending(1,$rejected,[],new RuntimeException('fixture'));
verifyFinalContent(D::failureDetails($pending)===[],'unknown interruption stays existing review path');
echo "final content: $n checks passed\n";
