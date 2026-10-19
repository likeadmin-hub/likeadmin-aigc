<?php
/** Isolated service execution: in-memory models, no database or provider calls. */
error_reporting(E_ALL & ~E_DEPRECATED);
class RegressionRow implements ArrayAccess {
    public static array $rows = [];
    public function __construct(public array $data = []) {}
    public static function where(...$args): RegressionQuery { return (new RegressionQuery(static::class))->where(...$args); }
    public static function alias(...$args): RegressionQuery { return new RegressionQuery(static::class); }
    public static function create(array $data): static { $data['id'] ??= count(static::$rows) + 1; static::$rows[$data['id']] = $data; return new static($data); }
    public function offsetExists($key): bool { return isset($this->data[$key]); }
    public function offsetGet($key): mixed { return $this->data[$key] ?? null; }
    public function offsetSet($key, $value): void { $this->data[$key] = $value; }
    public function offsetUnset($key): void { unset($this->data[$key]); }
    public function __get($key) { return $this->data[$key] ?? null; }
    public function __set($key, $value) { $this->data[$key] = $value; }
    public function toArray(): array { return $this->data; }
    public function isEmpty(): bool { return !$this->data; }
    public function save(): void { static::$rows[$this->data['id']] = $this->data; }
}
class RegressionCollection implements IteratorAggregate {
    public function __construct(private array $rows) {}
    public function getIterator(): Traversable { return new ArrayIterator($this->rows); }
    public function toArray(): array { return array_map(fn($row) => $row->toArray(), $this->rows); }
}
class RegressionQuery {
    private array $filters = [];
    private int $offset = 0, $size = 100;
    public function __construct(private string $model) {}
    private function column($key): string { return preg_replace('/^t\./', '', $key); }
    public function where($key, $value = null): static {
        if (is_array($key)) { foreach ($key as $k => $v) $this->where($k, $v); return $this; }
        $key = $this->column($key);
        $this->filters[] = fn($row) => ($row[$key] ?? null) == $value;
        return $this;
    }
    public function whereIn($key, $values): static { $key = $this->column($key); $this->filters[] = fn($row) => in_array($row[$key] ?? null, $values, true); return $this; }
    public function whereRaw($sql): static {
        if (preg_match('/user_id = (\d+)/', $sql, $match)) {
            $user = (int)$match[1];
            $this->filters[] = fn($row) => $row['source'] === 'official' || ($row['source'] === 'mine' && $row['user_id'] === $user);
        }
        return $this;
    }
    public function __call($name, $args): static { return $this; }
    public function limit($offset, $size = null): static { $this->offset = $size === null ? 0 : $offset; $this->size = $size ?? $offset; return $this; }
    private function data(): array { return array_values(array_filter($this->model::$rows, fn($row) => !array_filter($this->filters, fn($filter) => !$filter($row)))); }
    public function count(): int { return count($this->data()); }
    public function select(): RegressionCollection { return new RegressionCollection(array_map(fn($row) => new $this->model($row), array_slice($this->data(), $this->offset, $this->size))); }
    public function findOrEmpty(): RegressionRow { return new $this->model($this->data()[0] ?? []); }
}
class RegressionStyleTask extends RegressionRow { public static array $rows = []; }
class RegressionStyleResult extends RegressionRow { public static array $rows = []; }
class RegressionImageTask extends RegressionRow { public static array $rows = []; }
class RegressionVoice extends RegressionRow { public static array $rows = []; }
class RegressionTenantFile extends RegressionRow { public static array $rows = []; }
class RegressionFileService {
    public static function getFileUrlByStorage($uri, ...$args): string { return 'https://media.test/' . ltrim($uri, '/'); }
}
class RegressionStorage {
    public static function getEffectiveConfig($id): array { return ['scope' => 'tenant', 'default' => 'local']; }
    public static function getEffectiveDomain($id): string { return 'https://media.test'; }
}
class RegressionImageService {
    public static int $reads = 0;
    public static function taskDetail($tenant, $id, $user): array {
        self::$reads++;
        return RegressionImageTask::$rows[$id] + ['results' => [['id' => 71, 'image_uri' => 'finished.png', 'width' => 800, 'height' => 800]]];
    }
}
foreach ([
    RegressionStyleTask::class => 'app\\common\\model\\app\\aigc_style_transfer\\AigcStyleTransferTask',
    RegressionStyleResult::class => 'app\\common\\model\\app\\aigc_style_transfer\\AigcStyleTransferResult',
    RegressionImageTask::class => 'app\\common\\model\\app\\aigc_image\\AigcImageTask',
    RegressionImageService::class => 'app\\common\\service\\app\\aigc_image\\AigcImageService',
    RegressionVoice::class => 'app\\common\\model\\app\\aigc_digital_human\\AigcDigitalHumanVoice',
    RegressionTenantFile::class => 'app\\common\\model\\file\\TenantFile',
    RegressionFileService::class => 'app\\common\\service\\FileService',
    RegressionStorage::class => 'app\\common\\service\\storage\\StorageConfigService',
] as $fake => $real) class_alias($fake, $real);
require __DIR__ . '/../app/common/service/app/aigc_style_transfer/AigcStyleTransferService.php';
require __DIR__ . '/../app/common/service/app/aigc_digital_human/AigcDigitalHumanService.php';
require __DIR__ . '/../app/common/service/app/image_human/ImageHumanService.php';
use app\common\service\app\aigc_style_transfer\AigcStyleTransferService;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\common\service\app\image_human\ImageHumanService;
function check($condition, $message): void { if (!$condition) throw new RuntimeException($message); }
$failures = 0;
function runCase($name, $test): void { global $failures; try { $test(); echo "PASS $name\n"; } catch (Throwable $error) { $failures++; echo "FAIL $name: {$error->getMessage()}\n"; } }
function resetTasks(): void {
    RegressionStyleTask::$rows = [1 => ['id' => 1, 'tenant_id' => 2, 'user_id' => 9, 'status' => 'running', 'delete_time' => 0, 'image_task_id' => 51, 'image_task_ids' => [51], 'width' => 800, 'height' => 800, 'size_key' => '1:1']];
    RegressionStyleResult::$rows = [];
    RegressionImageTask::$rows = [51 => ['id' => 51, 'tenant_id' => 2, 'user_id' => 9, 'status' => 'success', 'delete_time' => 0, 'finish_time' => 100, 'tenant_cost_points' => 2, 'user_charge_points' => 3]];
    RegressionImageService::$reads = 0;
}
runCase('default task list reconciles completed image task and persists its work', function () {
    resetTasks();
    $data = AigcStyleTransferService::taskLists(2, 9, ['page_no' => 1, 'page_size' => 30]);
    check($data['lists'][0]['status'] === 'success', 'stale running task returned');
    check(count($data['lists'][0]['results']) === 1, 'finished image not exposed');
    check($data['lists'][0]['image_url'] === 'https://media.test/finished.png', 'missing playable image URL');
    AigcStyleTransferService::taskLists(2, 9);
    check(count(RegressionStyleResult::$rows) === 1, 'duplicate works after refresh');
});
runCase('opening works first reconciles before filtering successful tasks', function () {
    resetTasks();
    $data = AigcStyleTransferService::resultLists(2, 9);
    check($data['count'] === 1 && count($data['lists'][0]['results']) === 1, 'completed work hidden behind stale task status');
});
runCase('reconciliation is tenant and user scoped', function () {
    resetTasks();
    $data = AigcStyleTransferService::taskLists(2, 99);
    check($data['count'] === 0 && RegressionImageService::$reads === 0, 'read another user task');
    $data = AigcStyleTransferService::taskLists(3, 9);
    check($data['count'] === 0 && RegressionImageService::$reads === 0, 'read another tenant task');
});
runCase('explicit reconciliation opt-out remains read only', function () {
    resetTasks();
    $data = AigcStyleTransferService::taskLists(2, 9, ['sync_running' => 0]);
    check($data['lists'][0]['status'] === 'running' && RegressionImageService::$reads === 0, 'ignored sync_running=0');
});
RegressionVoice::$rows = [28 => ['id' => 28, 'tenant_id' => 2, 'user_id' => 0, 'source' => 'official', 'status' => 'ready', 'provider_asset_id' => 'speaker-28', 'preview_audio_uri' => 'preview.mp3', 'delete_time' => 0]];
RegressionTenantFile::$rows = [1 => ['id' => 1, 'tenant_id' => 2, 'uri' => 'preview.mp3']];
foreach ([AigcDigitalHumanService::class, ImageHumanService::class] as $service) {
    foreach (['id', 'voice_id'] as $field) runCase("$service accepts $field for cached voice preview", function () use ($service, $field) {
        $data = $service::previewVoice(2, 9, [$field => 28]);
        check($data['cached'] === true && $data['audio_url'] === 'https://media.test/preview.mp3', 'wrong cached preview');
    });
    runCase("$service denies cross-tenant preview", function () use ($service) {
        try { $service::previewVoice(3, 9, ['id' => 28]); } catch (Exception $error) { check(str_contains($error->getMessage(), '当前用户可用'), 'unexpected failure'); return; }
        throw new RuntimeException('cross-tenant voice accepted');
    });
    runCase("$service denies another user personal voice", function () use ($service) {
        RegressionVoice::$rows[28]['source'] = 'mine';
        RegressionVoice::$rows[28]['user_id'] = 77;
        try { $service::previewVoice(2, 9, ['id' => 28]); } catch (Exception $error) {
            check(str_contains($error->getMessage(), '当前用户可用'), 'unexpected failure');
            RegressionVoice::$rows[28]['source'] = 'official';
            return;
        }
        throw new RuntimeException('another user voice accepted');
    });
}
exit($failures ? 1 : 0);
