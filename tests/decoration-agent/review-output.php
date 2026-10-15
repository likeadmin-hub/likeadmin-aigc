<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use app\common\service\decorate\agent\DecorationAgentDecision as D;
$instruction=D::visualReviewOutputInstruction();
foreach(['完整六类','target/actual','不确定','passed=false','不得删除差异','operations','不复述'] as $required){
    if(!str_contains($instruction,$required))throw new RuntimeException('concise review lost required condition: '.$required);
}
if(!str_contains(D::visualReviewInstruction(),$instruction))throw new RuntimeException('output instruction not in actual model prompt');
// Short evidence remains ordinary six-category evidence; no truncation,
// rewritten booleans, or alternate acceptance path is introduced.
$checks=[];foreach(['layout','typography','color','assets','icons','interaction'] as $category)$checks[]=['category'=>$category,'passed'=>false,'detail'=>'该项待核对','evidence'=>['target/actual主体边界无法辨认，保留不确定']];
$acceptance=['passed'=>false,'checks'=>$checks,'summary'=>'证据不足，仍未通过'];
$parsed=D::parse(json_encode(['reply'=>'对照尚未通过','kind'=>'final','operations'=>[],'acceptance'=>$acceptance],JSON_UNESCAPED_UNICODE));
if($parsed['acceptance']!==$acceptance)throw new RuntimeException('concise report altered evidence or verdict');
echo "review-output: 9 checks passed\n";
