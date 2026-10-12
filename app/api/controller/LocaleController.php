<?php
namespace app\api\controller;

use app\common\service\ConfigService;

/** Account language shares the existing tenant configuration store. */
class LocaleController extends BaseApiController
{
    public function detail() { return $this->handle(false); }
    public function save() { return $this->handle(true); }

    private function handle(bool $write)
    {
        $tenant = (int)($this->userInfo['tenant_id'] ?? 0);
        if ($this->userId <= 0 || $tenant <= 0 || (int)$this->request->tenantId !== $tenant) {
            return $this->fail('账号与租户不匹配，请重新登录');
        }
        $key = 'user_' . $this->userId;
        if ($write) {
            if (!$this->request->isPost()) return $this->fail('请使用POST请求');
            $locale = $this->request->post('locale');
            if (!in_array($locale, ['zh-CN', 'zh-TW', 'en'], true)) return $this->fail('不支持的语言');
            ConfigService::set('pc_user_locale', $key, $locale);
        }
        $locale = ConfigService::get('pc_user_locale', $key, null);
        $configured = in_array($locale, ['zh-CN', 'zh-TW', 'en'], true);
        return $this->data(['locale' => $configured ? $locale : \app\common\service\PcDefaultLocaleService::read(), 'configured' => $configured]);
    }
}
