<?php
// Pure source reconstruction; no database, model or network.
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_llm\agent\AgentTextSegmenter as S;
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$paragraph=str_repeat('甲',3500)."\n\n".str_repeat('乙',1800).'。';
$page=S::page($paragraph,0);check($page['next_offset']===3502&&$page['boundary']==='paragraph','Paragraph split');
$heading=str_repeat('甲',3500)."\n".'## 保留此标题'."\n".str_repeat('乙',1800);
$page=S::page($heading,0);check($page['next_offset']===3501&&!str_contains($page['content'],'标题'),'Heading detached from body');
$table=str_repeat('甲',3500)."\n| 项目 | 金额 |\n| --- | --- |\n".str_repeat('乙',1500);
$page=S::page($table,0);check($page['next_offset']===3501,'Table header detached from first row');
$csv=str_repeat('a',3500).",b\n\"".str_repeat('中',350)."\n".str_repeat('文',350).'""quoted""'."\",value\nnext,row";
$page=S::page($csv,0,4000,'csv');check($page['next_offset']===3503&&$page['boundary']==='record','CSV split at quoted newline');
$sentence=str_repeat('甲',3600).'。'.str_repeat('乙',1600);
check(S::page($sentence,0)['next_offset']===3601,'Sentence boundary ignored');
$condition=str_repeat('甲',3500).'。不得海外投放；仅在取得书面授权后例外。'.str_repeat('乙',800);
$page=S::page($condition,0);check(str_contains($page['content'],'不得海外投放；仅在取得书面授权后例外。'),'Condition split at semicolon');
$longRow='项目'."\t".str_repeat('甲。',3000)."\n";
$page=S::page($longRow,0);check($page['forced_split']&&$page['next_offset']===4000,'Oversize table row not marked forced');
foreach([[$paragraph,'txt'],[$heading,'md'],[$table,'md'],[$csv,'csv'],[$sentence,'txt'],[$condition,'txt'],[$longRow,'docx'],[str_repeat('资料正文。',40000),'txt'],["\n😀\t段落\r\n\n",'txt'],['','txt']] as [$text,$format]){
 $all='';$offset=0;$pages=0;
 do{$part=S::page($text,$offset,4000,$format);check(mb_strlen($part['content'])<=4000,'Unbounded segment');$all.=$part['content'];$next=$part['next_offset'];check($next===null||$next>$offset,'Cursor stalled');$offset=$next;++$pages;}while($offset!==null);
 check($all===$text,'Lost/duplicated/normalized source');check($pages<=68,'Unexpected per-document call growth');
}
$hard=str_repeat('字',12000);check(S::page($hard,0)['forced_split'],'Long single clause not marked partial');
check(S::page($hard,4000)['offset']===4000&&S::page($hard,12000)['content']==='','Existing cursor not accepted');
check(S::page('abc',-1)['content']==='abc','Negative offset behavior changed');
echo "Structural segmentation passed: exact Unicode reconstruction, paragraph/heading/row/CSV quote/sentence boundaries, forced splits, 200000 chars and stable cursors; no model calls\n";
