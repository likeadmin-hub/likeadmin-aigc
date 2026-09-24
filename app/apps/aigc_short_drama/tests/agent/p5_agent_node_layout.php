<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;

$layoutMethod=new ReflectionMethod(GraphService::class,'agentNodeLayout');
$layoutMethod->setAccessible(true);
$sizeMethod=new ReflectionMethod(GraphService::class,'agentNodeSize');
$sizeMethod->setAccessible(true);
$layout=$layoutMethod->invoke(null,
    [['x'=>10,'y'=>20,'width'=>300,'height'=>280]],
    [
        ['type'=>'image','artifact'=>'subject'],
        ['type'=>'image','artifact'=>'three_view'],
        ['type'=>'image','artifact'=>'subject'],
        ['type'=>'image','artifact'=>'three_view'],
    ],
    '9:16'
);
agentCheck(count($layout)===4,'Agent layout retains every proposal');
agentCheck($layout[0]['x']===430.0 && $layout[0]['y']===0.0,'new batch clears the right edge of existing nodes');
agentCheck($layout[0]['width']===250 && $layout[0]['height']===444,'9:16 preview matches selected portrait ratio');
agentCheck(count(array_unique(array_column($layout,'x')))===1,'all generated artifacts align in one column');
agentCheck($layout[1]['y'] >= $layout[0]['y']+$layout[0]['height']+80,'generation queue follows proposal order without overlap');
agentCheck($layout[2]['y'] >= $layout[1]['y']+$layout[1]['height']+80,'generated nodes have a consistent vertical gap');

$textLayout=$layoutMethod->invoke(null,[],[
    ['type'=>'text','artifact'=>'story'],['type'=>'text','artifact'=>'episode'],
],'9:16');
agentCheck($textLayout[0]['x']===$textLayout[1]['x'] && $textLayout[0]['y']<$textLayout[1]['y'],'existing story text-card layout is preserved');

$landscape=$sizeMethod->invoke(null,'video','16:9');
agentCheck($landscape===[444,250],'video preview matches selected landscape ratio');
agentCheck($sizeMethod->invoke(null,'text','9:16')===[320,280],'story text remains readable and ratio-free');
agentCheck($sizeMethod->invoke(null,'image','invalid')===[420,320],'unknown ratio keeps legacy fallback');
echo "OK Agent node queue and aspect-ratio geometry\n";
