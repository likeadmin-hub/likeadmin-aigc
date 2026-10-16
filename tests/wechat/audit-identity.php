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
        $row = new Versions(['id' => 10, 'tenant_id' => 7, 'authorizer_id' => 3, 'upload_mode' => 'template', 'version' => '1.0.401', 'description' => '启动诊断测试 defaf28', 'experience_status' => 'success', 'audit_status' => 'none', 'release_status' => 'none', 'create_time' => 1700000000]);
        Versions::$rows = [$row];
        Accounts::$rows = [new Accounts(['id' => 3, 'tenant_id' => 7, 'authorizer_type' => 'miniprogram', 'authorization_status' => 1, 'access_token_ciphertext' => 'fixture-token', 'access_token_expire_time' => time() + 7200])];
        \app\common\model\wechat\WechatMnpReview::$rows = []; Http::$calls = []; Http::$replies = [];
        return $row;
    }
    function audit(array $changes = []): array {
        return array_replace(['errcode' => 0, 'auditid' => 763884086, 'status' => 0,
            'user_version' => '1.0.401', 'user_desc' => '启动诊断测试 defaf28', 'submit_audit_time' => 1700000100], $changes);
    }
    function submissionReplies(array $latest): array {
        return [$latest, ['errcode' => 0, 'category_list' => [['first_id' => 1, 'first_class' => '工具']]],
            ['errcode' => 0, 'page_list' => ['pages/index/index']], ['errcode' => 0, 'auditid' => 900000001]];
    }
    foreach ([['user_version' => '1.0.391'], ['user_desc' => '旧构建'], ['submit_audit_time' => 1699999999],
        ['user_version' => '', 'user_desc' => '', 'submit_audit_time' => 0]] as $changes) {
        $row = seed(); Http::$replies = submissionReplies(audit($changes));
        $result = Service::submitAudit(7, 10);
        check($result['audit_status'] === 'pending', 'Old or unidentifiable audit must not approve the new version');
        check(array_column(Http::$calls, 1) === ['wxa/get_latest_auditstatus','wxa/get_category','wxa/get_page','wxa/submit_audit'], 'The new version must actually be submitted to WeChat');
        check(\app\common\model\wechat\WechatMnpReview::$rows[0]['audit_no'] === '900000001', 'Only the new audit ID may be attached');
    }
    foreach ([0 => 'approved', 2 => 'pending'] as $status => $expected) {
        $row = seed(); Http::$replies = [audit(['status' => $status])];
        check(Service::submitAudit(7,10)['audit_status'] === $expected, 'Matching lost submission must recover');
        check(count(Http::$calls) === 1, 'Matching recovery must not submit twice');
    }
    $row = seed(); $row['audit_status'] = 'approved';
    $bad = new \app\common\model\wechat\WechatMnpReview(['id'=>1,'version_id'=>10,'audit_no'=>'763884086','audit_status'=>'approved','detail'=>json_encode(audit(['user_version'=>'1.0.391']))]);
    \app\common\model\wechat\WechatMnpReview::$rows = [$bad];
    Http::$replies = [audit(['user_version'=>'1.0.391'])];
    try { Service::releaseVersion(7,10); throw new RuntimeException('False approval published an older WeChat audit'); }
    catch (Throwable $e) { check(!str_contains($e->getMessage(),'False approval'),$e->getMessage()); }
    check(!in_array('wxa/release',array_column(Http::$calls,1),true), 'Mismatched audit must never reach release');
    check($row['audit_status'] === 'none' && $bad['audit_status'] === 'none', 'Persisted false approval must be repaired');
    check($bad['audit_no'] === '763884086', 'Historical audit evidence must remain available');

    $row = seed(); $row['audit_status'] = 'pending';
    Http::$replies = submissionReplies(audit(['user_version'=>'1.0.391']));
    check(Service::submitAudit(7,10)['audit_status'] === 'pending' && count(Http::$calls) === 4, 'False pending state must not suppress a real submission');
    $row = seed(); $row['audit_status'] = 'approved';
    $bad = new \app\common\model\wechat\WechatMnpReview(['id'=>1,'version_id'=>10,'audit_no'=>'763884086','audit_status'=>'approved','detail'=>json_encode(audit(['user_version'=>'1.0.391']))]);
    \app\common\model\wechat\WechatMnpReview::$rows = [$bad];
    try { Service::queryAudit(7,10); throw new RuntimeException('Old audit query restored false approval'); }
    catch (Throwable $e) { check(!str_contains($e->getMessage(),'Old audit query'),$e->getMessage()); }
    check($row['audit_status'] === 'none' && Http::$calls === [], 'Audit query must clear the proven invalid association');
    $row = seed(); Versions::$rows[] = new Versions(array_replace($row->toArray(),['id'=>11]));
    try { Service::submitAudit(7,10); throw new RuntimeException('Historical row submitted current experience'); }
    catch (Throwable $e) { check(!str_contains($e->getMessage(),'Historical row'),$e->getMessage()); }
    check(Http::$calls === [], 'Older experience cannot create an audit for newer uploaded code');
    echo "PASS: audit identity, real submission, lost-response recovery, false approval repair and release guard\n";
}
