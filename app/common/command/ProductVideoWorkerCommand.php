<?php
namespace app\common\command;
use app\common\service\app\aigc_product_video\ProductVideoWorker;
use app\common\service\app\aigc_product_video\ProductVideoPolicy;
use app\common\service\app\AppRegistryService;
use think\console\{Command,Input,Output};
use think\console\input\Option;
final class ProductVideoWorkerCommand extends Command
{
    protected function configure(){ $this->setName('product-video:worker')->addOption('replication',null,Option::VALUE_NONE,'Process video replication jobs')->addOption('once',null,Option::VALUE_NONE,'Process one stage')->addOption('job',null,Option::VALUE_OPTIONAL,'Restrict to job',0)->setDescription('Process independent product video jobs'); }
    protected function execute(Input $input,Output $output)
    {
        @set_time_limit(0);$running=true;
        if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);foreach([SIGTERM,SIGINT] as $signal)pcntl_signal($signal,static function()use(&$running){$running=false;});}
        $replication = (bool)$input->getOption('replication');
        $once = (bool)$input->getOption('once');
        $id = (int)$input->getOption('job');
        do{
            if(function_exists('aigc_runtime_generation_changed')&&aigc_runtime_generation_changed())break;
            try{
                $tick = fn() => $replication && !$once
                    ? $this->runFreshReplicationTick($id)
                    : ProductVideoWorker::tick($id);
                $count = $replication
                    ? (AppRegistryService::isInstalled(ProductVideoPolicy::REPLICATION) ? ProductVideoPolicy::forReplication($tick) : 0)
                    : $tick();
                if (!$replication && !$id && AppRegistryService::isInstalled(ProductVideoPolicy::SALE)) {
                    $count += ProductVideoPolicy::forSale(fn() => ProductVideoWorker::tick());
                }
            }catch(\Throwable $e){$output->writeln('Product video worker: '.get_class($e));return 1;}
            if($once){$output->writeln('processed='.$count);break;}if(!$count && $running)sleep(3);
        }while($running);return 0;
    }

    private function runFreshReplicationTick(int $id): int
    {
        if (!ProductVideoWorker::readyJobs($id)->value('id')) return 0;

        // The daemon only polls. A fresh CLI process leases and executes the job with the current application code.
        $command = [PHP_BINARY];
        if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
        foreach (['disable_functions', 'open_basedir', 'memory_limit'] as $option) {
            array_push($command, '-d', $option.'='.ini_get($option));
        }
        array_push($command, root_path().'think', 'product-video:worker', '--replication', '--once', '--job='.$id);
        $process = proc_open($command, [0=>STDIN, 1=>['pipe','w'], 2=>STDERR], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Cannot start video replication worker');
        $result = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || !preg_match('/^processed=([01])$/m', $result, $match)) {
            throw new \RuntimeException('Video replication worker did not finish its tick');
        }
        return (int)$match[1];
    }
}
