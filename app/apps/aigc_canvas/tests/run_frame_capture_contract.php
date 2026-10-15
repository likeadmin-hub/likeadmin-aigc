<?php
// Pure contract regression: no database, provider, FFmpeg or storage side effects.
declare(strict_types=1);

namespace think\facade {
    class Db {
        public static array $documents = [];
        public static array $files = [];
        public static array $results = [];
        private string $table = '';
        private array $in = [];
        private bool $notDeleted = false;
        public static array $queries = [];
        private array $filter = [];
        public static function name(string $name): self { if (!in_array($name, ['aigc_canvas_project', 'tenant_file', 'aigc_video_result'], true)) throw new \RuntimeException('Unexpected table'); $query = new self(); $query->table = $name; return $query; }
        public function whereIn(string $key, array $values): self { $this->in = [$key, $values]; return $this; }
        public function whereRaw(string $expression): self { $this->notDeleted = true; return $this; }
        public function where(array $filter): self { $this->filter = $filter; self::$queries[] = $filter; return $this; }
        public function find(): ?array {
            $rows = match ($this->table) { 'tenant_file' => self::$files, 'aigc_video_result' => self::$results, default => self::$documents };
            foreach ($rows as $document) {
                if ($this->in && !in_array($document[$this->in[0]] ?? null, $this->in[1], true)) continue;
                if ($this->notDeleted && !empty($document['delete_time'])) continue;
                if (count(array_intersect_assoc($document, $this->filter)) === count($this->filter)) return $document;
            }
            return null;
        }
    }
}
namespace app\common\enum { class FileEnum { const IMAGE_TYPE = 10; const VIDEO_TYPE = 20; const SOURCE_USER = 1; } }
namespace app\common\model\file {
    class TenantFile { public static array $records = []; public static function create(array $record): void { self::$records[] = $record; } }
}
namespace app\common\service\storage {
    class StorageConfigService {
        public static function getStoredFileConfig(?int $tenant, ?string $scope, ?string $engine): array { return ['scope' => 'tenant', 'default' => 'oss']; }
        public static function getStorageDomain(array $config): string { return 'https://managed.example'; }
    }
}
namespace app\common\service {
    class FileService { public static function getFileUrlByStorage(string $uri, string $scope, string $engine, string $domain): string { return $domain . '/' . $uri; } }
}
namespace app\common\service\app\aigc_video {
    class AigcVideoPosterService {
        public static array $calls = [];
        public static function createFrame(...$args): array {
            self::$calls[] = $args;
            return ['uri' => 'uploads/aigc_video/frames/result.jpg', 'storage_scope' => 'tenant', 'storage_engine' => 'oss',
                'storage_domain' => 'https://managed.example', 'width' => 160, 'height' => 90, 'mime_type' => 'image/jpeg', 'file_size' => 1024];
        }
    }
}
namespace {
    use app\common\service\app\aigc_canvas\AigcCanvasFrameService;
    use app\common\service\app\aigc_video\AigcVideoPosterService;
    use app\common\model\file\TenantFile;
    use think\facade\Db;
    require dirname(__DIR__, 4) . '/app/common/service/app/aigc_canvas/AigcCanvasFrameService.php';
    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function document(array $node, array $extra = []): array { return $extra + ['id' => 5, 'tenant_id' => 2, 'user_id' => 7, 'delete_time' => 0, 'nodes_json' => json_encode([$node])]; }
    function video(array $metadata = [], string $type = 'video'): array { return ['id' => 'old-video', 'type' => $type, 'metadata' => $metadata + ['video' => 'uploads/videos/source.mp4']]; }
    function capture(array $params = [], int $tenant = 2, int $user = 7): array { return AigcCanvasFrameService::capture($tenant, $user, $params + ['project_id' => 5, 'node_id' => 'old-video', 'time' => 0.5]); }
    function reject(callable $call, string $message): void {
        $count = count(AigcVideoPosterService::$calls);
        $failed = false;
        try { $call(); } catch (Exception $error) { $failed = true; }
        check($failed && count(AigcVideoPosterService::$calls) === $count, $message);
    }
    Db::$documents = [document(video())];
    $ownedFile = ['tenant_id' => 2, 'source_id' => 7, 'source' => 1, 'type' => 20, 'uri' => 'uploads/videos/source.mp4', 'delete_time' => null, 'storage_scope' => 'tenant', 'storage_engine' => 'oss', 'storage_domain' => 'https://managed.example'];
    Db::$files = [$ownedFile];
    $result = capture();
    check($result['width'] === 160 && $result['height'] === 90 && $result['url'] === 'https://managed.example/uploads/aigc_video/frames/result.jpg', 'Output must contain authorized preview and original dimensions');
    check(AigcVideoPosterService::$calls[0] === [2, 'uploads/videos/source.mp4', 'tenant', 'oss', 'https://managed.example', 0.5, 'frames'], 'Frame service must receive server-resolved storage');
    check(TenantFile::$records[0]['tenant_id'] === 2 && TenantFile::$records[0]['source_id'] === 7, 'Output must retain tenant and user ownership');
    echo "PASS owned video capture returns managed media\n";
    foreach ([0, -1, 'bad', 5.5] as $project) reject(fn() => capture(['project_id' => $project]), 'Invalid project must fail');
    foreach ([-0.01, 28801, INF, NAN, true, [], 'abc', null] as $time) reject(fn() => capture(['time' => $time]), 'Invalid time must fail');
    foreach (['url', 'source_url', 'uri', 'storage_domain', 'storage_engine', 'storage_scope', 'video_url'] as $key) reject(fn() => capture([$key => 'anything']), 'Request source override must fail');
    echo "PASS invalid requests never extract frames\n";
    foreach ([[3, 7], [2, 8], [0, 7], [2, 0]] as [$tenant, $user]) reject(fn() => capture([], $tenant, $user), 'Foreign ownership must fail');
    Db::$documents = [document(video(), ['delete_time' => 1])];
    reject(fn() => capture(), 'Deleted project must fail');
    Db::$documents = [document(video())];
    reject(fn() => capture(['node_id' => 'missing']), 'Missing node must fail');
    Db::$documents = [document(video([], 'image'))];
    reject(fn() => capture(), 'Nonvideo node must fail');
    echo "PASS project, tenant, user and node authorization\n";
    foreach (['https://evil.example/uploads/videos/source.mp4', 'http://127.0.0.1/uploads/x.mp4', 'blob:temporary', 'data:video/mp4,x', '//evil/uploads/x.mp4', 'uploads/../secret.mp4', 'uploads/%2e%2e/secret.mp4', 'uploads/%252e%252e/secret.mp4', 'uploads/a\\b.mp4', "uploads/a\0.mp4", 'uploads/image.png'] as $source) {
        Db::$documents = [document(video(['video' => $source]))];
        reject(fn() => capture(), 'Unsafe or unsupported media source must fail');
    }
    echo "PASS foreign URLs, traversal and unsupported media are rejected\n";
    Db::$documents = [document(video())];
    foreach ([['tenant_id' => 3], ['source_id' => 9], ['source' => 0], ['delete_time' => 1], ['type' => 10]] as $override) {
        Db::$files = [$override + $ownedFile];
        reject(fn() => capture(), 'Editable node metadata cannot grant access to another owner or deleted file');
    }
    Db::$files = [];
    reject(fn() => capture(), 'Unregistered source must fail closed');
    Db::$results = [['tenant_id' => 2, 'user_id' => 8, 'delete_time' => 0, 'video_uri' => 'uploads/videos/source.mp4']];
    reject(fn() => capture(), 'Foreign generated result must fail');
    Db::$results = [['tenant_id' => 2, 'user_id' => 7, 'delete_time' => 0, 'video_uri' => 'uploads/videos/source.mp4', 'storage_scope' => 'tenant', 'storage_engine' => 'oss']];
    capture();
    echo "PASS stored file and generated-result ownership is enforced independently of canvas metadata\n";
    Db::$files = [['uri' => 'uploads/legacy.mov'] + $ownedFile];
    Db::$results = [];
    Db::$documents = [document(['id' => 'old-video', 'type' => 'videoNode', 'data' => ['video_url' => 'https://managed.example/uploads/legacy.mov?signature=old', 'storage_domain' => 'http://127.0.0.1', 'storage_scope' => 'platform', 'storage_engine' => 'local']])];
    capture(['time' => 0]);
    $last = end(AigcVideoPosterService::$calls);
    check($last[1] === 'uploads/legacy.mov' && $last[2] === 'tenant' && $last[3] === 'oss' && $last[4] === 'https://managed.example' && $last[5] === 0.001, 'Legacy alias must use configured host and opening frame');
    echo "PASS legacy video aliases ignore untrusted metadata domain\n";
    $schema = json_decode(file_get_contents(dirname(__DIR__) . '/api_schema.json'), true);
    $api = array_values(array_filter($schema['apis'], static fn($api) => $api['api_path'] === 'app.aigc_canvas.generate/captureFrame'));
    check(count($api) === 1 && $api[0]['need_login'] === 1 && $api[0]['api_method'] === 'POST', 'Capture route must be declared and authenticated');
    echo "PASS authenticated API declaration\n";
}
