<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class SkillCategoryController extends BaseAdminController
{
    public function lists() { try { return $this->success('获取成功', ShortDramaSkillService::categories($this->tenantId, false)); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function save() { try { return $this->success('保存成功', ShortDramaSkillService::saveCategory($this->tenantId, $this->request->post()), 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
