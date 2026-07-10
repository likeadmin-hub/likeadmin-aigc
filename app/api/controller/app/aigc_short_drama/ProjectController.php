<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class ProjectController extends BaseApiController
{
    public function lists()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::projectLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::projectDetail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function rename()
    {
        try {
            return $this->success('保存成功', AigcShortDramaService::renameProject(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('id', 0),
                (string)$this->request->post('title', '')
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function coverOptions()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::projectCoverOptions(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->get('id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function cover()
    {
        try {
            return $this->success('保存成功', AigcShortDramaService::updateProjectCover(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('id', 0),
                (int)$this->request->post('asset_id', 0)
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            return $this->success('删除成功', AigcShortDramaService::deleteProject(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('id', 0)
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function publish()
    {
        try {
            $params = $this->request->post();
            if (isset($params['final_video_asset_id']) && empty($params['video_asset_id'])) {
                $params['video_asset_id'] = $params['final_video_asset_id'];
            }
            return $this->success('发布成功', AigcShortDramaService::submitPublishedWork((int)$this->request->tenantId, $this->userId, $params));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
