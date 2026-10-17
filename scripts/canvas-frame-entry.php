<?php
// Optional entry for a dedicated media FPM master. Keep the normal application
// bootstrap/authentication, and prevent query-string routing to another action.
$path = '/api/app.aigc_canvas.generate/captureFrame';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '') !== $path) {
    http_response_code(404);
    exit;
}
$_GET['s'] = $path;
$_SERVER['PATH_INFO'] = $path;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/public/index.php';
require dirname(__DIR__) . '/public/index.php';
