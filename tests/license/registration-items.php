<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
use app\common\service\license\CopyrightPolicyService as Policy;
function check($condition, $message): void { if (!$condition) throw new RuntimeException($message); }
$rows=[
 ['key'=>'配置公司名称','value'=>'https://example.test'],
 ['key'=>'Copyright © 2026 公司','value'=>''],
 ['key'=>'黔ICP备123号','value'=>'https://beian.miit.gov.cn/#/Integrated/index'],
 ['key'=>'贵公网安备52010302003421号','value'=>'https://beian.mps.gov.cn/#/query/webSearch'],
 ['key'=>'ICP备456号','value'=>'javascript:alert(1)'],
];
$out=Policy::registrationItems($rows);
check(array_column($out,'key')===['黔ICP备123号','贵公网安备52010302003421号','ICP备456号'],'Registration projection must omit company and copyright names');
check($out[0]['value']===$rows[2]['value'] && $out[1]['value']===$rows[3]['value'],'Configured official filing destinations must remain exact');
check($out[2]['value']==='','Unsafe filing URLs must be discarded');
echo "PASS copyright-independent filing projection preserves exact safe URLs without exposing company/copyright\n";
