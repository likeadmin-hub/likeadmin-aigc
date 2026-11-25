<?php

declare(strict_types=1);

namespace app\tenantapi\controller\app\aigc_video_enhance;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_video_enhance\VideoEnhanceConfig;

final class ConfigController extends BaseAdminController
{
    public function detail()
    {
        $tenant = $this->tenantId;
        if ($tenant < 1) return $this->fail('租户身份无效');
        return $this->success('', VideoEnhanceConfig::admin($tenant));
    }

    public function setup()
    {
        try {
            $tenant = $this->tenantId;
            if ($tenant < 1) throw new \InvalidArgumentException('租户身份无效');
            VideoEnhanceConfig::save($tenant, $this->request->post());
            return $this->success('保存成功');
        } catch (\InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
