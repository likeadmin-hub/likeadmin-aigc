<?php

// Loopback-only protocol fixture: no credentials or requests reach Qiniu.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
header('Content-Type: application/json');
header('X-Reqid: qiniu-php82-fixture');

if ($path === '/' && isset($_FILES['file'])) {
    if (($_POST['key'] ?? '') === 'reject') {
        http_response_code(401);
        echo json_encode(['error' => 'fixture upload denied']);
    } else {
        echo json_encode(['key' => $_POST['key'], 'hash' => 'fixture-hash',
            'mime' => $_FILES['file']['type'], 'body' => base64_encode(file_get_contents($_FILES['file']['tmp_name']))]);
    }
} elseif (preg_match('#^/mkblk/(\d+)$#', $path, $match)) {
    if (strlen($body) !== (int)$match[1]) {
        http_response_code(400);
        echo json_encode(['error' => 'block size mismatch']);
    } else {
        echo json_encode(['ctx' => 'block-' . $match[1], 'crc32' => (int)sprintf('%u', crc32($body)),
            'expired_at' => time() + 3600]);
    }
} elseif (preg_match('#^/mkfile/(\d+)/mimeType/([^/]+)/key/([^/]+)#', $path, $match)) {
    echo json_encode(['size' => (int)$match[1], 'mime' => base64_decode(strtr($match[2], '-_', '+/')),
        'key' => base64_decode(strtr($match[3], '-_', '+/')), 'hash' => 'fixture-hash', 'contexts' => $body]);
} elseif (preg_match('#^/(?:fetch/[^/]+/to|delete)/([^/]+)$#', $path, $match)) {
    echo json_encode(['entry' => base64_decode(strtr($match[1], '-_', '+/')), 'hash' => 'fixture-hash']);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'unexpected storage fixture request']);
}
