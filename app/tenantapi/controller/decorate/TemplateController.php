<?php

namespace app\tenantapi\controller\decorate;

use app\common\service\decorate\DecorateTemplateService;
use app\tenantapi\controller\BaseAdminController;
use RuntimeException;

class TemplateController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', DecorateTemplateService::lists($this->tenantId));
    }

    public function detail()
    {
        $id = $this->request->get('id/d', 0);
        $terminal = $this->request->get('terminal/s', DecorateTemplateService::TERMINAL_MOBILE);
        return $this->success('获取成功', DecorateTemplateService::detail($this->tenantId, $id, $terminal));
    }

    public function add()
    {
        return $this->success('创建成功', DecorateTemplateService::add($this->tenantId, $this->request->post()), 1, 1);
    }

    public function edit()
    {
        try {
            DecorateTemplateService::edit($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function copy()
    {
        try {
            $id = $this->request->post('id/d', 0);
            return $this->success('复制成功', DecorateTemplateService::copy($this->tenantId, $id), 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $id = $this->request->post('id/d', 0);
            DecorateTemplateService::delete($this->tenantId, $id);
            return $this->success('删除成功', [], 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function enable()
    {
        try {
            $id = $this->request->post('id/d', 0);
            DecorateTemplateService::enable($this->tenantId, $id);
            return $this->success('启用成功', [], 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function publish()
    {
        try {
            $id = $this->request->post('id/d', 0);
            DecorateTemplateService::publish($this->tenantId, $id);
            return $this->success('发布成功', [], 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function export()
    {
        try {
            $id = $this->request->post('id/d', 0);
            return $this->success('导出成功', DecorateTemplateService::exportPackage($this->tenantId, $id));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function import()
    {
        try {
            $base64 = (string)$this->request->post('file_base64/s', '');
            $filename = (string)$this->request->post('filename/s', '');
            return $this->success('导入成功', DecorateTemplateService::importPackage($this->tenantId, $base64, $filename), 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function saveSettings()
    {
        try {
            $id = $this->request->post('id/d', 0);
            $settings = $this->request->post('settings/a', []);
            DecorateTemplateService::saveSettings($this->tenantId, $id, $settings);
            return $this->success('保存成功', [], 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
