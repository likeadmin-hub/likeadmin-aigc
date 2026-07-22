<?php

namespace app\tenantapi\controller\setting;

use app\common\model\TenantConfig;
use app\common\model\tenant\Tenant;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\StorageSettingService;
use app\common\service\ai\AiTaskResultStorageService;
use app\tenantapi\controller\BaseAdminController;

class StorageController extends BaseAdminController
{
    public function lists()
    {
        $default = $this->getConfig('default', 'local');
        $enabled = (int)$this->getConfig('enable', 0) === 1;
        $allowLocalStorage = $this->allowLocalStorage();
        $lists = [
            ['name' => '七牛云存储', 'path' => '存储在七牛云，请前往七牛云开通存储服务', 'engine' => 'qiniu', 'status' => $enabled && $default === 'qiniu' ? 1 : 0],
            ['name' => '阿里云OSS', 'path' => '存储在阿里云，请前往阿里云开通存储服务', 'engine' => 'aliyun', 'status' => $enabled && $default === 'aliyun' ? 1 : 0],
            ['name' => '腾讯云COS', 'path' => '存储在腾讯云，请前往腾讯云开通存储服务', 'engine' => 'qcloud', 'status' => $enabled && $default === 'qcloud' ? 1 : 0],
        ];
        if ($allowLocalStorage) {
            array_unshift($lists, ['name' => '本地存储', 'path' => '存储在本地服务器', 'engine' => 'local', 'status' => $enabled && $default === 'local' ? 1 : 0]);
        }
        return $this->success('获取成功', [
            'allow_custom_storage' => $this->allowCustomStorage(),
            'allow_local_storage' => $allowLocalStorage,
            'result_transfer_enabled' => AiTaskResultStorageService::transferEnabled($this->tenantId) ? 1 : 0,
            'lists' => $lists,
        ]);
    }

    public function detail()
    {
        $engine = (string)$this->request->get('engine', 'local');
        if ($engine === 'local' && !$this->allowLocalStorage()) {
            return $this->fail('平台未允许该租户使用本地存储');
        }
        $default = $this->getConfig('default', 'local');
        $enabled = (int)$this->getConfig('enable', 0) === 1;
        $config = $engine === 'local' ? [] : $this->getConfig($engine, []);
        $config['status'] = $enabled && $engine === $default ? 1 : 0;
        $config['enable'] = $enabled ? 1 : 0;
        $config['allow_custom_storage'] = $this->allowCustomStorage();
        $config['allow_local_storage'] = $this->allowLocalStorage();
        return $this->success('获取成功', $config);
    }

    public function setup()
    {
        $params = $this->request->post();
        if (array_key_exists('result_transfer_enabled', $params)) {
            AiTaskResultStorageService::setTransferEnabled(
                $this->tenantId,
                (int)$params['result_transfer_enabled'] === 1
            );
            return $this->success('配置成功', [], 1, 1);
        }
        if (!$this->allowCustomStorage()) {
            return $this->fail('平台未允许该租户自定义存储');
        }
        $engine = (string)($params['engine'] ?? 'local');
        $enabled = (int)($params['status'] ?? 0) === 1;
        if ($engine === 'local' && $enabled && !$this->allowLocalStorage()) {
            return $this->fail('平台未允许该租户使用本地存储');
        }
        StorageSettingService::set('tenant', $this->tenantId, 'enable', $enabled ? 1 : 0);
        StorageSettingService::set('tenant', $this->tenantId, 'default', $enabled ? $engine : 'local');
        if ($engine !== 'local') {
            StorageSettingService::set('tenant', $this->tenantId, $engine, [
                'bucket' => $params['bucket'] ?? '',
                'region' => $params['region'] ?? '',
                'access_key' => $params['access_key'] ?? '',
                'secret_key' => $params['secret_key'] ?? '',
                'domain' => $params['domain'] ?? '',
            ]);
        }
        StorageConfigService::clearCache($this->tenantId);
        return $this->success('配置成功', [], 1, 1);
    }

    public function change()
    {
        if (!$this->allowCustomStorage()) {
            return $this->fail('平台未允许该租户自定义存储');
        }
        $engine = (string)$this->request->post('engine', 'local');
        if ($engine === 'local' && !$this->allowLocalStorage()) {
            return $this->fail('平台未允许该租户使用本地存储');
        }
        $default = $this->getConfig('default', 'local');
        $turnOff = $default === $engine;
        StorageSettingService::set('tenant', $this->tenantId, 'enable', $turnOff && !$this->allowLocalStorage() ? 0 : 1);
        StorageSettingService::set('tenant', $this->tenantId, 'default', $turnOff ? 'local' : $engine);
        StorageConfigService::clearCache($this->tenantId);
        return $this->success('切换成功', [], 1, 1);
    }

    private function allowCustomStorage(): bool
    {
        return (int)Tenant::where('id', $this->tenantId)->value('allow_custom_storage') === 1;
    }

    private function allowLocalStorage(): bool
    {
        return (int)Tenant::where('id', $this->tenantId)->value('allow_local_storage') === 1;
    }

    private function getConfig(string $name, mixed $default)
    {
        $value = TenantConfig::where(['tenant_id' => $this->tenantId, 'type' => 'storage', 'name' => $name])->value('value');
        if ($value === null) {
            return $default;
        }
        $json = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $json : $value;
    }
}
