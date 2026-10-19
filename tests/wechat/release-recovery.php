<?php
declare(strict_types=1);

// Exercise the real release service and request parser without WeChat or database writes.
namespace tests\wechat {
    class Record extends \ArrayObject {
        public function isEmpty(): bool { return count($this) === 0; }
        public function toArray(): array { return $this->getArrayCopy(); }
        public function save(array $data): void { foreach ($data as $key => $value) $this[$key] = $value; }
    }
    class Query {
        private array $filters = [];
        public function __construct(private array $rows, private string $recordClass = Record::class) {}
        public function where($key, $value = null): self { $this->filters += is_array($key) ? $key : [$key => $value]; return $this; }
        public function order(string $order): self { usort($this->rows, fn($a, $b) => $b['id'] <=> $a['id']); return $this; }
        public function findOrEmpty(?int $id = null): Record {
            foreach ($this->rows as $row) {
                if ($id !== null && $row['id'] !== $id) continue;
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) !== $value) continue 2;
                return $row;
            }
            return new $this->recordClass();
        }
        public function value(string $key) { return $this->findOrEmpty()[$key] ?? null; }
    }
}
namespace app\common\model\wechat {
    class WechatMnpReview extends \tests\wechat\Record {
        public static array $rows = [];
        public static function withoutGlobalScope(): \tests\wechat\Query { return new \tests\wechat\Query(self::$rows, self::class); }
        public function save(array $data): void {
            parent::save($data);
            if (!isset($this['id'])) { $this['id'] = count(self::$rows) + 1; self::$rows[] = $this; }
        }
    }


    class WechatMnpVersion extends \tests\wechat\Record {
        public static array $rows = [];
        public static function withoutGlobalScope(): \tests\wechat\Query { return new \tests\wechat\Query(self::$rows, self::class); }
    }
    class WechatAuthorizer extends \tests\wechat\Record {
        public static array $rows = [];
        public static function withoutGlobalScope(): \tests\wechat\Query { return new \tests\wechat\Query(self::$rows, self::class); }
    }
}
namespace WpOrg\Requests {
    class Requests {
        public static array $replies = [], $calls = [];
        private static function send(string $method, string $url, ?string $body = null): object {
            $path = ltrim((string)parse_url($url, PHP_URL_PATH), '/');
            self::$calls[] = [$method, $path, $body];
            $reply = array_shift(self::$replies);
            if ($reply === null) throw new \RuntimeException('Unexpected HTTP request: ' . $path);
            if ($reply instanceof \Throwable) throw $reply;
            return (object)['body' => json_encode($reply, JSON_THROW_ON_ERROR)];
        }
        public static function post($url, $headers, $body, $options): object { return self::send('POST', $url, $body); }
        public static function get($url, $headers, $options): object { return self::send('GET', $url); }
    }
}
namespace {
    use app\common\model\wechat\{WechatMnpVersion as Versions, WechatAuthorizer as Accounts};
    use app\common\service\wechat\OpenPlatformService as Service;
    use WpOrg\Requests\Requests as Http;
    use tests\wechat\Record;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    function runtime_path(): string { return sys_get_temp_dir() . '/wechat-release-fixture/'; }
    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    function seed(): Record {
        $row = new Versions(['id' => 10, 'tenant_id' => 7, 'authorizer_id' => 3, 'upload_mode' => 'template', 'version' => '1.0.401', 'description' => '启动诊断测试 defaf28', 'experience_status' => 'success', 'audit_status' => 'approved', 'release_status' => 'none', 'create_time' => 1700000000]);
        Versions::$rows = [$row];
        Accounts::$rows = [new Accounts(['id' => 3, 'tenant_id' => 7, 'authorizer_type' => 'miniprogram', 'authorization_status' => 1, 'access_token_ciphertext' => 'fixture-token', 'access_token_expire_time' => time() + 7200])];
        \app\common\model\wechat\WechatMnpReview::$rows = []; Http::$calls = []; Http::$replies = [];
        return $row;
    }
    function audit(): array { return ['errcode'=>0,'auditid'=>763884086,'status'=>0,'user_version'=>'1.0.401','user_desc'=>'启动诊断测试 defaf28','submit_audit_time'=>1700000100]; }
    function alreadyReleased(): array { return ['errcode' => 85052, 'errmsg' => 'app is already released rid: fixture']; }
    function online(array $changes = []): array { return ['errcode' => 0, 'release_info' => array_replace(['release_version' => '1.0.401', 'release_desc' => '启动诊断测试 defaf28', 'release_time' => 1700001000], $changes)]; }
    function rejected(Record $row, string $message): void {
        try { Service::releaseVersion(7, 10); } catch (Throwable $e) {
            check($row['release_status'] === 'none', $message . ': incorrectly marked released');
            return;
        }
        throw new RuntimeException($message . ': unexpectedly succeeded');
    }

