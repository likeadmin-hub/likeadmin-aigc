<?php
declare(strict_types=1);
namespace app\common\service\decorate;

use RuntimeException;
use think\facade\Cache;

/** Short-lived telemetry only. The import's storage/DB transaction remains authoritative. */
final class DecorationTransferProgress
{
    private const TTL = 600;
    private const MAX_STEPS = 400;
    private array $snapshot;

    private function __construct(private string $key, string $mode)
    {
        $this->snapshot = ['available'=>true, 'mode'=>$mode, 'status'=>'running', 'steps'=>[], 'result'=>[]];
    }

    private static function key(int $tenant, int $admin, string $id): string
    {
        if ($tenant <= 0 || $admin <= 0 || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new RuntimeException('模板操作编号无效');
        }
        return 'decoration_transfer:'.$tenant.':'.$admin.':'.$id;
    }

    public static function begin(int $tenant, int $admin, string $id, string $mode): self
    {
        if (!in_array($mode, ['import', 'export'], true)) throw new RuntimeException('模板操作类型无效');
        // Existing integrations without an operation ID keep their synchronous API contract.
        $key = $id === '' ? '' : self::key($tenant, $admin, $id);
        if ($key !== '') {
            try { $existing = Cache::get($key); } catch (\Throwable $error) { $existing = null; }
            if ($existing) throw new RuntimeException('模板操作编号已使用，请刷新列表后重新操作');
        }
        $progress = new self($key, $mode);
        $progress->step($mode === 'import' ? '服务器已接收模板包，开始导入' : '服务器开始整理导出模板');
        return $progress;
    }

    public static function read(int $tenant, int $admin, string $id): array
    {
        $key = self::key($tenant, $admin, $id);
        try { return Cache::get($key) ?: ['available'=>true, 'status'=>'pending', 'steps'=>[]]; }
        catch (\Throwable $error) { return ['available'=>false, 'status'=>'pending', 'steps'=>[]]; }
    }

    public function step(string $message): void
    {
        if ($this->snapshot['status'] !== 'running') return;
        $steps = &$this->snapshot['steps'];
        $seq = $steps ? $steps[array_key_last($steps)]['seq'] + 1 : 1;
        $steps[] = ['seq'=>$seq, 'at'=>time(), 'message'=>mb_substr($message, 0, 600)];
        if (count($steps) > self::MAX_STEPS) array_shift($steps);
        $this->persist();
    }

    public function succeed(array $result = []): void
    {
        if ($this->snapshot['status'] !== 'running') return;
        $this->step($this->snapshot['mode'] === 'import' ? '模板草稿已保存，导入完成' : '模板包已生成，等待浏览器下载');
        $this->snapshot['status'] = 'success';
        // Never cache template contents, signed URLs or base64 ZIPs.
        $this->snapshot['result'] = isset($result['asset_import'])
            ? ['asset_import'=>array_intersect_key($result['asset_import'], array_flip(['files', 'bytes', 'references']))]
            : [];
        $this->persist();
    }

    public function fail(string $message): void
    {
        if ($this->snapshot['status'] !== 'running') return;
        $this->step($message);
        $this->snapshot['status'] = 'error';
        $this->persist();
    }

    private function persist(): void
    {
        if ($this->key === '') return;
        // An unavailable progress cache must never roll back a valid import or hide its outcome.
        try { Cache::set($this->key, $this->snapshot, self::TTL); } catch (\Throwable $error) { }
    }
}
