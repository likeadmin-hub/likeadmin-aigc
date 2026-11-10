<?php
/** Isolated SQLite and synthetic audio only; no real database, storage or provider calls. */
namespace app\common\service\open_platform {
    final class OpenSafeHttp
    {
        public static array $bodies = [];
        public static function get(string $url, int $maxBytes, int $timeout): array
        {
            if (!isset(self::$bodies[$url])) throw new \RuntimeException('Unknown fixture URL');
            return ['body' => self::$bodies[$url]];
        }
    }
}
namespace app\common\service\storage {
    final class StorageConfigService
    {
        public static function currentTenantId(): int { return 0; }
        public static function getStoredFileConfig(?int $tenantId, string $scope, string $engine): array
        {
            return ['engine' => [$engine => ['private_access' => false]]];
        }
    }
}
namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    (new \think\App(dirname(__DIR__)))->initialize();
    set_exception_handler(static function (\Throwable $e): void {
        fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
    });
    $config = new \think\Config();
    $config->set(['default' => 'fixture', 'connections' => ['fixture' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
    ]]], 'database');
    \think\facade\Db::setConfig($config);
    foreach (['tenant_file', 'file'] as $table) {
        \think\facade\Db::execute('CREATE TABLE la_' . $table . ' (id INTEGER PRIMARY KEY, tenant_id INTEGER, uri TEXT, storage_scope TEXT, storage_engine TEXT, storage_domain TEXT, delete_time INTEGER DEFAULT NULL)');
    }
    $uri = 'uploads/cloud-duration-regression.wav';
    $row = ['tenant_id' => 2, 'uri' => $uri, 'storage_scope' => 'tenant',
        'storage_engine' => 'aliyun', 'storage_domain' => 'https://original-storage.invalid'];
    \think\facade\Db::name('tenant_file')->insert($row);
    \think\facade\Db::name('tenant_file')->insert(array_replace($row, [
        'tenant_id' => 3, 'storage_domain' => 'https://other-tenant.invalid',
    ]));
    $pcm = str_repeat("\0", 46080); // 2.88 seconds, mono PCM16 at 8 kHz.
    $wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    \app\common\service\open_platform\OpenSafeHttp::$bodies = [
        'https://original-storage.invalid/' . $uri => $wav,
        'https://other-tenant.invalid/' . $uri => 'unreadable audio',
    ];
    $method = new \ReflectionMethod(\app\common\service\app\image_human\ImageHumanService::class, 'detectAudioDurationFromUri');
    $checks = [
        'cloud relative URI preserves fractional duration' => [$uri, 2, 2.88],
        'leading slash resolves stored metadata' => ['/' . $uri, 2, 2.88],
        'absolute cloud URL remains supported' => ['https://original-storage.invalid/' . $uri, 2, 2.88],
        'another tenant cannot use the first tenant storage' => [$uri, 3, 0.0],
        'unknown audio remains unmeasurable' => ['uploads/missing.wav', 2, 0.0],
        'empty audio remains unmeasurable' => ['', 2, 0.0],
    ];
    foreach ($checks as $name => [$input, $tenant, $expected]) {
        $actual = $method->invoke(null, $input, $tenant);
        if (abs($actual - $expected) > 0.000001) throw new \RuntimeException("FAIL $name: expected $expected, got $actual");
        echo "PASS $name\n";
    }
    \think\facade\Db::name('tenant_file')->delete(true);
    \think\facade\Db::name('file')->insert(array_replace($row, ['storage_scope' => 'platform']));
    if (abs($method->invoke(null, $uri, 2) - 2.88) > 0.000001) throw new \RuntimeException('FAIL platform upload storage fallback');
    echo "PASS platform upload storage fallback\n";
}
