<?php

declare(strict_types=1);

namespace app\platformapi\controller\app\aigc_video_enhance;

use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_video_enhance\VideoEnhanceConfig;

final class ConfigController extends BaseAdminController
{
    public function detail()
    {
        $tenant = 0;
        return $this->success('', VideoEnhanceConfig::admin($tenant));
    }

    public function setup()
    {
        try {
            $tenant = 0;
            VideoEnhanceConfig::save($tenant, $this->request->post());
            return $this->success('保存成功');
        } catch (\InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
