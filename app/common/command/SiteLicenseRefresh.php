<?php
namespace app\common\command;
use app\common\service\license\SiteLicenseAccessService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
class SiteLicenseRefresh extends Command
{
    protected function configure() { $this->setName('site-license:refresh')->setDescription('刷新平台站点授权权限'); }
    protected function execute(Input $input, Output $output)
    {
        try {
            $site=(new SiteLicenseAccessService())->refresh();
            $output->writeln('站点授权同步状态: '.$site['access_status']);
        } catch (\Throwable $e) {
            // Expected failures must not disable the existing cron dispatcher.
            Log::warning('Site license refresh failed: '.$e->getMessage());
            $output->writeln('授权同步暂不可用');
        }
        return 0;
    }
}
