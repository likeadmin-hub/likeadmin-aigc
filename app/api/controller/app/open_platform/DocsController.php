<?php
namespace app\api\controller\app\open_platform;
use app\api\controller\BaseApiController;
use app\common\service\open_platform\OpenDocuments;
class DocsController extends BaseApiController
{
    public array $notNeedLogin=['lists','openapi'];
    public function lists(){return $this->success('获取成功',OpenDocuments::publicLists((int)$this->request->tenantId,(string)$this->request->get('search','')));}
    public function openapi(){return json(OpenDocuments::openapi((int)$this->request->tenantId));}
}
