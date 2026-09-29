<?php
namespace app\common\service;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use think\facade\Db;
use InvalidArgumentException;
use RuntimeException;

/** Account appearance is independent of tenant decoration and AI task settings. */
final class UserAppearanceService
{
    public const PRESETS = ['default', 'neon-sunset-drive', 'voxel', 'winter', 'doodle-art', 'felt-landscape', 'emerald-nebula', 'city-lights', 'sakura-midnight', 'claymorphism', 'halloween-night', 'Art-Style', 'mexican-heritage', 'stellar'];
    private const TABLE = 'user_appearance';

    public static function defaults(): array
    {
        return ['selected' => 'default', 'mode' => 'system', 'custom' => []];
    }

    public static function normalize(array $input, callable $ownedImage): array
    {
        if (array_diff(array_keys($input), ['selected', 'mode', 'custom'])) throw new InvalidArgumentException('皮肤配置包含不支持的字段');
        $mode = $input['mode'] ?? 'system';
        if (!in_array($mode, ['light', 'dark', 'system'], true)) throw new InvalidArgumentException('主题模式无效');
        $custom = $input['custom'] ?? [];
        if (!is_array($custom) || count($custom) > 20) throw new InvalidArgumentException('最多保存20个定制皮肤');
        $skins = [];
        foreach ($custom as $skin) {
            if (!is_array($skin) || array_diff(array_keys($skin), ['id', 'name', 'mode', 'primary', 'secondary', 'background_id', 'background_url'])) throw new InvalidArgumentException('定制皮肤字段无效');
            $id = $skin['id'] ?? '';
            if (!is_string($id) || !preg_match('/^custom-[a-zA-Z0-9-]{8,64}$/D', $id) || isset($skins[$id])) throw new InvalidArgumentException('定制皮肤标识无效');
            $name = $skin['name'] ?? '';
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 40) throw new InvalidArgumentException('皮肤名称须为1至40个字符');
            if (!in_array($skin['mode'] ?? '', ['light', 'dark'], true)) throw new InvalidArgumentException('定制皮肤模式无效');
            foreach (['primary', 'secondary'] as $key) {
                if (!is_string($skin[$key] ?? null) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $skin[$key])) throw new InvalidArgumentException('请选择有效的皮肤颜色');
            }
            $fileId = filter_var($skin['background_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($fileId === false || ($fileId > 0 && !$ownedImage($fileId))) throw new InvalidArgumentException('背景图片不存在或不属于当前账号');
            // URLs are always resolved from owned storage records, never trusted from the client.
            $skins[$id] = ['id' => $id, 'name' => trim($name), 'mode' => $skin['mode'], 'primary' => strtolower($skin['primary']), 'secondary' => strtolower($skin['secondary']), 'background_id' => $fileId];
        }
        $selected = $input['selected'] ?? 'default';
        if (!is_string($selected) || (!in_array($selected, self::PRESETS, true) && !isset($skins[$selected]))) throw new InvalidArgumentException('请选择有效的皮肤');
        return ['selected' => $selected, 'mode' => $mode, 'custom' => array_values($skins)];
    }

    public static function read(int $tenant, int $user): array
    {
        self::identity($tenant, $user);
        $row = Db::name(self::TABLE)->where(['tenant_id' => $tenant, 'user_id' => $user])->find();
        return self::present($tenant, $user, $row ? json_decode($row['preferences_json'], true) : self::defaults(), (int)($row['revision'] ?? 0));
    }

    public static function save(int $tenant, int $user, int $revision, array $input): array
    {
        self::identity($tenant, $user);
        if ($revision < 0) throw new InvalidArgumentException('配置版本无效');
        $prefs = self::normalize($input, fn($id) => self::image($tenant, $user, $id));
        Db::transaction(function () use ($tenant, $user, $revision, $prefs) {
            $scope = ['tenant_id' => $tenant, 'user_id' => $user];
            // Unique identity plus CAS prevents two devices overwriting another edit.
            $row = Db::name(self::TABLE)->where($scope)->lock(true)->find();
            if ((int)($row['revision'] ?? 0) !== $revision) throw new RuntimeException('皮肤已在其他设备更新，请重新加载后再保存');
            $data = ['preferences_json' => json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'revision' => $revision + 1, 'update_time' => time()];
            if ($row) {
                if (Db::name(self::TABLE)->where($scope)->where('revision', $revision)->update($data) !== 1) throw new RuntimeException('皮肤配置已更新，请重试');
            } else {
                Db::name(self::TABLE)->insert($scope + $data + ['create_time' => time()]);
            }
        });
        return self::present($tenant, $user, $prefs, $revision + 1);
    }

    private static function identity(int $tenant, int $user): void
    {
        if ($tenant <= 0 || $user <= 0) throw new InvalidArgumentException('请先登录');
    }

    private static function image(int $tenant, int $user, int $id): ?array
    {
        // TenantFile also handles tenant table sharding, matching UploadService.
        $file = TenantFile::where(['id' => $id, 'tenant_id' => $tenant, 'source' => FileEnum::SOURCE_USER, 'source_id' => $user, 'type' => FileEnum::IMAGE_TYPE])->find();
        if (!$file || !preg_match('/\.(png|jpe?g|webp)$/i', $file['uri'])) return null;
        return $file->toArray();
    }

    private static function present(int $tenant, int $user, array $prefs, int $revision): array
    {
        foreach ($prefs['custom'] as &$skin) {
            $file = !empty($skin['background_id']) ? self::image($tenant, $user, (int)$skin['background_id']) : null;
            $skin['background_url'] = $file ? FileService::getFileUrlByStorage($file['uri'], $file['storage_scope'] ?? '', $file['storage_engine'] ?? '', $file['storage_domain'] ?? '') : '';
            if (!$file) $skin['background_id'] = 0;
        }
        return ['preferences' => $prefs, 'revision' => $revision];
    }
}
