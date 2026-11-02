<?php
/** No database access: tenant_id=0 stops at the tenant guard after parsing. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});

use app\platformapi\controller\app\open_platform\ManagementController;
use app\common\service\open_platform\OpenManagement;
use app\common\service\open_platform\OpenException;

function checkAuthorization(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function authorizationResponse(array $payload, string $method = 'POST'): array
{
    $request = (new app\Request())->setMethod($method)->setAction('setTenantAuthorization')->withPost($payload);
    $controller = (new ReflectionClass(ManagementController::class))->newInstanceWithoutConstructor();
    foreach (['request' => $request, 'adminInfo' => ['admin_id' => 1, 'root' => 1]] as $name => $value) {
        (new ReflectionProperty(ManagementController::class, $name))->setValue($controller, $value);
    }
    return $controller->setTenantAuthorization()->getData();
}

// Characterize the application filter that caused the original JSON false failure.
$request = (new app\Request())->withPost(['enabled' => false]);
checkAuthorization($request->post('enabled') === '', 'Application trim behavior changed; review this regression');
foreach ([false, true, 0, 1, '0', '1'] as $value) {
    $response = authorizationResponse(['tenant_id' => 0, 'enabled' => $value]);
    checkAuthorization($response['msg'] === '租户不存在', 'Valid toggle rejected before tenant guard: ' . var_export($value, true) . ' => ' . $response['msg']);
}
foreach ([null, '', 'false', 'true', [], 2, ' 1 '] as $value) {
    $response = authorizationResponse(['tenant_id' => 0, 'enabled' => $value]);
    checkAuthorization($response['msg'] === '请传入有效 API 开关状态', 'Malformed toggle reached tenant guard');
}
checkAuthorization(authorizationResponse(['tenant_id' => 0])['msg'] === '请传入有效 API 开关状态', 'Missing toggle was accepted');
checkAuthorization(authorizationResponse(['enabled' => 0], 'GET')['msg'] === '仅支持 POST 请求', 'GET toggle was accepted');
foreach ([[false, false], [true, true], [0, false], [1, true], ['0', false], ['1', true]] as [$input, $expected]) {
    checkAuthorization(OpenManagement::authorizationInput($input) === $expected, 'Authorization input changed intent');
}
echo "PASS authorization controller preserves JSON booleans and numeric flags, rejects invalid/missing states and GET without database access\n";
