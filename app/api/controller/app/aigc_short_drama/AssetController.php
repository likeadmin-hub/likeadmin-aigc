<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class AssetController extends BaseApiController
{
    public function register()
    {
        try {
            return $this->success('登记成功', AigcShortDramaService::registerAsset((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function extractVideoLastFrame()
    {
        try {
            return $this->success('提取成功', AigcShortDramaService::extractVideoLastFrame((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function lists()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::assetLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcShortDramaService::deleteAsset((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function select()
    {
        try {
            return $this->success('应用成功', AigcShortDramaService::selectStoryboardAsset((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
