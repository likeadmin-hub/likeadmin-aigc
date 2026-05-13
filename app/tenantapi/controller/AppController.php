<?php

namespace app\tenantapi\controller;

use app\common\model\app\App;
use app\common\model\app\TenantApp;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\AppFrontendManifestService;
use app\common\service\app\AppPlanService;
use app\common\service\app\DefaultAppService;
use Exception;

class AppController extends BaseAdminController
{
    public function market()
    {
        $name = trim((string)$this->request->get('name', ''));
        $isBuyFilter = $this->request->get('is_buy', '');
        $apps = App::where('status', 'installed')->order(['sort' => 'desc', 'id' => 'desc'])->select()->toArray();
        $tenantApps = TenantApp::where('tenant_id', $this->tenantId)->column('*', 'app_code');
        $rows = [];
        foreach ($apps as &$app) {
            $tenantApp = $tenantApps[$app['code']] ?? [];
            $isBuiltin = (int)($app['is_builtin'] ?? 0) === 1 || DefaultAppService::isDefaultApp((string)$app['code']);
            $app['is_builtin'] = $isBuiltin ? 1 : (int)($app['is_builtin'] ?? 0);
            $app['expire_policy'] = $isBuiltin ? AppPlanService::EXPIRE_ALLOW : ($app['expire_policy'] ?? AppPlanService::EXPIRE_BLOCK);
            $app['is_buy'] = $isBuiltin ? 1 : (empty($tenantApp) ? 0 : 1);
            $app['shelf_status'] = $isBuiltin ? AppAccessService::SHELF_ON : ($tenantApp['shelf_status'] ?? 'off');
            $app['enable_status'] = $isBuiltin ? AppAccessService::ENABLED : ($tenantApp['enable_status'] ?? 'disabled');
            $app['expire_time'] = $isBuiltin ? 0 : ($tenantApp['expire_time'] ?? 0);
            $app = AppPlanService::enrichApp($app, $tenantApp, true);
            if ($name !== '') {
                $keyword = mb_strtolower($name);
                $appName = mb_strtolower((string)($app['name'] ?? ''));
                $appCode = mb_strtolower((string)($app['code'] ?? ''));
                if (!str_contains($appName, $keyword) && !str_contains($appCode, $keyword)) {
                    continue;
                }
            }
            if ($isBuyFilter !== '' && (int)$app['is_buy'] !== (int)$isBuyFilter) {
                continue;
            }
            $rows[] = $app;
        }
        return $this->success('获取成功', $rows);
    }

    public function my()
    {
        $lists = TenantApp::where('tenant_id', $this->tenantId)->order('id', 'desc')->select()->toArray();
        $apps = App::whereIn('code', array_column($lists, 'app_code') ?: [''])->column('*', 'code');
        $listedCodes = [];
        foreach ($lists as &$item) {
            $app = $apps[$item['app_code']] ?? [];
            $isBuiltin = (int)($app['is_builtin'] ?? 0) === 1 || DefaultAppService::isDefaultApp((string)$item['app_code']);
            $listedCodes[] = (string)$item['app_code'];
            $item['name'] = $app['name'] ?? $item['app_code'];
            $item['icon'] = $app['icon'] ?? '';
            $item['description'] = $app['description'] ?? '';
            $item['platform_status'] = $app['status'] ?? 'removed';
            $item['is_builtin'] = $isBuiltin ? 1 : 0;
            $item['expire_policy'] = $isBuiltin ? AppPlanService::EXPIRE_ALLOW : ($app['expire_policy'] ?? AppPlanService::EXPIRE_BLOCK);
            if ($isBuiltin) {
                $item['buy_status'] = AppAccessService::BUY_PAID;
                $item['shelf_status'] = AppAccessService::SHELF_ON;
                $item['enable_status'] = AppAccessService::ENABLED;
                $item['expire_time'] = 0;
            }
            $item = AppPlanService::enrichApp(array_merge($app, $item), $item, true);
            $item['app_code'] = $item['app_code'] ?? ($app['code'] ?? '');
        }
        $builtinApps = App::where(['status' => 'installed', 'is_builtin' => 1])->order(['sort' => 'desc', 'id' => 'desc'])->select()->toArray();
        foreach ($builtinApps as $app) {
            if (in_array((string)$app['code'], $listedCodes, true)) {
                continue;
            }
            array_unshift($lists, [
                'id' => 0,
                'tenant_id' => $this->tenantId,
                'app_code' => $app['code'],
                'version' => $app['current_version'],
                'buy_status' => 'paid',
                'shelf_status' => 'on',
                'enable_status' => 'enabled',
                'expire_time' => 0,
                'name' => $app['name'],
                'icon' => $app['icon'],
                'description' => $app['description'],
                'platform_status' => $app['status'],
                'is_builtin' => 1,
                'expire_policy' => AppPlanService::EXPIRE_ALLOW,
                'is_expired' => 0,
                'can_renew' => 0,
                'plans' => [],
            ]);
        }
        return $this->success('获取成功', $lists);
    }

    public function buy()
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            if ($this->isDefaultOpenedApp($appCode)) {
                return $this->success('系统默认应用已开通', [
                    'app_code' => $appCode,
                    'order_type' => AppPlanService::ORDER_OPEN,
                    'points_amount' => '0.00',
                    'before_expire_time' => 0,
                    'after_expire_time' => 0,
                ], 1, 1);
            }
            $planId = (int)$this->request->post('plan_id', 0);
            if ($planId <= 0) {
                return $this->fail('请选择套餐');
            }
            $result = AppPlanService::openOrRenew($this->tenantId, $this->adminId, $appCode, $planId);
            return $this->success($result['order_type'] === AppPlanService::ORDER_RENEW ? '续签成功' : '开通成功', $result, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function shelf()
    {
        $appCode = (string)$this->request->post('app_code', '');
        if ($appCode === 'system_default' || $this->isDefaultOpenedApp($appCode)) {
            return $this->fail('系统默认应用不允许上下架');
        }
        $status = (string)$this->request->post('shelf_status', AppAccessService::SHELF_ON);
        $row = TenantApp::where(['tenant_id' => $this->tenantId, 'app_code' => $appCode])->findOrEmpty();
        if ($row->isEmpty()) {
            return $this->fail('应用未购买');
        }
        $row->shelf_status = $status;
        $row->update_time = time();
        $row->save();
        return $this->success('操作成功', [], 1, 1);
    }

    public function frontend()
    {
        $terminal = (string)$this->request->get('terminal', 'tenant');
        return $this->success('获取成功', AppFrontendManifestService::tenantEntries($this->tenantId, $terminal));
    }

    private function isDefaultOpenedApp(string $appCode): bool
    {
        if ($appCode === '' || !DefaultAppService::isDefaultApp($appCode)) {
            return false;
        }
        return AppRegistryService::isInstalled($appCode);
    }
}
