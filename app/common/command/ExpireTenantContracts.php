<?php

namespace app\common\command;

use app\common\service\tenant\TenantContractService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class ExpireTenantContracts extends Command
{
    protected function configure(): void
    {
        $this->setName('tenant:expire_contracts')
            ->setDescription('扫描并禁用已到期租户');
    }

    protected function execute(Input $input, Output $output): int
    {
        $count = TenantContractService::expireSignedTenants();
        $output->writeln('expired_tenants: ' . $count);
        return self::SUCCESS;
    }
}
