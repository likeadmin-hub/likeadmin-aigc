<?php
declare(strict_types=1);
namespace app\common\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayMaintenanceService;
class VirtualPayMaintain extends Command
{
    protected function configure(): void {$this->setName('virtualpay:maintain')->setDescription('套餐支付、退款与发货核对');}
    protected function execute(Input $input,Output $output): int
    {
        if(function_exists('aigc_runtime_generation_changed')&&\aigc_runtime_generation_changed())return 0;
        $db=(string)(Db::query('SELECT DATABASE() AS db')[0]['db']??'');$key='vp-maintain:'.substr(hash('sha256',$db),0,40);
        if((int)(Db::query('SELECT GET_LOCK(?,0) AS n',[$key])[0]['n']??0)!==1)return 0;
        try{$output->writeln(json_encode((new VirtualPayMaintenanceService())->run(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}finally{Db::query('SELECT RELEASE_LOCK(?)',[$key]);}return 0;
    }
}
