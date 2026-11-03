<?php
namespace app\tenantapi\controller\app\aigc_copywriting;

use app\common\service\app\AppDisplayConfigService;

class ConfigController extends Controller
{
    public function detail()
    {
        return $this->call(fn() => AppDisplayConfigService::appendToConfig($this->tenantId, 'aigc_copywriting', []));
    }

    public function setup()
    {
        return $this->call(function () {
            $display = $this->request->post('display_config', [], null);
            if (!is_array($display)) {
                throw new \InvalidArgumentException('展示配置格式不正确');
            }
            return ['display_config' => AppDisplayConfigService::save($this->tenantId, 'aigc_copywriting', $display)];
        });
    }
}
