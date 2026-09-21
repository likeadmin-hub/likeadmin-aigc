<?php
declare(strict_types=1);
// Match the deployed front controller; ThinkPHP multi-app resolves the app
// from SCRIPT_FILENAME before processing PATH_INFO.
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 5) . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/bootstrap.php';
if (PHP_SAPI !== 'cli-server') throw new RuntimeException('Test HTTP router requires isolated CLI server');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Defense in depth: this test server cannot reach any generation endpoint.
$actions=defined('SHORT_DRAMA_BROWSER_MOCK_PROVIDER')?'current|create|save|lists|patch|run|task':'current|create|save|lists|patch';
$resourceRead=$_SERVER['REQUEST_METHOD']==='GET' && in_array($path,['/api/app.aigc_short_drama.asset/lists','/api/app.aigc_canvas.project/lists'],true);
if (!$resourceRead && !preg_match('#^/api/app\.aigc_short_drama\.canvas/('.$actions.')$#D', (string)$path)) {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', app()->getRootPath() . 'public');
$http = app()->http;
$response = $http->run();
$response->send();
$http->end($response);
