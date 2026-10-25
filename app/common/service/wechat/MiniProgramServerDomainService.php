<?php

namespace app\common\service\wechat;

/** Synchronize only the bound tenant's required domains; retain existing entries. */
class MiniProgramServerDomainService
{
    public static function ensurePlatform(string|array $host, callable $request): array
    {
        $hosts=(array)$host;$host=implode(';',$hosts);
        $contains = static function (array $state) use ($hosts): bool {
            if (!array_key_exists('testing_wxa_server_domain', $state)) return false;
            foreach (['testing_wxa_server_domain', 'published_wxa_server_domain'] as $key) {
                if (!array_key_exists($key, $state)) continue;
                $domains = array_map('trim', explode(';', strtolower((string)$state[$key])));
                if (array_diff($hosts, $domains)) return false;
            }
            return true;
        };
        $state = $request(['action' => 'get']);
        if ($contains($state)) return $state;
        $request(['action' => 'add', 'wxa_server_domain' => $host, 'is_modify_published_together' => true]);
        $state = $request(['action' => 'get']);
        if (!$contains($state)) throw new \RuntimeException('第三方平台服务器域名未生效（' . $host . '），请检查微信域名备案及第三方平台配置');
        return $state;
    }

    public static function ensure(string $apiBaseUrl, callable $request, array $settings = []): array
    {
        $parts = parse_url($apiBaseUrl);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new \InvalidArgumentException('小程序接口域名必须是有效的 HTTPS 域名');
        }
        $host = strtolower($parts['host']);
        $required = WechatDomainSettings::serverDomains($apiBaseUrl, $settings);
        try {
            $current = $request(['action' => 'get']);
            $missing = self::missing($required, $current);
            if (!$missing) return $current;
            $request(array_merge(['action' => 'add'], $missing));
            $current = $request(['action' => 'get']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('微信合法域名同步失败（' . $host . '）：' . $e->getMessage() . '；请检查第三方平台的小程序服务器域名及授权权限', 0, $e);
        }
        if (self::missing($required, $current)) {
            throw new \RuntimeException('微信合法域名回读校验失败（' . $host . '），请检查第三方平台服务器域名配置后重试');
        }
        return $current;
    }

    public static function ensureWebview(array $domains, callable $request): array
    {
        if (!$domains) return [];
        $current = $request(['action'=>'get']);
        $required = ['webviewdomain'=>$domains];
        $missing = self::missing($required, $current);
        if ($missing) { $request(['action'=>'add'] + $missing); $current=$request(['action'=>'get']); }
        if (self::missing($required,$current)) throw new \RuntimeException('微信业务域名回读校验失败，请检查域名校验及授权权限');
        return $current;
    }

    public static function ensurePlatformWebview(string|array $host, callable $request): array
    {
        $hosts=(array)$host;$host=implode(';',$hosts);
        $contains=static function(array $state)use($hosts):bool{
            if (!array_key_exists('testing_wxa_jump_h5_domain',$state)) return false;
            foreach(['testing_wxa_jump_h5_domain','published_wxa_jump_h5_domain'] as $key){
                if(array_key_exists($key,$state)&&array_diff($hosts,array_map('trim',explode(';',strtolower((string)$state[$key])))))return false;
            }
            return true;
        };
        $state=$request(['action'=>'get']);
        if($contains($state))return $state;
        $request(['action'=>'add','wxa_jump_h5_domain'=>$host,'is_modify_published_together'=>true]);
        $state=$request(['action'=>'get']);
        if(!$contains($state))throw new \RuntimeException('第三方平台业务域名未生效（'.$host.'），请完成微信域名校验后重试');
        return $state;
    }

    private static function missing(array $required, array $current): array
    {
        $missing = [];
        foreach ($required as $key => $domains) {
            $present = array_map(static fn($domain) => strtolower(rtrim((string)$domain, '/')), (array)($current[$key] ?? []));
            $delta = array_values(array_diff($domains, $present));
            if ($delta) $missing[$key] = $delta;
        }
        return $missing;
    }
}
