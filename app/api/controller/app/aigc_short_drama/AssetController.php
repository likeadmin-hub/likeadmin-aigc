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
            return $this->success('success', AigcShortDramaService::registerAsset((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function extractVideoLastFrame()
    {
        try {
            return $this->success('success', AigcShortDramaService::extractVideoLastFrame((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function lists()
    {
        try {
            return $this->success('success', AigcShortDramaService::assetLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcShortDramaService::deleteAsset((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('success', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function select()
    {
        try {
            return $this->success('success', AigcShortDramaService::selectStoryboardAsset((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
