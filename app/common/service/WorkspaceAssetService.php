<?php
namespace app\common\service;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use think\facade\Db;
use InvalidArgumentException;

/** Account-wide library metadata. Generation records and their physical files remain authoritative. */
final class WorkspaceAssetService
{
    private const TABLE = 'user_asset_state';
    private const SOURCES = [
        'image' => 'aigc_image', 'video' => 'aigc_video',
        'digital_human' => 'aigc_digital_human', 'image_human' => 'image_human',
        'action_transfer' => 'aigc_action_transfer', 'person_replacement' => 'aigc_person_replacement',
        'short_drama' => 'aigc_short_drama',
    ];
    public const LABELS = ['', 'todo', 'in_progress', 'review', 'done'];

    public static function normalize(array $params): array
    {
        if (array_diff(array_keys($params), ['refs', 'favorite', 'label', 'action'])) throw new InvalidArgumentException('资产操作包含无效字段');
        $refs = $params['refs'] ?? [];
        if (!is_array($refs) || !$refs || count($refs) > 100) throw new InvalidArgumentException('每次请选择1至100项资产');
        foreach ($refs as $ref) {
            if (!is_string($ref) || !preg_match('/^(image|video|digital_human|image_human|action_transfer|person_replacement|short_drama|upload):(result|task):[1-9][0-9]{0,10}$/D', $ref)) throw new InvalidArgumentException('资产标识无效');
            [$source, $kind] = explode(':', $ref);
            if (in_array($source, ['short_drama', 'upload'], true) && $kind !== 'result') throw new InvalidArgumentException('资产标识无效');
        }
        $patch = [];
        if (array_key_exists('favorite', $params)) {
            if (!in_array($params['favorite'], [0, 1], true)) throw new InvalidArgumentException('收藏状态无效');
            $patch['favorite'] = $params['favorite'];
        }
        if (array_key_exists('label', $params)) {
            if (!in_array($params['label'], self::LABELS, true)) throw new InvalidArgumentException('资产标签无效');
            $patch['label'] = $params['label'];
        }
        $action = $params['action'] ?? '';
        if (!in_array($action, ['', 'trash', 'restore', 'remove'], true)) throw new InvalidArgumentException('资产操作无效');
        if (!$patch && !$action) throw new InvalidArgumentException('请选择资产操作');
        return [array_values(array_unique($refs)), $patch, $action];
    }

    public static function read(int $tenant, int $user): array
    {
        self::identity($tenant, $user);
        $states = Db::name(self::TABLE)->where(['tenant_id' => $tenant, 'user_id' => $user])->select()->toArray();
        $supplemental = [];
        // Read saved references independently of the source's recent-results limit.
        foreach ($states as $state) {
            if ($state['hidden_at'] || (!$state['trashed_at'] && !$state['favorite'])) continue;
            $asset = self::owned($tenant, $user, $state['asset_ref']);
            if ($asset) $supplemental[] = $asset;
        }
        return ['states' => array_map(fn($row) => array_intersect_key($row, array_flip(['asset_ref','favorite','label','trashed_at','hidden_at'])), $states), 'supplemental' => $supplemental];
    }

