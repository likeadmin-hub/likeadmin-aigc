<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\common\service\wechat;


use app\common\service\wechat\WeChatConfigService;
use EasyWeChat\Kernel\Exceptions\Exception;
use EasyWeChat\MiniApp\Application;


/**
 * 微信功能类
 * Class WeChatMnpService
 * @package app\common\service
 */
class WeChatMnpService
{

    protected $app;

    protected $config;

    protected int $tenantId;

    protected bool $useOpenPlatform = false;

    public function __construct(?int $tenantId = null)
    {
        $this->tenantId = max(0, $tenantId ?? (int)(request()->tenantId ?? 0));
        $this->useOpenPlatform = OpenPlatformService::hasAuthorizedMiniprogram($this->tenantId);
        if ($this->useOpenPlatform) return;
        $this->config = $this->getConfig();
        $this->app = new Application($this->config);
    }


    /**
     * @notes 配置
     * @return array
     * @throws \Exception
     * @author 段誉
     * @date 2023/2/27 12:03
     */
    protected function getConfig()
    {
        $config = WeChatConfigService::getMnpConfig();
        if (empty($config['app_id']) || empty($config['secret'])) {
            throw new \Exception('请先设置小程序配置');
        }
        return $config;
    }


    /**
     * @notes 小程序-根据code获取微信信息
     * @param string $code
     * @return array
     * @throws Exception
     * @throws \EasyWeChat\Kernel\Exceptions\HttpException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @author 段誉
     * @date 2023/2/27 11:03
     */
    public function getMnpResByCode(string $code)
    {
        if ($this->useOpenPlatform) {
            return OpenPlatformService::authorizedMnpSessionByCode($this->tenantId, $code);
        }
        $utils = $this->app->getUtils();
        $response = $utils->codeToSession($code);

        if (!isset($response['openid']) || empty($response['openid'])) {
            throw new Exception('获取openID失败');
        }

        return $response;
    }


    /**
     * @notes 获取手机号
     * @param string $code
     * @return \EasyWeChat\Kernel\HttpClient\Response|\Symfony\Contracts\HttpClient\ResponseInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @author 段誉
     * @date 2023/2/27 11:46
     */
    public function getUserPhoneNumber(string $code)
    {
        if ($this->useOpenPlatform) {
            return OpenPlatformService::authorizedMnpPhoneNumber($this->tenantId, $code);
        }
        return $this->app->getClient()->postJson('wxa/business/getuserphonenumber', [
            'code' => $code,
        ]);
    }


    public function posterIdentity(): string
    {
        if ($this->useOpenPlatform) return OpenPlatformService::posterIdentity($this->tenantId);
        return 'direct:'.(string)$this->config['app_id'];
    }

    public function posterCode(string $scene): string
    {
        $payload = ['scene'=>$scene,'page'=>'pages/decoration-tab/tab-0','check_path'=>true,'env_version'=>'release','width'=>430,'is_hyaline'=>false];
        try {
            $bytes = $this->useOpenPlatform
                ? OpenPlatformService::authorizedPosterCode($this->tenantId,$payload)
                : $this->app->getClient()->postJson('wxa/getwxacodeunlimit',$payload)->getContent();
        } catch (\Throwable $e) {
            throw new \RuntimeException('专属码生成失败，请检查小程序配置及发布状态后重试');
        }
        $image = @getimagesizefromstring($bytes);
        if (!$image || !in_array($image['mime'],['image/png','image/jpeg'],true) || strlen($bytes)>2097152) {
            $body=json_decode($bytes,true); $code=(int)($body['errcode']??0);
            throw new \RuntimeException('微信未返回有效小程序码（'.$code.'），请检查授权和正式版首页');
        }
        return $bytes;
    }

}
