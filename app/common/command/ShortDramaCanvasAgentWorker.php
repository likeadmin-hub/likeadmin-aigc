<?php
declare(strict_types=1);
namespace app\common\command;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationQueue;
use app\common\service\app\aigc_short_drama\canvas_agent\FeatureGate;
use app\common\service\app\aigc_short_drama\canvas_agent\MarketTextConversationProvider;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

/** Durable short-drama Agent dispatcher. It only scans tenants that explicitly
 * enabled paid model execution in tenant administration. */
final class ShortDramaCanvasAgentWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('short-drama:canvas-agent-worker')
            ->setDescription('Dispatch enabled short-drama canvas Agent conversations')
            ->addOption('once',null,Option::VALUE_NONE,'Scan once and exit')
            ->addOption('tenant',null,Option::VALUE_OPTIONAL,'Restrict to one tenant')
            ->addOption('sleep',null,Option::VALUE_OPTIONAL,'Idle seconds',2);
    }

    protected function execute(Input $input,Output $output): int
    {
        @set_time_limit(0);
        $running=true;
        $stop=static function () use (&$running): void {$running=false;};
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true); pcntl_signal(SIGTERM,$stop); pcntl_signal(SIGINT,$stop);
        }
        $tenantOption=max(0,(int)$input->getOption('tenant'));
        $pause=max(1,min(30,(int)$input->getOption('sleep')));
        $provider=new MarketTextConversationProvider();
        do {
            $processed=0;
            $tenants=$tenantOption>0 ? [$tenantOption] : array_map('intval',Db::name('aigc_short_drama_config')->where('status',1)->column('tenant_id'));
            foreach (array_values(array_unique($tenants)) as $tenant) {
                if (!FeatureGate::executionEnabled($tenant)) continue;
                $result=ConversationQueue::tick($tenant,$provider,0,20);
                $processed+=(int)($result['scanned']??0);
            }
            if ($input->getOption('once')) { $output->writeln('scanned='.$processed); return 0; }
            if ($processed===0 && $running) sleep($pause);
        } while ($running);
        return 0;
    }
}
