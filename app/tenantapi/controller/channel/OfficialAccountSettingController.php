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

namespace app\tenantapi\controller\channel;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\logic\channel\OfficialAccountSettingLogic;
use app\tenantapi\validate\channel\OfficialAccountSettingValidate;

/**
 * 公众号设置
 * Class OfficialAccountSettingController
 * @package app\tenantapi\controller\channel
 */
class OfficialAccountSettingController extends BaseAdminController
{
    /** 生成表单候选值，不修改已保存的公众号配置。 */
    public function generateCredential()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用POST请求');
        }
        $type = $this->request->post('type', '');
        if ($type === 'token') {
            $value = bin2hex(random_bytes(16));
        } elseif ($type === 'encoding_aes_key') {
            // 32字节密钥去除Base64填充后为43位，排除微信不接受的符号。
            do {
                $value = rtrim(base64_encode(random_bytes(32)), '=');
            } while (!preg_match('/^[A-Za-z0-9]{43}$/D', $value));
        } else {
            return $this->fail('不支持的凭证类型');
        }
        return $this->data(['value' => $value]);
    }

    /**
     * @notes 获取公众号配置
     * @return \think\response\Json
     * @author ljj
     * @date 2022/2/16 10:09 上午
     */
    public function getConfig()
    {
        $result = (new OfficialAccountSettingLogic())->getConfig();
        return $this->data($result);
    }

    /**
     * @notes 设置公众号配置
     * @return \think\response\Json
     * @author ljj
     * @date 2022/2/16 10:09 上午
     */
    public function setConfig()
    {
        $params = (new OfficialAccountSettingValidate())->post()->goCheck();
        (new OfficialAccountSettingLogic())->setConfig($params);
        return $this->success('操作成功',[],1,1);
    }
}
