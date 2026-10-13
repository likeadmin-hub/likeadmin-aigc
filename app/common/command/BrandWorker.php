<?php
declare(strict_types=1);
namespace app\common\command;
use app\common\service\brand\BrandProvisionService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;
use think\facade\Log;

/** Separate OEM executor; no AI queues, billing, ACKs or task locks are touched. */
class BrandWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('brand:worker')->setDescription('独立处理贴牌租户开通与续费任务')
            ->addOption('once',null,Option::VALUE_NONE,'处理待执行任务后退出')
            ->addOption('limit',null,Option::VALUE_OPTIONAL,'单次模式最多处理任务数',20)
            ->addOption('sleep',null,Option::VALUE_OPTIONAL,'空闲等待秒数',3);
    }

    protected function execute(Input $input,Output $output): int
    {
        $running=true; $processed=0; $failed=false; $once=(bool)$input->getOption('once');
        $limit=max(1,min(100,(int)$input->getOption('limit'))); $sleep=max(1,min(30,(int)$input->getOption('sleep')));
        if (function_exists('pcntl_signal')) { pcntl_async_signals(true); $stop=static function()use(&$running): void { $running=false; }; pcntl_signal(SIGTERM,$stop); pcntl_signal(SIGINT,$stop); }
        $database=(string)(Db::query('SELECT DATABASE() AS db')[0]['db']??'');
        $lock='oem:'.substr(hash('sha256',$database.'|'.Db::name('tenant_brand_job')->getTable()),0,56);
        if ((int)(Db::query('SELECT GET_LOCK(?,0) AS acquired',[$lock])[0]['acquired']??0)!==1) { $output->writeln('已有本项目贴牌执行器运行，本进程退出。'); return 1; }
        $output->writeln('OEM worker started: concurrency=1');
        try {
            while ($running && (!$once || $processed<$limit)) {
                if (function_exists('aigc_runtime_generation_changed') && \aigc_runtime_generation_changed()) { $output->writeln('Runtime generation changed; OEM worker retired.'); break; }
                $job=BrandProvisionService::claim('oem:'.(gethostname()?:'host').':'.getmypid());
                if (!$job) { if ($once) break; sleep($sleep); continue; }
                BrandProvisionService::execute($job); $processed++;
                $state=Db::name('tenant_brand_job')->where('id',$job['id'])->value('status');
                if ($state==='failed') $failed=true;
                $output->writeln('OEM task '.(int)$job['id'].': '.$state);
            }
        } catch (\Throwable $e) {
            // A lost DB connection must retire this process and its advisory lock, not replay SQL.
            Log::error('OEM worker retired after error: '.$e->getMessage());
            $output->writeln('贴牌执行器遇到异常，任务记录已保留，请由进程守护恢复。'); return 1;
        } finally {
            try { Db::query('SELECT RELEASE_LOCK(?)',[$lock]); } catch (\Throwable) {}
            $output->writeln('OEM worker stopped: processed='.$processed);
        }
        return $failed?1:0;
    }
}
