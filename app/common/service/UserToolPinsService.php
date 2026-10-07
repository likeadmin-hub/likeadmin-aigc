<?php
namespace app\common\service;

use InvalidArgumentException;
use think\facade\Db;

/** Account-level workspace preference, independent of app installs and favorites. */
final class UserToolPinsService
{
    private const TABLE = 'user_tool_pins';

    public static function scope(int $tenant, int $user): array
    {
        if ($tenant <= 0 || $user <= 0) throw new InvalidArgumentException('请先登录');
        return ['tenant_id' => $tenant, 'user_id' => $user];
    }

    public static function normalize(array $ids): array
    {
        if (($ids && array_keys($ids) !== range(0, count($ids) - 1)) || count($ids) > 200) throw new InvalidArgumentException('置顶工具数量或格式无效');
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,200}$/D', $id)) throw new InvalidArgumentException('工具标识无效');
        }
        return array_values(array_unique($ids));
    }

    public static function read(int $tenant, int $user): array
    {
        $row = Db::name(self::TABLE)->where(self::scope($tenant, $user))->find();
        return ['ids' => $row ? json_decode($row['tool_ids'], true, 512, JSON_THROW_ON_ERROR) : [], 'initialized' => (bool)$row];
    }

    /** Import the old account-local record once; never resurrect pins removed elsewhere. */
    public static function initialize(int $tenant, int $user, array $ids): array
    {
        self::scope($tenant, $user);
        $json = json_encode(self::normalize($ids), JSON_THROW_ON_ERROR);
        $table = Db::name(self::TABLE)->getTable();
        Db::execute("INSERT INTO `$table` (`tenant_id`,`user_id`,`tool_ids`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `id`=`id`", [$tenant, $user, $json]);
        return self::read($tenant, $user);
    }

    /** Send desired pin state, not an entire stale list from another device. */
    public static function set(int $tenant, int $user, string $id, bool $pinned): array
    {
        self::normalize([$id]);
        $scope = self::scope($tenant, $user);
        return Db::transaction(function () use ($tenant, $user, $scope, $id, $pinned) {
            self::initialize($tenant, $user, []);
            $row = Db::name(self::TABLE)->where($scope)->lock(true)->find();
            $ids = json_decode($row['tool_ids'], true, 512, JSON_THROW_ON_ERROR);
            if ($pinned && !in_array($id, $ids, true)) $ids[] = $id;
            if (!$pinned) $ids = array_values(array_filter($ids, fn($value) => $value !== $id));
            $ids = self::normalize($ids);
            Db::name(self::TABLE)->where($scope)->update(['tool_ids' => json_encode($ids, JSON_THROW_ON_ERROR)]);
            return ['ids' => $ids, 'initialized' => true];
        });
    }
}
