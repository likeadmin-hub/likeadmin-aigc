<?php
declare(strict_types=1);

// Separate PHP processes reproduce ThinkPHP's api/index runtime/cache boundary.
if (($argv[1] ?? '') === '--phase') {
    $root = dirname(__DIR__, 2);
    require $root . '/runtime-loader.php';
    $app = new AigcRuntimeApp($root);
    $app->initialize();
    $phase = $argv[2];
    $app->setRuntimePath($app->getRuntimePath() . ($phase === 'start' || $phase === 'consume' ? 'api/' : 'index/'));
    \think\facade\Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => '', 'prefix' => 'oauth_cache_acceptance']]], 'cache');
    \think\facade\Cache::forgetDriver('file');
    $service = new class extends \app\common\service\wechat\OfficialAccountOAuthRelayService {
        protected function identity(int $tenantId): array
        {
            return ['authorizer_appid' => 'wx1234567890abcdef', 'component_appid' => 'wxabcdef1234567890', 'platform_origin' => 'https://z.cn'];
        }
        protected function exchange(int $tenantId, string $code): array { return ['openid' => 'test-cache-only']; }
    };
    try {
        $value = match ($phase) {
            'start' => $service->start(7, 'https://tenant.example.com/mobile/pages/login/login?scene=login', 'https://tenant.example.com'),
            'launch' => $service->launch($argv[3], 'https://z.cn'),
            'complete' => $service->complete($argv[3], 'fixture-code', $argv[3], 'https://z.cn'),
            'consume' => $service->consume(7, $argv[3], $argv[4]),
        };
        echo json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit(1); }
    exit;
}

function phase(string $name, string ...$args)
{
    $process = proc_open(array_merge([PHP_BINARY, __FILE__, '--phase', $name], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode($output, true);
    if ($exit !== 0 || !($result['ok'] ?? false)) throw new RuntimeException($name . ' failed: ' . ($result['error'] ?? $error));
    return $result['value'];
}
$start = phase('start');
parse_str((string)parse_url($start['url'], PHP_URL_QUERY), $query);
$wechatUrl = phase('launch', $query['state']);
if (!str_contains($wechatUrl, 'open.weixin.qq.com')) throw new RuntimeException('Missing WeChat launch URL');
$return = phase('complete', $query['state']);
parse_str((string)parse_url($return, PHP_URL_QUERY), $query);
$user = phase('consume', $query['code'], $start['browser_nonce']);
if ($user['openid'] !== 'test-cache-only') throw new RuntimeException('Shared cache lost the fixture identity');
echo "OAuth cross-application cache: API → index → API passed (4 separate PHP processes; no WeChat network)\n";
