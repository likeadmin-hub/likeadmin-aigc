<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class SubjectController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        try {
            return $this->success('success', AigcShortDramaService::subjectLibraryLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            return $this->success('success', AigcShortDramaService::saveSubjectLibrary((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcShortDramaService::deleteSubjectLibrary((int)$this->request->tenantId, $this->userId, (int)$this->request->post('id', 0));
            return $this->success('success', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function describe()
    {
        try {
            return $this->success('success', AigcShortDramaService::describeSubjectImage((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generate()
    {
        try {
            return $this->success('success', AigcShortDramaService::createSubjectLibraryGeneration((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function imageHistory()
    {
        try {
            return $this->success('success', AigcShortDramaService::subjectImageHistory((int)$this->request->tenantId, $this->userId, (int)$this->request->get('subject_id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function registerImage()
    {
        try {
            return $this->success('success', AigcShortDramaService::registerSubjectImageAsset((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function selectImage()
    {
        try {
            return $this->success('success', AigcShortDramaService::selectSubjectImageAsset((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function threeViewHistory()
    {
        try {
            return $this->success('success', AigcShortDramaService::subjectThreeViewHistory((int)$this->request->tenantId, $this->userId, (int)$this->request->get('subject_id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
