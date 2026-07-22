<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        // 定时任务
        'crontab' => 'app\common\command\Crontab',
        // 退款查询
        'query_refund' => 'app\common\command\QueryRefund',
        // 充值到账修复
        'recharge:repair' => 'app\common\command\RepairRecharge',
        // AIGC消耗日志补偿刷新
        'ai:usage_reconcile' => 'app\common\command\AiUsageReconcile',
        // AIGC 异步结果 Worker（宝塔守护进程）
        'ai:task-worker' => 'app\common\command\AiTaskWorker',
        // AIGC 关联业务结果修复
        'ai:repair-linked-results' => 'app\common\command\AiRepairLinkedResults',
        // 仅恢复因错误本地超时被退款的上游任务
        'ai:recover-timeout-tasks' => 'app\common\command\AiRecoverTimeoutTasks',
        // 租户合约到期扫描
        'tenant:expire_contracts' => 'app\common\command\ExpireTenantContracts',
        // 短剧任务提示词修复
        'short-drama:repair-prompts' => 'app\common\command\RepairShortDramaPrompts',
    ],
];
