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
namespace app\platformapi\controller\upgrade;

use app\common\service\update\PackageExtractService;
use app\common\service\update\SystemPackageUpdateService;
use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\upgrade\UpgradeLists;
use app\platformapi\logic\upgrade\UpgradeLogic;
use app\platformapi\validate\upgrade\downloadPkgValidate;
use app\platformapi\validate\upgrade\UpgradeValidate;
use think\response\Json;

/**
 * 系统更新
 */
class UpgradeController extends BaseAdminController
{
    public function overview(): Json
    {
        try {
            return $this->success('获取成功', (new SystemPackageUpdateService())->overview());
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function source(): Json
    {
        try {
            return $this->success('获取成功', UpdateSourceClient::getSource());
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function saveSource(): Json
    {
        try {
            return $this->success('保存成功', UpdateSourceClient::saveSource($this->request->post()), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function environment(): Json
    {
        return $this->success('获取成功', PackageExtractService::environment(root_path()));
    }

    public function cloudVersions(): Json
    {
        try {
            return $this->success('获取成功', (new SystemPackageUpdateService())->versions($this->request->get()));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function downloadCloudPackage(): Json
    {
        try {
            $targetVersion = (string)$this->request->post('target_version', '');
            $currentVersion = (string)$this->request->post('current_version', '');
            return $this->success('下载成功', (new SystemPackageUpdateService())->downloadPackage($targetVersion, $currentVersion), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function preflightPackage(): Json
    {
        try {
            return $this->success('预检完成', (new SystemPackageUpdateService())->preflight((int)$this->request->post('package_id', 0)));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function applyPackage(): Json
    {
        try {
            return $this->success('系统更新成功', (new SystemPackageUpdateService())->apply((int)$this->request->post('package_id', 0)), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function ignoreVersion(): Json
    {
        try {
            $version = (string)$this->request->post('version', '');
            return $this->success('忽略成功', (new SystemPackageUpdateService())->ignoreVersion($version), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function licenseInfo(): Json
    {
        try {
            return $this->success('获取成功', (new UpdateLicenseService())->info());
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function machineCode(): Json
    {
        try {
            return $this->success('获取成功', (new UpdateLicenseService())->machineCode());
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function importLicense(): Json
    {
        try {
            $file = $this->request->file('file');
            if (!$file) {
                return $this->fail('请上传授权文件');
            }
            $dir = runtime_path() . 'update_license/';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $saveName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.json';
            $file->move($dir, $saveName);
            return $this->success('导入成功', (new UpdateLicenseService())->import($dir . $saveName), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function import_license(): Json
    {
        return $this->importLicense();
    }

    public function licenseImport(): Json
    {
        return $this->importLicense();
    }

    public function logs(): Json
    {
        try {
            return $this->data((new SystemPackageUpdateService())->versionLogs($this->request->get()));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * @notes 查看系统更新列表
     * @return Json
     * @author 段誉
     * @date 2021/8/14 17:17
     */
    public function lists(): Json
    {
        return $this->dataLists(new UpgradeLists());
    }

    /**
     * @notes 执行系统更新
     * @return Json
     * @author 段誉
     * @date 2021/8/14 16:51
     */
    public function upgrade(): Json
    {
        $params = (new UpgradeValidate())->post()->goCheck();
        $params['update_type'] = 1; // 一键更新类型
        if (true === UpgradeLogic::upgrade($params)) {
            return $this->success('更新成功', [], 1, 1);
        }
        return $this->fail('更新失败:'. UpgradeLogic::getError());
    }

    /**
     * @notes 下载更新包
     * @return Json
     * @author 段誉
     * @date 2021/10/8 14:23
     */
    public function downloadPkg(): Json
    {
        $params = (new downloadPkgValidate())->post()->goCheck();
        $result = UpgradeLogic::getPkgLine($params);
        if (false === $result) {
            return $this->fail(UpgradeLogic::getError());
        }
        return $this->success('', $result);
    }
}
