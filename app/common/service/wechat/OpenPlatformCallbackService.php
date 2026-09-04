<?php

namespace app\common\service\wechat;

use app\common\model\wechat\WechatCallbackLog;
use app\common\model\wechat\WechatAuthorizer;
use app\tenantapi\logic\channel\OfficialAccountReplyLogic;
use EasyWeChat\Kernel\Encryptor;
use think\facade\Cache;

/** Handles unauthenticated callbacks from the WeChat open platform. */
class OpenPlatformCallbackService
{
    public static function handle($request): array|string
    {
        $config = \app\common\model\wechat\WechatOpenPlatform::withoutGlobalScope()->findOrEmpty(1)->toArray();
        $token = WechatCredentialService::decrypt($config['token'] ?? '');
        $aesKey = WechatCredentialService::decrypt($config['encoding_aes_key'] ?? '');
        $appId = (string)($config['app_id'] ?? '');
        $timestamp = (string)$request->param('timestamp', '');
        $nonce = (string)$request->param('nonce', '');
        // URL verification uses `signature`; encrypted event callbacks use
        // `msg_signature`. Accept the legacy name only as a compatibility
        // fallback so the encrypted callback is verified against the right
        // digest.
        $signature = (string)$request->param('msg_signature', $request->param('signature', ''));
        if ($token === '' || $aesKey === '' || $appId === '') throw new \RuntimeException('开放平台回调配置不完整');
        if ($request->isGet() && $request->param('auth_code', '') === '') {
            self::verifyPlain($token, $timestamp, $nonce, $signature);
            return (string)$request->param('echostr', '');
        }
        if ($request->isGet() && $request->param('auth_code', '') !== '') {
            $requestId = bin2hex(random_bytes(12)); $tenantId = 0;
            try {
                $state = (string)$request->param('state', ''); if ($state === '') throw new \RuntimeException('授权状态缺失');
                $context = self::stateContext($state); $tenantId = (int)($context['tenant_id'] ?? 0);
                $info = OpenPlatformService::queryAuthorization((string)$request->param('auth_code'));
                $scope = (array)($info['func_info'] ?? []); $profile = OpenPlatformService::authorizerProfileByAppid((string)$info['authorizer_appid']); $type = self::authorizerType($scope, array_merge($info, ['authorizer_info' => $profile]));
                $expectedType = (string)($context['authorizer_type'] ?? '');
                if ($expectedType !== '' && $expectedType !== $type) throw new \RuntimeException('微信返回的账号类型与选择不一致，请重新授权');
                if ($tenantId > 0) OpenPlatformService::bindAuthorizer($tenantId, (string)$info['authorizer_appid'], $type, ['refresh_token' => $info['authorizer_refresh_token'] ?? '', 'func_info' => $scope, 'name' => $profile['nick_name'] ?? '', 'principal_name' => $profile['principal_name'] ?? '', 'head_img' => $profile['head_img'] ?? '']);
                self::log($requestId, 'authorized', 1, 'success', '', $tenantId); return 'success';
            } catch (\Throwable $e) { self::log($requestId, 'authorized', 1, 'failed', $e->getMessage(), $tenantId); throw $e; }
        }
        if ($timestamp === '' || $nonce === '' || abs(time() - (int)$timestamp) > 300) throw new \RuntimeException('回调时间戳或 Nonce 已过期');
        $raw = (string)$request->getContent();
        $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml) throw new \RuntimeException('回调报文格式错误');
        $encrypted = trim((string)($xml->Encrypt ?? ''));
        if ($encrypted === '') throw new \RuntimeException('回调密文为空');
        $expected = sha1(implode('', self::sorted([$token, $timestamp, $nonce, $encrypted])));
        if ($signature === '' || !hash_equals($expected, $signature)) throw new \RuntimeException('回调签名校验失败');
        $encryptor = new Encryptor($appId, $token, $aesKey, $appId);
        $plain = $encryptor->decrypt($encrypted, $signature, $nonce, $timestamp);
        $message = @simplexml_load_string($plain, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if (!$message) throw new \RuntimeException('回调解密失败');
        $event = (string)($message->InfoType ?? '');
        $dedupePayload = match ($event) {
            'component_verify_ticket' => (string)($message->ComponentVerifyTicket ?? ''),
            'authorized', 'updateauthorized' => (string)($message->AuthorizationCode ?? $request->param('auth_code', '')),
            'unauthorized' => (string)($message->AuthorizerAppid ?? ''),
            default => $plain,
        };
        $eventKey = sha1($event . '|' . $dedupePayload);
        $cachedReply = Cache::get('wechat.open_platform.callback.' . $eventKey);
        if ($cachedReply) {
            self::log(bin2hex(random_bytes(12)), $event, 1, 'duplicate');
            return is_array($cachedReply) ? (string)($cachedReply['response'] ?? 'success') : 'success';
        }
        $requestId = bin2hex(random_bytes(12));
        $tenantId = 0;
        try {
            if ($event === '') {
                $result = self::authorizerReply($message, $config);
            } elseif ($event === 'component_verify_ticket') {
                OpenPlatformService::saveVerifyTicket((string)($message->ComponentVerifyTicket ?? ''));
            } elseif (in_array($event, ['authorized', 'updateauthorized'], true)) {
                $state = (string)$request->param('state', '');
                $authorizationCode = (string)($message->AuthorizationCode ?? $request->param('auth_code', ''));
                $context = $state !== '' ? self::stateContext($state) : ['tenant_id' => 0];
                $tenantId = (int)($context['tenant_id'] ?? 0);
                $info = OpenPlatformService::queryAuthorization($authorizationCode);
                $scope = (array)($info['func_info'] ?? []);
                $profile = OpenPlatformService::authorizerProfileByAppid((string)$info['authorizer_appid']);
                $type = self::authorizerType($scope, array_merge($info, ['authorizer_info' => $profile]));
                $expectedType = (string)($context['authorizer_type'] ?? '');
                if ($expectedType !== '' && $expectedType !== $type) throw new \RuntimeException('微信返回的账号类型与选择不一致，请重新授权');
                if ($tenantId > 0) OpenPlatformService::bindAuthorizer($tenantId, (string)$info['authorizer_appid'], $type, ['refresh_token' => $info['authorizer_refresh_token'] ?? '', 'func_info' => $scope, 'name' => $profile['nick_name'] ?? '', 'principal_name' => $profile['principal_name'] ?? '', 'head_img' => $profile['head_img'] ?? '']);
            } elseif ($event === 'unauthorized') {
                OpenPlatformService::markUnauthorized((string)($message->AuthorizerAppid ?? ''));
            }
            $response = $result ?? 'success';
            Cache::set('wechat.open_platform.callback.' . $eventKey, ['response' => $response], 86400);
            self::log($requestId, $event, 1, 'success', '', $tenantId);
            return $response;
        } catch (\Throwable $e) {
            self::log($requestId, $event, 1, 'failed', $e->getMessage(), $tenantId);
            throw $e;
        }
    }

