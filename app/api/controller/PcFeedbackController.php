<?php

namespace app\api\controller;

use app\common\enum\FileEnum;
use app\common\service\PcFeedbackService;
use app\common\service\UploadService;
use Exception;

class PcFeedbackController extends BaseApiController
{
    public array $notNeedLogin = ['submit', 'uploadImage'];

    public function submit()
    {
        try {
            return $this->success('提交成功', PcFeedbackService::submit(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function lists()
    {
        try {
            return $this->success('获取成功', PcFeedbackService::userLists(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->get()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function uploadImage()
    {
        try {
            return $this->success('上传成功', UploadService::image(
                0,
                $this->userId,
                FileEnum::SOURCE_USER,
                'uploads/feedback'
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
