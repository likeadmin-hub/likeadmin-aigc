# 双后台运营统计接口

平台与租户均为 `GET /workbench/operations`。通过原有 `workbench/index` 权限校验，租户范围仅取已登录管理员身份，忽略客户端传入的 tenant_id。旧 `workbench/index` 不变。

## 日期

- `range=today|yesterday|7d|30d|custom`，默认 `7d`。
- custom 必填 `start_date`、`end_date`，严格 YYYY-MM-DD，包含首尾日期，不超过90天，结束日期不得晚于今天。
- 上海时区。单天按小时，跨天按日，空桶补0；今天只展示到当前小时。
- 内部开始时间包含，结束时间不包含。今天截至查询时刻。上期按同样日数前移，并比较同样已经过的时长。

## 返回结构

```ts
interface Operations {
  schema_version: 1
  scope: 'platform' | 'tenant'
  updated_at: number // Unix seconds
  range: {
    key: string; start_date: string; end_date: string
    start_at: number; end_at: number
    previous_start_at: number; previous_end_at: number
    bucket: 'hour' | 'day'; timezone: 'Asia/Shanghai'
  }
  status: 'ready' | 'empty' | 'partial' | 'error'
  metrics: Array<{
    key: string; label: string; unit: string; source: string
    value: number | null; previous: number | null; change_percent: number | null
    status: 'ready' | 'unavailable' | 'error'; note: string
  }>
  trend: {labels: string[]; series: Array<{key: string; label: string; unit: string; data: number[]}>}
  distributions: Array<{key: string; label: string; unit: string; status: 'ready'; data: Array<{key: string; name: string; value: number}>}>
  rankings: Array<{key: string; label: string; unit: string; status: 'ready'; rows: Array<{id: string | number; name: string; value: number; secondary_value?: number}>}>
  sources: Array<{key: string; label: string; status: 'ready' | 'unavailable' | 'error'; note: string}>
  warnings: string[]
}
```

金额与点数不得绘制成同一单位序列。当前快照没有上期值；上期为0不计算增长率。无已结束任务时成功率为null。来源缺失/读取失败时对应指标为null，图表不返回该来源，不能解释成业务0。

## 指标

当前快照：`tenant_total`、`tenant_enabled`（平台），`user_total`、`user_point_balance`（租户），`tenant_point_balance`、`apps_enabled`（双方）。

按选定期间：

- 双方：`task_total`、`task_success`、`task_failed`、`task_running`、`task_success_rate`、`active_users`、`settled_tenant_points`、`settled_user_points`、`cash_received`、`paid_orders`、`refund_amount`、`app_openings`。
- 平台：`tenant_new`、`active_tenants`。
- 租户：`user_new`、`points_issued`、`points_consumed`、`points_returned`。

财务核心另提供 `order_average`（付费客单价，元/笔）：所选期间 `cash_received / paid_orders`，使用相同外部已支付订单口径，未扣退款；没有付费订单或支付来源不可用时为null。上期也按同口径计算，不从点数或任务价格推算收入。

图表：`task_status`、`cash_sources`、`point_issuance`（租户）。排行：`apps_by_tasks`、`apps_by_points`（value为租户结算点数，secondary_value为用户结算点数）、`tenants_by_points`（平台）、`users_by_tasks`（租户），最多10条。除快照和成功率外，各期间指标有趋势。

## 来源口径与边界

- 任务来自集中 `ai_app_task` 顶层记录，按创建时间分组，包含当前状态；不会逐应用查询动态业务表。成功率分母是成功、部分成功、失败、取消的已结束任务。未接入统一记录的历史任务不包含。
- 活跃是有顶层任务的去重用户/租户。平台用户按租户+用户复合身份去重，不用 user.login_time 伪造历史DAU。完整访问活跃需要另行建设事件埋点，本次不新增。
- 实际结算只读 `ai_consumption_log` 的 settled 行及实际结算快照，按finish_time；不从应用任务可变价格字段推算，也不把预占/退款当收入。历史独立账单不包含。
- 平台收款：算力商城、合约、贴牌额度采购三类订单。租户收款：充值、会员订单。仅 pay_status=1、pay_way=2/3/4、正金额，按pay_time。已删除的已支付订单仍保留财务统计。点数支付、赠送、卡密、应用点数开通均不计现金；同一支付不再叠加receipt表。
- 租户退款合并充值/会员 `refund_record` 成功记录及虚拟支付已确认全额退款。虚拟退款要求intent成功、同租户/应用/环境的refund回执、金额与订单及intent一致、原支付交易号一致；按订单去重，以首次确认回执时间归属。同一虚拟订单的传统兼容退款记录跨日期排除，不叠加refund_log重试次数。传统退款没有独立确认时间，仍按update_time归属；重复付款退款不冲减仅有一份的订单收款。
- 平台退款仅覆盖贴牌额度的原始付款退款，按confirmed_time；重复支付的退回不冲减只有一份的订单收款。不声称这是全部平台退款，不计算平台净收入/利润。
- 用户点数发放/消费/退回来自分表感知且tenant_id隔离的余额流水。发放含后台、充值、会员、注册、卡密；消费扣减包含预占，因此与已结算指标分开。当前余额读取账户快照，不触发到期处理或任何写入。
- 核心集中表固定数量聚合；唯一分表读取是当前租户的user与user_account_log，不遍历所有租户或动态应用表。排行榜名称批量查询，最多10个ID。

验证：`sh tests/workbench-operations/run-local.sh`。数据库测试只允许本机 z_cn，事务内构造两租户和临时分表，finally回滚并检查fixture租户未留下。不发送外部通知，不修改已有业务余额。
