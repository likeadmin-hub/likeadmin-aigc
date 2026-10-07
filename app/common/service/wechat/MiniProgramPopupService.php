<?php
namespace app\common\service\wechat;

use app\common\model\wechat\WechatCredential;

/** Public presentation only; never return the credential record. */
class MiniProgramPopupService
{
    public static function normalize(array $input): array
    {
        $result = [];
        foreach (['title' => 60, 'description' => 300, 'title_en' => 60, 'description_en' => 300, 'title_zh_tw' => 60, 'description_zh_tw' => 300] as $key => $limit) {
            $value = $input[$key] ?? '';
            if (!is_string($value)) throw new \InvalidArgumentException('小程序码弹窗文案必须为文本');
            $value = trim($value);
            if (mb_strlen($value) > $limit) throw new \InvalidArgumentException('小程序码弹窗标题最多60字，说明最多300字');
            $result[$key] = $value;
        }
        return $result;
    }

    public static function forTenant(int $tenantId): array
    {
        if ($tenantId <= 0) return self::normalize([]);
        $json = WechatCredential::withoutGlobalScope()->where('tenant_id', $tenantId)->value('settings_json');
        $settings = json_decode((string)$json, true) ?: [];
        return self::normalize(is_array($settings['qr_popup'] ?? null) ? $settings['qr_popup'] : []);
    }
}
