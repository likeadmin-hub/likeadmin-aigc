<?php
declare(strict_types=1);
namespace app\common\command;

use app\common\service\virtualpay\{VirtualPayKeySetup, VirtualPayVault};
use think\console\{Command, Input, Output};
use think\console\input\Option;

final class VirtualPayKey extends Command
{
    protected function configure(): void
    {
        $this->setName('virtualpay:key')->setDescription('检查支付凭据加密配置；--init 仅初始化从未保存凭据的站点')
            ->addOption('init', null, Option::VALUE_NONE, '安全初始化独立随机密钥，不覆盖已有密钥或加密数据');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            if (!$input->getOption('init')) {
                VirtualPayVault::requireKey();
                $output->writeln('支付凭据加密密钥长度检查通过。请确认 Web 与定时任务使用同一站点配置并妥善备份。');
                return 0;
            }
            $result = VirtualPayKeySetup::initialize(root_path().'.env', VirtualPayVault::configuredKey(), [VirtualPayKeySetup::class, 'hasEncryptedRecords']);
            $output->writeln($result['created'] ? '已初始化独立支付加密密钥，原配置已私密备份。请备份当前 .env，刷新租户页面后重新保存支付配置。' : '支付凭据加密密钥已配置，未更改任何密钥。');
            return 0;
        } catch (\Throwable $error) {
            $output->writeln('<error>'.$error->getMessage().'</error>');
            return 1;
        }
    }
}
