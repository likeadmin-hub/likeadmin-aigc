<?php
declare(strict_types=1);

namespace app\common\listener;

use app\api\service\WechatUserService;
use app\common\model\user\User;
use think\facade\Log;
use think\Response;

/** HttpEnd runs after Response::send / fastcgi_finish_request on PHP-FPM. */
class WechatAvatarSync
{
    public function handle(Response $response): void
    {
        $request = request();
        $work = $request->wechatAvatarSync ?? null;
        $request->wechatAvatarSync = null;
        if (!is_array($work)) return;

        $data = $response->getData();
        if (!is_array($data) || (int)($data['code'] ?? 0) !== 1
            || (int)$request->tenantId !== (int)$work['tenant_id']) return;

        $started = microtime(true);
        try {
            $query = static fn() => User::where([
                'id' => (int)$work['user_id'],
                'tenant_id' => (int)$work['tenant_id'],
                'avatar' => $work['expected_avatar'],
            ]);
            // Also protects a rolled-back registration and a newer user upload.
            if (!$query()->count()) return;
            $avatar = (new WechatUserService($work['profile'], (int)$work['terminal']))->getAvatarByWechat();
            if ($avatar !== '') $query()->update(['avatar'=>$avatar]);
            Log::info('WeChat avatar synchronization completed ' . json_encode([
                'tenant_id'=>(int)$work['tenant_id'],
                'duration_ms'=>(int)round((microtime(true)-$started)*1000),
            ]));
        } catch (\Throwable $error) {
            // Never log provider URLs, OpenID, storage credentials or tokens.
            Log::warning('WeChat avatar synchronization failed ' . json_encode([
                'tenant_id'=>(int)$work['tenant_id'],
                'duration_ms'=>(int)round((microtime(true)-$started)*1000),
                'error_type'=>get_class($error),
            ]));
        }
    }
}