    private static function verifyPlain(string $token, string $timestamp, string $nonce, string $signature): void { if ($timestamp === '' || abs(time() - (int)$timestamp) > 300 || $signature === '') throw new \RuntimeException('回调签名参数无效'); $expected = sha1(implode('', self::sorted([$token, $timestamp, $nonce]))); if (!hash_equals($expected, $signature)) throw new \RuntimeException('回调签名校验失败'); }
    private static function stateContext(string $state): array { return $state === '' ? ['tenant_id' => 0] : OpenPlatformService::consumeAuthState($state); }
    private static function sorted(array $values): array { sort($values, SORT_STRING); return $values; }
    private static function authorizerType(array $scope, array $info = []): string
    {
        $profile = (array)($info['authorizer_info'] ?? $info);
        foreach (['MiniProgramInfo', 'mini_program_info', 'miniProgramInfo'] as $key) {
            if (!empty($profile[$key])) return 'miniprogram';
        }
        $value = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (str_contains((string)$value, '小程序') || str_contains(strtolower((string)$value), 'miniprogram')) return 'miniprogram';
        $json = json_encode($scope, JSON_UNESCAPED_UNICODE);
        return str_contains((string)$json, '小程序') || str_contains(strtolower((string)$json), 'mini') ? 'miniprogram' : 'official';
    }

    /** Reply to an authorized official-account message through the component callback. */
    private static function authorizerReply(\SimpleXMLElement $message, array $config): string
    {
        $authorizerAppId = trim((string)($message->ToUserName ?? ''));
        if ($authorizerAppId === '') {
            return 'success';
        }
        $authorizer = WechatAuthorizer::withoutGlobalScope()
            ->where([
                'authorizer_appid' => $authorizerAppId,
                'authorizer_type' => 'official',
                'authorization_status' => 1,
            ])
            ->findOrEmpty();
        if ($authorizer->isEmpty()) {
            return 'success';
        }

        $payload = [];
        foreach ($message as $key => $value) {
            $payload[$key] = (string)$value;
        }
        $reply = OfficialAccountReplyLogic::authorizerReply($payload, (int)$authorizer['tenant_id']);
        if ($reply === '') {
            return 'success';
        }

        $plain = self::textReplyXml(
            (string)($message->FromUserName ?? ''),
            $authorizerAppId,
            $reply
        );
        $encryptor = new Encryptor(
            (string)$config['app_id'],
            WechatCredentialService::decrypt($config['token'] ?? ''),
            WechatCredentialService::decrypt($config['encoding_aes_key'] ?? ''),
            (string)$config['app_id']
        );
        return $encryptor->encryptAsXml($plain);
    }

    private static function textReplyXml(string $toUser, string $fromUser, string $content): string
    {
        $escape = static fn (string $value): string => str_replace(']]>', ']]]]><![CDATA[>', $value);
        return '<xml><ToUserName><![CDATA[' . $escape($toUser) . ']]></ToUserName>'
            . '<FromUserName><![CDATA[' . $escape($fromUser) . ']]></FromUserName>'
            . '<CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[' . $escape($content) . ']]></Content></xml>';
    }
    private static function log(string $requestId, string $event, int $valid, string $result, string $error = '', int $tenantId = 0): void { try { WechatCallbackLog::withoutGlobalScope()->insert(['request_id' => $requestId, 'tenant_id' => $tenantId, 'event_type' => $event, 'signature_valid' => $valid, 'result' => $result, 'error_message' => mb_substr($error, 0, 255), 'create_time' => time()]); } catch (\Throwable $ignored) {} }
}