    public static function update(int $tenant, int $user, array $params): array
    {
        self::identity($tenant, $user);
        [$refs, $patch, $action] = self::normalize($params);
        return Db::transaction(function () use ($tenant, $user, $refs, $patch, $action) {
            $output = [];
            foreach ($refs as $ref) {
                if (!self::owned($tenant, $user, $ref)) throw new InvalidArgumentException('资产不存在或无权操作');
                $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'asset_ref' => $ref];
                // Insert-ignore + row lock also serializes first writes from different devices.
                Db::name(self::TABLE)->extra('IGNORE')->insert($scope + ['create_time' => time(), 'update_time' => time()]);
                $row = Db::name(self::TABLE)->where($scope)->lock(true)->find();
                if ($row['hidden_at']) throw new InvalidArgumentException('资产已从资产库移除');
                $data = $patch + ['update_time' => time()];
                if ($action === 'trash') $data['trashed_at'] = (int)$row['trashed_at'] ?: time();
                if ($action === 'restore') $data['trashed_at'] = 0;
                if ($action === 'remove') {
                    if (!$row['trashed_at']) throw new InvalidArgumentException('请先将资产移到最近删除');
                    $data['hidden_at'] = time();
                }
                Db::name(self::TABLE)->where($scope)->update($data);
                $output[] = array_intersect_key(array_replace($row, $data), array_flip(['asset_ref','favorite','label','trashed_at','hidden_at']));
            }
            return ['states' => $output];
        });
    }

    public static function uploads(int $tenant, int $user, int $page): array
    {
        self::identity($tenant, $user);
        $rows = TenantFile::where(['tenant_id' => $tenant, 'source' => FileEnum::SOURCE_USER, 'source_id' => $user])
            ->whereIn('type', [FileEnum::IMAGE_TYPE, FileEnum::VIDEO_TYPE, FileEnum::FILE_TYPE])
            ->order('id', 'desc')->page(max(1, $page), 100)->select()->toArray();
        return ['lists' => array_map(fn($row) => self::presentUpload($row), $rows), 'has_more' => count($rows) === 100];
    }

    private static function identity(int $tenant, int $user): void
    {
        if ($tenant <= 0 || $user <= 0) throw new InvalidArgumentException('请先登录');
    }

    private static function owned(int $tenant, int $user, string $ref): ?array
    {
        [$source, $kind, $id] = explode(':', $ref);
        if ($source === 'upload') {
            $file = TenantFile::where(['id' => (int)$id, 'tenant_id' => $tenant, 'source' => FileEnum::SOURCE_USER, 'source_id' => $user])->find();
            return $file ? self::presentUpload($file->toArray()) : null;
        }
        $prefix = self::SOURCES[$source] ?? null;
        if (!$prefix) return null;
        $table = $source === 'short_drama' ? $prefix . '_asset' : $prefix . '_' . $kind;
        $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'delete_time' => 0];
        $record = Db::name($table)->where($scope)->where('id', (int)$id)->find();
        if (!$record) return null;
        $task = [];
        if ($kind === 'result' && $source !== 'short_drama') {
            $task = Db::name($prefix . '_task')->where($scope)->where('id', (int)$record['task_id'])->find();
            if (!$task) return null;
        }
        // Only presentation fields may leave this service, never provider payloads or credentials.
        $fields = ['id','task_id','title','prompt','script_text','status','create_time','width','height','duration','ratio','aspect_ratio','resolution','quality','model_name','asset_type'];
        $raw = array_intersect_key(array_replace($task ?: [], $record), array_flip($fields));
        $raw['result_id'] = $kind === 'result' ? (int)$id : 0;
        $raw['task_id'] = $kind === 'task' ? (int)$id : ($record['task_id'] ?? 0);
        foreach (['image', 'video', 'cover'] as $type) {
            $raw[$type . '_url'] = self::url($record, $type . '_uri');
        }
        if ($source === 'short_drama') {
            $raw['url'] = self::url($record, 'uri');
            $meta = json_decode($record['meta_json'] ?? '{}', true) ?: [];
            $raw['meta'] = array_intersect_key($meta, array_flip(['title','message','visible_prompt','model','ratio']));
        }
        return ['source' => $source, 'ref' => $ref, 'raw' => $raw];
    }

    private static function presentUpload(array $row): array
    {
        return ['source' => 'upload', 'ref' => 'upload:result:' . $row['id'], 'raw' => [
            'id' => (int)$row['id'], 'title' => $row['name'], 'type' => (int)$row['type'],
            'url' => self::url($row, 'uri'), 'create_time' => $row['create_time'],
        ]];
    }

    private static function url(array $row, string $field): string
    {
        if (empty($row[$field])) return '';
        return FileService::getFileUrlByStorage($row[$field], $row['storage_scope'] ?? '', $row['storage_engine'] ?? '', $row['storage_domain'] ?? '');
    }
}
