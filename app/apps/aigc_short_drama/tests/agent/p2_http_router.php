<?php
declare(strict_types=1);
$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__,5).'/public/index.php';
$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';
require __DIR__.'/bootstrap.php';
if (PHP_SAPI!=='cli-server') throw new RuntimeException('Test HTTP router requires isolated CLI server');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if (!preg_match('#^/api/app\.aigc_short_drama\.canvas_agent/(threads|preferences|savePreferences|createThread|messages|events|send|stop|run|stream|workflow|answerWorkflow|confirmWorkflowPlan|confirmWorkflowStage)$#D',(string)$path)) {http_response_code(404);exit;}
define('ROOT_PATH',app()->getRootPath().'public');
$http=app()->http;$response=$http->run();$response->send();$http->end($response);
