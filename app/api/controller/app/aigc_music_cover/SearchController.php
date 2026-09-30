<?php

namespace app\api\controller\app\aigc_music_cover;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music_cover\AigcMusicSearchService;
use Exception;

class SearchController extends BaseApiController
{
    public function index()
    {
        try {
            return $this->success('搜索成功', AigcMusicSearchService::search(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
