<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;

$layout=(new ReflectionMethod(GraphService::class,'agentNodeLayout'))->invoke(null,
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
agentCheck($layout[0]['y']===$layout[2]['y'] && $layout[1]['y']===$layout[3]['y'],'same artifact kind shares one row');
agentCheck($layout[0]['x']<$layout[2]['x'] && $layout[1]['x']<$layout[3]['x'],'nodes advance horizontally within each row');
agentCheck($layout[1]['y'] >= $layout[0]['y']+$layout[0]['height']+120,'artifact rows do not overlap');

$landscape=(new ReflectionMethod(GraphService::class,'agentNodeSize'))->invoke(null,'video','16:9');
agentCheck($landscape===[444,250],'video preview matches selected landscape ratio');
agentCheck((new ReflectionMethod(GraphService::class,'agentNodeSize'))->invoke(null,'text','9:16')===[320,280],'story text remains readable and ratio-free');
agentCheck((new ReflectionMethod(GraphService::class,'agentNodeSize'))->invoke(null,'image','invalid')===[420,320],'unknown ratio keeps legacy fallback');
echo "OK Agent node rows and aspect-ratio geometry\n";
