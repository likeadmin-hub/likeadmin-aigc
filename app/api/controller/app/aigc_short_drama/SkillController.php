<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
use app\common\service\app\aigc_short_drama\ShortDramaSkillRuntime;
use Exception;

class SkillController extends BaseApiController
{
    public array $notNeedLogin = ['featured', 'detail'];

    public function featured()
    {
        try { return $this->success('获取成功', ShortDramaSkillService::featured((int)$this->request->tenantId, $this->request->get())); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function detail()
    {
        try { return $this->success('获取成功', ShortDramaSkillService::detail((int)$this->request->tenantId, (int)$this->request->get('id', 0), true)); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function mine()
    {
        try { return $this->success('获取成功', ShortDramaSkillService::mine((int)$this->request->tenantId, $this->userId)); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function history()
    {
        try { return $this->success('获取成功', ShortDramaSkillService::history((int)$this->request->tenantId, $this->userId, $this->request->get())); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function confirm()
    {
        try { return $this->success('确认成功', ShortDramaSkillRuntime::confirm((int)$this->request->tenantId, $this->userId, (string)$this->request->post('task_id', ''), (string)$this->request->post('node', ''))); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function default()
    {
        try {
            ShortDramaSkillService::setDefault((int)$this->request->tenantId, $this->userId, (int)$this->request->post('skill_id', 0), (bool)$this->request->post('enabled', true));
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Read-only recommendation. A user must confirm before a task can use it. */
    public function recommend()
    {
        try { return $this->success('获取成功', ShortDramaSkillService::recommend((int)$this->request->tenantId, $this->request->post(), $this->userId)); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }
}
