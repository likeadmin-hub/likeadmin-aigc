<?php
/** Local transactional guard check; submits no provider request. */
require dirname(__DIR__, 4) . '/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_canvas\AigcCanvasService as Canvas;
$db=config('database.connections.mysql');
if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) throw new RuntimeException('Local database only');
$checks=0; Db::startTrans();
try {
    $project=Canvas::createProject(1,1,['name'=>'transactional-deleted-project-fixture']);
    Canvas::deleteProject(1,1,(int)$project['id']);
    foreach (['generateImage','generateVideo','generateMusic','generateText','streamText'] as $method) {
        try { Canvas::$method(1,1,['project_id'=>$project['id'],'prompt'=>'must not submit']); throw new RuntimeException('Deleted project accepted by '.$method); }
        catch (Exception $e) { if (!str_contains($e->getMessage(),'画布项目不存在或已删除')) throw $e; $checks++; }
    }
} finally { Db::rollback(); }
echo "Deleted project admission: {$checks} guards passed; transaction rolled back; no provider calls\n";
