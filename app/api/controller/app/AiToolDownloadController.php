<?php

namespace app\api\controller\app;

use app\api\controller\BaseApiController;
use app\common\cache\ExportCache;
use app\common\service\app\AiToolDownloadService;
use app\common\service\JsonService;
use Exception;

class AiToolDownloadController extends BaseApiController
{
    public array $notNeedLogin = ['export'];

    public function zip()
    {
        try {
            return $this->success('获取成功', AiToolDownloadService::createZip(
                (int)$this->request->tenantId,
                $this->userId,
                (string)$this->request->post('app_code', ''),
                (int)$this->request->post('task_id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function export()
    {
        $fileKey = (string)$this->request->get('file', '');
        $exportCache = new ExportCache();
        $fileInfo = $exportCache->getFile($fileKey);
        if (empty($fileInfo) || empty($fileInfo['src']) || empty($fileInfo['name'])) {
            return JsonService::fail('下载文件不存在');
        }

        $filePath = $fileInfo['src'] . $fileInfo['name'];
        if (!is_file($filePath)) {
            $exportCache->delete($fileKey);
            return JsonService::fail('下载文件不存在');
        }

        $exportCache->delete($fileKey);
        return download($filePath, $fileInfo['name']);
    }
}
