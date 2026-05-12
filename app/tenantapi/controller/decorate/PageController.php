<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\tenantapi\controller\decorate;


use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\decorate\DecoratePageLogic;
use app\tenantapi\validate\decorate\DecoratePageValidate;
use app\common\service\decorate\DecorateTemplateService;
use RuntimeException;


/**
 * 装修页面
 * Class DecoratePageController
 * @package app\tenantapi\controller\decorate
 */
class PageController extends BaseAdminController
{

    /**
     * @notes 获取装修修页面详情
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/14 18:43
     */
    public function detail()
    {
        if ($this->request->get('template_id/d', 0) > 0 || $this->request->get('page_code/s', '') !== '') {
            $result = DecorateTemplateService::pageDetail($this->tenantId, $this->request->get());
            return $this->success('获取成功', $result);
        }
        $result = DecoratePageLogic::getDetail([
            'id' => $this->request->get('id/d', 0),
            'type' => $this->request->get('type/d', 0),
        ]);
        return $this->success('获取成功', $result);
    }

    public function lists()
    {
        $templateId = $this->request->get('template_id/d', 0);
        $terminal = $this->request->get('terminal/s', DecorateTemplateService::TERMINAL_MOBILE);
        $detail = DecorateTemplateService::detail($this->tenantId, $templateId, $terminal);
        return $this->success('获取成功', $detail['pages']);
    }


    /**
     * @notes 保存装修配置
     * @return \think\response\Json
     * @author 段誉
     * @date 2022/9/15 9:57
     */
    public function save()
    {
        if ($this->request->post('template_id/d', 0) > 0) {
            try {
                return $this->success('操作成功', DecorateTemplateService::savePage($this->tenantId, $this->request->post()), 1, 1);
            } catch (RuntimeException $e) {
                return $this->fail($e->getMessage());
            }
        } else {
            $params = (new DecoratePageValidate())->post()->goCheck();
            $result = DecoratePageLogic::save($params);
            if (false === $result) {
                return $this->fail(DecoratePageLogic::getError());
            }
        }
        return $this->success('操作成功', [], 1, 1);
    }

    public function add()
    {
        try {
            return $this->success('创建成功', DecorateTemplateService::addPage($this->tenantId, $this->request->post()), 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function edit()
    {
        try {
            return $this->success('保存成功', DecorateTemplateService::updatePageBase($this->tenantId, $this->request->post()), 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function copy()
    {
        try {
            $id = $this->request->post('id/d', 0);
            return $this->success('复制成功', DecorateTemplateService::copyPage($this->tenantId, $id), 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $id = $this->request->post('id/d', 0);
            DecorateTemplateService::deletePage($this->tenantId, $id);
            return $this->success('删除成功', [], 1, 1);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function linkLists()
    {
        $templateId = $this->request->get('template_id/d', 0);
        $terminal = $this->request->get('terminal/s', DecorateTemplateService::TERMINAL_MOBILE);
        return $this->success('获取成功', DecorateTemplateService::linkLists($this->tenantId, $templateId, $terminal));
    }


}
