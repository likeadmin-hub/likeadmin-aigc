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

namespace app\api\lists;

use app\common\enum\user\AccountLogEnum;
use app\common\lists\ListsExtendInterface;
use app\common\model\user\UserAccountLog;
use app\common\model\user\User;


/**
 * 账户流水列表
 * Class AccountLogLists
 * @package app\shopapi\lists
 */
class AccountLogLists extends BaseApiDataLists implements ListsExtendInterface
{

    /**
     * @notes 搜索条件
     * @return array
     * @author 段誉
     * @date 2023/2/24 14:43
     */
    public function queryWhere()
    {
        // 指定用户
        $where[] = ['user_id', '=', $this->userId];

        // 用户月明细
        if (isset($this->params['type']) && $this->params['type'] == 'um') {
            $where[] = ['change_type', 'in', AccountLogEnum::getUserMoneyChangeType()];
        }

        // 变动类型
        if (!empty($this->params['action'])) {
            $where[] = ['action', '=', $this->params['action']];
        }

        return $where;
    }


    /**
     * @notes 获取列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2023/2/24 14:43
     */
    public function lists(): array
    {
        $field = 'id,change_type,change_amount,action,left_amount,source_sn,create_time,remark,extra';
        $lists = UserAccountLog::field($field)
            ->where($this->queryWhere())
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        foreach ($lists as &$item) {
            $item['type_desc'] = AccountLogEnum::getChangeTypeDesc($item['change_type']);
            $symbol = $item['action'] == AccountLogEnum::DEC ? '-' : '+';
            $item['change_amount_desc'] = $symbol . $item['change_amount'];
            $item['extra'] = $item['extra'] ? json_decode($item['extra'], true) ?: [] : [];
        }

        return $lists;
    }


    /**
     * @notes 获取数量
     * @return int
     * @author 段誉
     * @date 2023/2/24 14:44
     */
    public function count(): int
    {
        return UserAccountLog::where($this->queryWhere())->count();
    }


    /**
     * @notes 用户点数汇总
     * @return array
     */
    public function extend(): array
    {
        $baseWhere = [
            ['user_id', '=', $this->userId],
            ['change_type', 'in', AccountLogEnum::getUserMoneyChangeType()],
        ];

        return [
            'user_money' => number_format((float)User::where(['id' => $this->userId])->value('user_money'), 2, '.', ''),
            'total_income' => number_format((float)UserAccountLog::where($baseWhere)
                ->where('action', AccountLogEnum::INC)
                ->sum('change_amount'), 2, '.', ''),
            'total_consume' => number_format((float)UserAccountLog::where($baseWhere)
                ->where('action', AccountLogEnum::DEC)
                ->sum('change_amount'), 2, '.', ''),
        ];
    }
}
