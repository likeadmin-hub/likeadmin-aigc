<?php
namespace app\common\service\app\aigc_short_drama;

/** New requests opt in; already queued requests retain their frozen contract. */
final class ShortDramaInputContract
{
    public const VERSION = 1;

    public static function current(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= self::VERSION;
    }

    public static function withoutSkills(array $request): array
    {
        foreach (['skill_id', 'skill_version', 'skill_source', 'skill_inputs', '_skill_snapshot',
            '_skill_asset_inventory', '_skill_confirmations', '_skill_origin_task_id'] as $key) unset($request[$key]);
        return $request;
    }

    public static function begin(array $request): array
    {
        return array_replace(self::withoutSkills($request), ['_input_contract_version' => self::VERSION]);
    }
}
