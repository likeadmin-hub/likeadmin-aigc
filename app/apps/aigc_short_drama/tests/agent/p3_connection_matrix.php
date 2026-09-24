<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
use think\facade\Db;

$fixture=json_decode((string)file_get_contents(__DIR__.'/fixtures/p3_connection_matrix.json'),true,512,JSON_THROW_ON_ERROR);
$allowed=$fixture['reference_edges'] ?? null;
if (!is_array($allowed) || $allowed===[]) throw new RuntimeException('P3_CONNECTION_MATRIX_INVALID');
Db::startTrans();
try {
    foreach (array_keys($allowed) as $sourceType) foreach (array_keys($allowed) as $targetType) {
        $canvas=Canvas::create(91001,92001,['title'=>'P3 capability '.$sourceType.'-'.$targetType])['id'];
        Db::name(Graph::TABLE)->where('id',$canvas)->update(['nodes_json'=>json_encode([
            ['id'=>1,'type'=>$sourceType,'x'=>0,'y'=>0,'metadata'=>[]],
            ['id'=>2,'type'=>$targetType,'x'=>1,'y'=>1,'metadata'=>[]],
        ])]);
        $request=['request_key'=>'matrix-'.$sourceType.'-'.$targetType,'expected_revision'=>0,'operations'=>[['op'=>'add_edge','edge'=>['from'=>1,'to'=>2,'kind'=>'reference']]]];
        $expected=in_array($targetType,$allowed[$sourceType],true);
        try {
            $result=Graph::patch(91001,92001,$canvas,$request);
            agentCheck($expected && count($result['edges'])===1,'M01 server reference '.$sourceType.' -> '.$targetType);
        } catch (RuntimeException $error) {
            agentCheck(!$expected && $error->getMessage()==='EDGE_CAPABILITY_UNSUPPORTED','M01 server reference '.$sourceType.' -> '.$targetType);
        }
    }
    $canvas=Canvas::create(91001,92001,['title'=>'P3 annotation compatibility'])['id'];
    Db::name(Graph::TABLE)->where('id',$canvas)->update(['nodes_json'=>json_encode([
        ['id'=>1,'type'=>'video','x'=>0,'y'=>0,'metadata'=>[]],
        ['id'=>2,'type'=>'image','x'=>1,'y'=>1,'metadata'=>[]],
    ])]);
    $annotation=Graph::patch(91001,92001,$canvas,['request_key'=>'annotation','expected_revision'=>0,'operations'=>[['op'=>'add_edge','edge'=>['from'=>1,'to'=>2,'kind'=>'annotation']]]]);
    agentCheck(($fixture['annotation_edges'] ?? null)==='unrestricted' && count($annotation['edges'])===1,'annotation remains an unrestricted graph note, not a media reference');
} finally { Db::rollback(); }
echo "NOT_RUN selected-model generation, browser drag/drop and real Provider media generation\n";
