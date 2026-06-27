<?php

namespace app\platformapi\controller;

use app\common\model\app\App;
use app\common\service\app\AppPlanService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\DefaultAppService;
use app\common\service\update\AppPackageUpdateService;
use app\common\service\update\PackageExtractService;
use app\common\service\update\UpdateSourceClient;
use app\common\service\update\UpdateLicenseService;
use Exception;
use think\response\Json;

class AppController extends BaseAdminController
{
    public function lists()
    {
        $lists = App::order(['sort' => 'desc', 'id' => 'desc'])->select()->toArray();
        foreach ($lists as &$item) {
            if (!DefaultAppService::isDefaultApp((string)($item['code'] ?? ''))) {
                $item['is_builtin'] = 0;
                continue;
            }
            $item['is_builtin'] = 1;
            $item['expire_policy'] = AppPlanService::EXPIRE_ALLOW;
            $item['status'] = AppRegistryService::STATUS_INSTALLED;
        }
        return $this->success('获取成功', $lists);
    }

    public function plans(): Json
    {
        try {
            $appCode = (string)$this->request->get('app_code', '');
            return $this->success('获取成功', AppPlanService::plans($appCode));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function savePlan(): Json
    {
        try {
            return $this->success('保存成功', AppPlanService::savePlan($this->request->post())->toArray(), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deletePlan(): Json
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            $planId = (int)$this->request->post('id', 0);
            AppPlanService::deletePlan($appCode, $planId);
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function saveExpirePolicy(): Json
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            $policy = (string)$this->request->post('expire_policy', AppPlanService::EXPIRE_BLOCK);
            AppPlanService::saveExpirePolicy($appCode, $policy);
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function cloudLists(): Json
    {
        try {
            $params = $this->request->get();
            return $this->success('获取成功', (new AppPackageUpdateService())->cloudLists($params));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function cloudDetail(): Json
    {
        try {
            $appCode = (string)$this->request->get('app_code', '');
            return $this->success('获取成功', (new AppPackageUpdateService())->cloudDetail($appCode));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function downloadPackage(): Json
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            $targetVersion = (string)$this->request->post('target_version', '');
            $action = (string)$this->request->post('action', 'install');
            return $this->success('下载成功', (new AppPackageUpdateService())->downloadCloudPackage($appCode, $targetVersion, $action), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function uploadPackage(): Json
    {
        try {
            $file = $this->request->file('file');
            if (!$file) {
                return $this->fail('请上传应用离线包');
            }
            $extension = strtolower($file->extension());
            $format = in_array($extension, ['gz', 'tgz'], true) ? 'tar.gz' : ($extension ?: 'zip');
            $dir = runtime_path() . 'update_uploads/';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $saveName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . ($format === 'tar.gz' ? 'tar.gz' : $format);
            $path = $dir . $saveName;
            $file->move($dir, $saveName);
            return $this->success('上传成功', (new AppPackageUpdateService())->saveUploadedPackage($path, $format), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function preflightPackage(): Json
    {
        try {
            $packageId = (int)$this->request->post('package_id', 0);
            $appCode = (string)$this->request->post('app_code', '');
            return $this->success('预检完成', (new AppPackageUpdateService())->preflight($packageId, $appCode));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function applyPackage(): Json
    {
        try {
            $packageId = (int)$this->request->post('package_id', 0);
            return $this->success('应用更新成功', (new AppPackageUpdateService())->apply($packageId), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function updateSource(): Json
    {
        try {
            return $this->success('获取成功', [
                'source' => UpdateSourceClient::getSource(),
                'environment' => PackageExtractService::environment(root_path() . 'app/apps'),
            ]);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function saveUpdateSource(): Json
    {
        try {
            return $this->success('保存成功', UpdateSourceClient::saveSource($this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function install()
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            $manifest = AppRegistryService::installFromLocal($appCode);
            return $this->success('安装成功', $manifest, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            $appCode = (string)$this->request->get('app_code', '');
            return $this->success('获取成功', AppRegistryService::detail($appCode));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function enable()
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            AppRegistryService::setStatus($appCode, AppRegistryService::STATUS_INSTALLED);
            return $this->success('启用成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function disable()
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            AppRegistryService::setStatus($appCode, AppRegistryService::STATUS_DISABLED);
            return $this->success('禁用成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function uninstall()
    {
        try {
            $appCode = (string)$this->request->post('app_code', '');
            $clearData = (int)$this->request->post('clear_data', 0) === 1;
            AppRegistryService::uninstall($appCode, $clearData);
            return $this->success('卸载成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
