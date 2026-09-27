<?php
namespace app\api\controller\app\aigc_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_image\ImagePromptEnhanceService;
use Exception;
use Throwable;

class PromptController extends BaseApiController
{
    public function enhance()
    {
        try {
            return $this->success('增强成功', ImagePromptEnhanceService::enhance(
                (int)$this->request->tenantId, $this->userId, $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            return $this->fail('提示词增强暂不可用，请稍后重试');
        }
    }
}
