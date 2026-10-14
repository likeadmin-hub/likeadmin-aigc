<?php
declare(strict_types=1);
namespace app\common\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\common\service\brand\BrandMaintenanceService;
class BrandMaintain extends Command {
    protected function configure(): void { $this->setName('brand:maintain')->setDescription('贴牌付款退款核对、超时关闭和每日人工跟进提醒'); }
    protected function execute(Input $input,Output $output): int {
        if(function_exists('aigc_runtime_generation_changed') && \aigc_runtime_generation_changed()) return 0;
        $database=(string)(Db::query('SELECT DATABASE() AS db')[0]['db']??''); $lock='oem-maintain:'.substr(hash('sha256',$database),0,40);
        if((int)(Db::query('SELECT GET_LOCK(?,0) AS acquired',[$lock])[0]['acquired']??0)!==1) return 0;
        try { $output->writeln(json_encode(BrandMaintenanceService::run(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
        finally { Db::query('SELECT RELEASE_LOCK(?)',[$lock]); }
        return 0;
    }
}
