<?php
namespace app\common\service\open_platform;

/** Pure protocol/security rules; no DB or request globals. */
final class OpenPolicy
{
    public static function matches(string $template, string $path): ?array
    {
        $names = [];
        $pattern = preg_replace_callback('/\\\{([a-z_]+)\\\}/', static function ($m) use (&$names) { $names[] = $m[1]; return '([^/]+)'; }, preg_quote($template, '#'));
        if (!preg_match('#^' . $pattern . '$#D', $path, $m)) return null;
        array_shift($m);
        foreach ($m as $v) if (preg_match('/[%\\\\\x00-\x20]/', $v) || in_array($v, ['.', '..'], true)) return null;
        return array_combine($names, $m) ?: [];
    }
    public static function fingerprint(array $params): string
    {
        $sort = static function ($value) use (&$sort) { $object=$value instanceof \stdClass;if($object)$value=(array)$value;if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach ($value as &$v) $v = $sort($v); return $object?(object)$value:$value; };
        return hash('sha256', json_encode($sort($params), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    public static function ipAllowed(string $ip, array $ranges): bool
    {
        if (!$ranges) return true;
        $address = @inet_pton($ip); if ($address === false) return false;
        foreach ($ranges as $range) {
            [$network, $bits] = array_pad(explode('/', (string)$range, 2), 2, null);
            $packed = @inet_pton($network); if ($packed === false || strlen($packed) !== strlen($address)) continue;
            $bits = $bits === null ? strlen($packed) * 8 : (ctype_digit($bits) ? (int)$bits : -1);
            if ($bits < 0 || $bits > strlen($packed) * 8) continue;
            $bytes = intdiv($bits, 8); $tail = $bits % 8;
            if (substr($address, 0, $bytes) === substr($packed, 0, $bytes) && (!$tail || ((ord($address[$bytes]) ^ ord($packed[$bytes])) & (255 << (8-$tail))) === 0)) return true;
        }
        return false;
    }
    public static function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) return self::isPublicIp(substr($ip, 7));
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    public static function validateInput(array $input, int $depth = 0): void
    {
        if ($depth > 32 || count($input) > 10000) throw new \InvalidArgumentException('请求结构超出限制');
        $blocked = ['tenant_id','user_id','admin_id','price_snapshot','billing_override','tenant_cost_points','user_charge_points','upstream_url','upstream_api_key','runtime_adapter','executor','provider_config','source_app_code','business_table','business_id','parent_task_id','tenantid','userid','app_code'];
        foreach ($input as $key => $value) {
            if($depth===0&&is_string($key)&&str_starts_with($key,'_'))throw new \InvalidArgumentException('请求包含内部控制字段');
            if($depth===0&&$key==='source_app'&&in_array($value,['aigc_short_drama','aigc_canvas','aigc_geo'],true))throw new \InvalidArgumentException('该工作室流程不对外开放');
            if (is_string($key) && ($depth === 0 && in_array(strtolower($key), $blocked, true) || str_starts_with($key, '_billing') || $key === '_user_id' || str_starts_with($key,'_open_platform'))) throw new \InvalidArgumentException('请求包含平台内部字段');
            if (is_array($value)||$value instanceof \stdClass) self::validateInput((array)$value, $depth + 1);
        }
    }
    public static function publicData(array $data): array
    {
        $hidden = ['config_json','prompt_template','system_prompt','tenant_id','user_id','tenant_override_id','cost_source_url','api_key','active_api_key','license_key','secret','secret_key','access_key','password','token','authorization','provider_params_json','pricing_snapshot','market_snapshot','price_snapshot','upstream_price','platform_price','platform_unit_cost','tenant_cost_points','tenant_cost','tenant_unit_points','tenant_unit_cost','cost_points','reserved_tenant_cost','actual_tenant_cost','point_balance','source_blob','key_hash','actual_tenant','budget_tenant','tenant_price','platform_sale_points','gross_margin_points','user_point_sn','tenant_point_sn'];
        foreach ($data as $key => &$value) {
            if (in_array(strtolower((string)$key), $hidden, true) || str_ends_with((string)$key, '_secret') || str_contains((string)$key, 'credential') || str_starts_with((string)$key,'_open_platform') || preg_match('/^(?:reserved_|actual_|estimated_)?tenant_(?:cost|price)|^(?:platform_|upstream_).*(?:price|cost|margin)|^tenant_.*(?:cost|margin)|^.*(?:api[_-]?key|access[_-]?token)$/i',(string)$key)) { unset($data[$key]); continue; }
            if (is_array($value)) $value = self::publicData($value);
        }
        return $data;
    }
}
