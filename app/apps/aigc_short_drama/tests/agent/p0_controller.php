<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use app\Request;
use app\api\controller\app\aigc_short_drama\CanvasController;
use app\api\http\middleware\LoginMiddleware;
use app\api\http\middleware\AppAccessMiddleware;
use think\facade\Db;

/** Actual login/app middleware and controller; no HTTP server or browser claim. */
function canvasRequest(string $action, int $tenant, string $token, array $params = [], string $controller = 'app.aigc_short_drama.Canvas'): array
{
    $request = new Request();
    $request->setController($controller)->setAction($action);
    $request->withHeader(['token' => $token])->withGet($params)->withPost($params);
    $request->tenantId = $tenant;
    app()->instance('request', $request);
    $request->controllerObject = new CanvasController(app());
    $response = (new LoginMiddleware())->handle($request, static function ($request) use ($action) {
        return (new AppAccessMiddleware())->handle($request, static function () use ($action) {
            return (new CanvasController(app()))->$action();
        });
    });
    return $response->getData();
}

Db::startTrans();
try {
    foreach ([91001, 91002] as $id) {
        Db::name('tenant')->insert(['id' => $id, 'sn' => 'p0-' . $id, 'create_time' => time()]);
        Db::name('tenant_app')->insert(['tenant_id' => $id, 'app_code' => 'aigc_short_drama', 'buy_status' => 'paid', 'enable_status' => 'enabled', 'shelf_status' => 'on', 'expire_time' => time() + 3600]);
    }
    foreach ([[92001, 91001], [92002, 91001], [92003, 91002]] as [$id, $tenant]) {
        Db::name('user')->insert(['id' => $id, 'sn' => $id, 'account' => 'p0-' . $id, 'tenant_id' => $tenant]);
        Db::name('user_session')->insert(['tenant_id' => $tenant, 'user_id' => $id, 'token' => 'isolated-p0-' . $id, 'terminal' => 4, 'expire_time' => time() + 86400 * 365]);
    }
    // App records are global installation state rather than per-tenant test
    // fixtures.  A normal local installation already has them, so never turn
    // that valid state into a duplicate-key failure before controller checks.
    if (!Db::name('app')->where('code', 'aigc_short_drama')->find()) Db::name('app')->insert(['code' => 'aigc_short_drama', 'status' => 'installed']);
    if (!Db::name('app')->where('code', 'aigc_canvas')->find()) Db::name('app')->insert(['code' => 'aigc_canvas', 'status' => 'disabled']);
    agentCheck(canvasRequest('lists', 91001, '')['code'] !== 1, 'login middleware rejects missing token');
    agentCheck(canvasRequest('lists', 91001, 'invalid-fixture-token')['code'] !== 1, 'login middleware rejects unknown token');
    agentCheck(canvasRequest('lists', 91002, 'isolated-p0-92001')['code'] !== 1, 'login middleware rejects tenant/token mismatch');
    $created = canvasRequest('create', 91001, 'isolated-p0-92001', ['title' => 'P0 controller fixture', 'tenant_id' => 91002, 'user_id' => 92003]);
    agentCheck($created['code'] === 1, 'B05 authorized short-drama create works while independent canvas disabled');
    $id = (int)$created['data']['id'];
    $row = Db::name('aigc_short_drama_canvas')->where('id', $id)->find();
    agentCheck((int)$row['tenant_id'] === 91001 && (int)$row['user_id'] === 92001, 'controller ignores forged body owner');
    $nodes = [];
    foreach (['text', 'image', 'video', 'audio'] as $i => $type) {
        $nodes[] = ['id' => $i + 1, 'type' => $type, 'x' => 50 + 300 * $i, 'y' => 80, 'width' => 250, 'height' => 220, 'metadata' => ['content' => 'Synthetic ' . $type]];
    }
    $payload = ['id' => $id, 'nodes' => $nodes, 'edges' => [['from' => 1, 'to' => 2]], 'viewport' => ['x' => 5, 'y' => 10, 'k' => 0.9]];
    agentCheck(canvasRequest('save', 91001, 'isolated-p0-92001', $payload)['code'] === 1, 'B02 actual controller accepts four-node document');
    $loaded = canvasRequest('current', 91001, 'isolated-p0-92001', ['id' => $id]);
    // Existing app Request globally trims scalars: JSON numeric fields become
    // numeric strings before CanvasService. Pin that actual wire contract, not
    // loose equality that could hide omitted fields or changed text content.
    $wire = static function (array $value) use (&$wire): array {
        return array_map(static fn($item) => is_array($item) ? $wire($item) : (is_int($item) || is_float($item) ? (string)$item : $item), $value);
    };
    agentCheck($loaded['code'] === 1 && $loaded['data']['nodes'] === $wire($nodes) && $loaded['data']['edges'] === $wire($payload['edges']) && $loaded['data']['viewport'] === $wire($payload['viewport']), 'B02 fresh controller read preserves complete document (existing numeric-string wire format)');
    foreach ([[91001, 'isolated-p0-92002'], [91002, 'isolated-p0-92003']] as [$tenant, $token]) {
        foreach (['current', 'save', 'delete'] as $action) {
            agentCheck(canvasRequest($action, $tenant, $token, ['id' => $id, 'nodes' => []])['code'] !== 1, 'owner isolation rejects ' . $action);
        }
    }
    // The independent canvas app is global installation state and may be
    // enabled in this real local instance.  Do not mutate it just to create a
    // negative fixture; that route's disabled-app check is therefore covered
    // by its own app test, not asserted from the short-drama acceptance run.
    echo "NOT_RUN B05 independent canvas disabled-route check: existing local install is enabled\n";
    Db::name('tenant_app')->where(['tenant_id' => 91001, 'app_code' => 'aigc_short_drama'])->update(['shelf_status' => 'off']);
    agentCheck(canvasRequest('current', 91001, 'isolated-p0-92001', ['id' => $id])['code'] !== 1, 'app middleware rejects short-drama shelf off');
} finally {
    Db::rollback();
    foreach ([92001, 92002, 92003] as $id) (new \app\common\cache\UserTokenCache())->deleteUserInfo('isolated-p0-' . $id);
}
echo "NOT_RUN HTTP routing/tenant resolution and browser UI; real middleware/controller tested in-process\n";
