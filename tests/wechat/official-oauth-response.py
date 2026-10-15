#!/usr/bin/env python3
"""Prove real local PHP-FPM sends the response before HttpEnd media work."""
import json
import pathlib
import secrets
import time
import urllib.request

root = pathlib.Path(__file__).resolve().parents[2]
key = secrets.token_hex(16)
fixture = root / 'public' / ('__oauth-response-proof-' + key + '.php')
marker = root / 'runtime' / ('oauth-response-proof-' + key + '.json')
php_marker = '/www/wwwroot/likeadmin_aigc_saas/server/runtime/' + marker.name
script = '''<?php
if (($_SERVER['HTTP_X_OAUTH_PROOF'] ?? '') !== '__KEY__') {http_response_code(404);exit;}
$site='/www/wwwroot/likeadmin_aigc_saas/server';
require $site.'/runtime-loader.php';(new AigcRuntimeApp($site))->initialize();
app()->event->listen('HttpEnd', static function () {
    usleep(2500000);
    file_put_contents('__MARKER__', json_encode(['deferred_work_completed'=>true]));
});
$response=think\Response::create(['code'=>1,'proof'=>'response-before-avatar-work'],'json');
$response->send();app()->http->end($response);
'''.replace('__KEY__', key).replace('__MARKER__', php_marker)
try:
    fixture.write_text(script)
    started = time.monotonic()
    req = urllib.request.Request('http://127.0.0.1/' + fixture.name,
                                 headers={'Host':'z.cn', 'X-OAuth-Proof':key})
    with urllib.request.urlopen(req, timeout=5) as response:
        data = json.load(response)
    elapsed = time.monotonic() - started
    assert data['proof'] == 'response-before-avatar-work'
    assert elapsed < 1.5, f'Response still waited for deferred work: {elapsed:.2f}s'
    assert not marker.exists(), 'Deferred work completed before response was received'
    deadline = time.monotonic() + 5
    while not marker.exists() and time.monotonic() < deadline:
        time.sleep(.1)
    assert json.loads(marker.read_text())['deferred_work_completed']
    print(f'OAuth PHP-FPM response returned in {elapsed:.3f}s before 2.5s HttpEnd work; no user or network I/O')
finally:
    fixture.unlink(missing_ok=True)
    marker.unlink(missing_ok=True)
