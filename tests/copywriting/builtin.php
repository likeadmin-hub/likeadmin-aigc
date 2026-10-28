<?php
require __DIR__.'/contract.php';
use app\common\service\app\aigc_copywriting\CopywritingSeeder as S;
check(class_exists(S::class),'builtins are not implemented');
$tools=S::catalog();$expected=['copy_rewrite','risk_optimize','original_rewrite','typo_check','smart_write','sales_copy','spoken_script','video_title','copy_create','xiaohongshu_article','wechat_article','copy_extract'];
check(count($tools)===12,'exactly twelve builtins');check(array_diff($expected,array_column($tools,'code'))===[],'all builtins present');
foreach($tools as $tool){check(app\common\service\app\aigc_copywriting\CopywritingDocument::validate($tool['document'])===[], 'invalid builtin '.$tool['code']);check(count($tool['document']['fields'])>=3,'complete fields');}
