<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (PHP_SAPI !== 'cli-server') throw new RuntimeException('Test HTTP router requires isolated CLI server');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Defense in depth: this test server cannot reach any generation endpoint.
if (!preg_match('#^/api/app\.aigc_short_drama\.canvas/(current|create|save|lists)$#D', (string)$path)) {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', app()->getRootPath() . 'public');
$http = app()->http;
$response = $http->run();
$response->send();
$http->end($response);
