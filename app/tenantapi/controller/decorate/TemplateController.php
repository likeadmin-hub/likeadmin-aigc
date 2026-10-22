<?php

namespace app\tenantapi\controller\decorate;

use app\common\service\decorate\DecorateTemplateService;
use app\common\service\decorate\DecorationTransferProgress;
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

    public function history()
    {
        try {
            $id = $this->request->get('id/d', 0);
            return $this->success('获取成功', DecorateTemplateService::publishedHistory($this->tenantId, $id));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function rollback()
    {
        try {
            $id = $this->request->post('id/d', 0);
            $historyId = $this->request->post('history_id/s', '');
            DecorateTemplateService::rollback($this->tenantId, $id, $historyId);
            return $this->success('回退成功', [], 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function transferProgress()
    {
        try {
            $id = (string)$this->request->get('operation_id/s', '');
            return $this->success('获取成功', DecorationTransferProgress::read($this->tenantId, $this->adminId, $id));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function export()
    {
        $progress = null;
        try {
            $progress = DecorationTransferProgress::begin($this->tenantId, $this->adminId, (string)$this->request->post('operation_id/s', ''), 'export');
            $id = $this->request->post('id/d', 0);
            $result = DecorateTemplateService::exportPackage($this->tenantId, $id, [$progress, 'step']);
            $progress->succeed();
            return $this->success('导出成功', $result);
        } catch (\Throwable $e) {
            $progress?->fail($e instanceof RuntimeException ? $e->getMessage() : '模板导出异常，请查看服务日志');
            if ($e instanceof RuntimeException) return $this->fail($e->getMessage());
            throw $e;
        }
    }

    public function import()
    {
        $progress = null;
        try {
            $progress = DecorationTransferProgress::begin($this->tenantId, $this->adminId, (string)$this->request->post('operation_id/s', ''), 'import');
            $base64 = (string)$this->request->post('file_base64/s', '');
            $filename = (string)$this->request->post('filename/s', '');
            $result = DecorateTemplateService::importPackage($this->tenantId, $base64, $filename, [$progress, 'step']);
            // The transaction has committed before reporting success to pollers.
            $progress->succeed($result);
            return $this->success('导入成功', $result);
        } catch (\Throwable $e) {
            $progress?->fail($e instanceof RuntimeException ? $e->getMessage() : '模板导入异常，请先刷新列表确认结果');
            if ($e instanceof RuntimeException) return $this->fail($e->getMessage());
            throw $e;
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
