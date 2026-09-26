<?php
namespace app\common\command;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class ShortDramaPlanningWorker extends Command
{
    protected function configure()
    {
        $this->setName('short-drama:planning-worker')->addOption('once', null, Option::VALUE_NONE, 'Process one planning task')
            ->addOption('task', null, Option::VALUE_OPTIONAL, 'Restrict to a task ID')
            ->setDescription('Run story, outline and v3 standalone script tasks independently of the browser');
    }
    protected function execute(Input $input, Output $output)
    {
        @set_time_limit(0);
        $stopping = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$stopping) { $stopping = true; };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        do {
            try {
            $query = Db::name('aigc_short_drama_script_task')->where('delete_time', 0)->whereIn('status', ['pending', 'queued', 'running'])
                ->whereRaw("JSON_VALID(request_json) AND COALESCE(JSON_EXTRACT(request_json, '$.episode_id'), 0) = 0")
                ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(request_json, '$.source')), '') <> 'market_file_qa_parse'")
                ->where(function ($query) {
                    $query->whereLike('request_json', '%story_outline_v2%')->whereOr(function ($v3) {
                        $v3->whereRaw("JSON_EXTRACT(request_json, '$._generation_version') >= 3")
                            ->whereRaw("COALESCE(JSON_EXTRACT(request_json, '$.multi_episode'), false) = false");
                    });
                });
            if ($input->getOption('task')) $query->where('task_id', $input->getOption('task'));
            $rows = $query->order('id')->limit(20)->select()->toArray();
            $processed = false;
            foreach ($rows as $row) {
                $processed = AigcShortDramaService::runStoryPlanningTask((int)$row['tenant_id'], (int)$row['user_id'], $row['task_id']);
                if ($processed) break;
            }
            if ($input->getOption('once')) { $output->writeln('processed=' . (int)$processed); return 0; }
            if (!$processed) sleep(3);
            } catch (\Throwable $error) {
                // Never turn a setup/database failure into a successful task or a tight retry loop.
                $output->writeln('Planning worker error: ' . $error->getMessage());
                if ($input->getOption('once')) return 1;
                sleep(3);
            }
        } while (!$stopping);
        return 0;
    }
}
