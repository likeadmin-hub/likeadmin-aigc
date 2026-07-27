<?php

namespace app\common\command;

use app\common\service\brand\TenantBrandService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class ExpireTenantBrandOrders extends Command
{
    protected function configure()
    {
        $this->setName('tenant:expire_brand_orders')->setDescription('释放超时未支付的贴牌订单预占额度');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('expired=' . TenantBrandService::expirePendingOrders());
        return self::SUCCESS;
    }
}