    $row = seed(); Http::$replies = [audit(), alreadyReleased(), online()];
    $result = Service::releaseVersion(7, 10);
    check($result['release_status'] === 'released' && $row['release_status'] === 'released', 'Already released must reconcile the matching online version');
    check(Http::$calls === [['GET','wxa/get_latest_auditstatus',null], ['POST', 'wxa/release', '{}'], ['POST', 'wxa/getversioninfo', '{}']], 'Release recovery must query the online version');
    Service::releaseVersion(7, 10);
    check(count(Http::$calls) === 3, 'Repeated release must not call WeChat again');

    foreach ([['release_version' => '1.0.391'], ['release_desc' => '另一次构建'], ['release_time' => 1699999999], ['release_version' => ''], ['release_time' => 0]] as $changes) {
        $row = seed(); Http::$replies = [audit(), alreadyReleased(), online($changes)]; rejected($row, 'Mismatched online identity');
    }
    $row = seed(); Http::$replies = [audit(), alreadyReleased(), ['errcode' => 0]]; rejected($row, 'Missing online release');
    $row = seed(); $row['description'] = '';
    Http::$replies = [audit(), alreadyReleased(), ['errcode' => 0, 'release_info' => ['release_version' => '1.0.401', 'release_time' => 1700001000]]];
    rejected($row, 'Missing description cannot establish identity even for an empty local description');
    $row = seed(); Http::$replies = [audit(), alreadyReleased(), new RuntimeException('network timeout')]; rejected($row, 'Failed verification');
    $row = seed(); Http::$replies = [audit(), ['errcode' => 85020, 'errmsg' => 'audit not ready']]; rejected($row, 'Other upstream rejection');
    check(count(Http::$calls) === 2, 'Other errors must not trigger recovery');

    $row = seed(); Http::$replies = [audit(), ['errcode' => 0]];
    check(Service::releaseVersion(7, 10)['release_status'] === 'released', 'Normal release must still succeed');

    $row = seed(); $newer = new Record(array_replace($row->toArray(), ['id' => 11])); Versions::$rows[] = $newer;
    Http::$replies = [audit(), ['errcode' => 0]]; rejected($row, 'Historical approved record');
    check(Http::$calls === [], 'Historical approved records must not publish the latest WeChat audit');

    $row = seed(); Versions::$rows[] = new Record(array_replace($row->toArray(), ['id' => 11, 'tenant_id' => 8]));
    Versions::$rows[] = new Record(array_replace($row->toArray(), ['id' => 12, 'authorizer_id' => 4]));
    Http::$replies = [audit(), ['errcode' => 0]];
    check(Service::releaseVersion(7, 10)['release_status'] === 'released', 'Latest approved check must be scoped to tenant and authorizer');

    $row = seed();
    $lock = \app\common\service\SubmitLockService::acquire('wechat.version.release.authorizer.3', 7, 0);
    try { rejected($row, 'Concurrent release for the same account'); }
    finally { \app\common\service\SubmitLockService::release($lock); }
    check(Http::$calls === [], 'Concurrent release must not reach WeChat');

    $row = seed(); $row['authorizer_id'] = 2; rejected($row, 'Old authorization');
    check(Http::$calls === [], 'Old authorization must never reach WeChat');
    $row = seed(); $row['audit_status'] = 'pending'; rejected($row, 'Pending audit');
    check(Http::$calls === [], 'Pending audit must never reach WeChat');
    echo "PASS: release recovery, identity mismatch, failure preservation, idempotency, history and tenant isolation\n";
}
