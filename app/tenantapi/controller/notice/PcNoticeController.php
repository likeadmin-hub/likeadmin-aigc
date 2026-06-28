<?php

namespace app\tenantapi\controller\notice;

use app\common\service\notice\TenantPcNoticeService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\notice\PcNoticeLists;
use app\tenantapi\validate\notice\PcNoticeValidate;
use Exception;

class PcNoticeController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new PcNoticeLists());
    }

    public function detail()
    {
        $params = (new PcNoticeValidate())->goCheck('detail');
        return $this->data(TenantPcNoticeService::detail($this->tenantId, (int)$params['id']));
    }

    public function add()
    {
        $params = (new PcNoticeValidate())->post()->goCheck('add');
        try {
            TenantPcNoticeService::save($this->tenantId, $params);
            return $this->success('新增成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function edit()
    {
        $params = (new PcNoticeValidate())->post()->goCheck('edit');
        try {
            TenantPcNoticeService::save($this->tenantId, $params);
            return $this->success('编辑成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        $params = (new PcNoticeValidate())->post()->goCheck('delete');
        try {
            TenantPcNoticeService::delete($this->tenantId, (int)$params['id']);
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        $params = (new PcNoticeValidate())->post()->goCheck('status');
        try {
            TenantPcNoticeService::status($this->tenantId, (int)$params['id'], (int)$params['status']);
            return $this->success('操作成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
