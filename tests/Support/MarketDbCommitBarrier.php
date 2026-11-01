<?php

/** Production SQL executes unchanged. A private worker pauses at one observed transaction boundary. */
final class MarketDbCommitBarrier extends think\db\connector\Mysql
{
    private static ?MarketIsolatedMysqlFixture $instance = null;
    private static string $prefix = '';
    private static string $dir = '';

    public function getBuilderClass(): string { return think\db\builder\Mysql::class; }

    public static function install(MarketIsolatedMysqlFixture $instance, string $prefix, string $dir): void
    {
        MarketIsolatedMysqlFixture::check(preg_match('/^b56_[a-f0-9]{12}_$/D',$prefix)===1
            && is_file($dir.'/owner') && trim(file_get_contents($dir.'/owner'))===$prefix, 'Not an owned DB boundary fixture');
        self::$instance=$instance; self::$prefix=$prefix; self::$dir=$dir;
        $cfg=config('database'); $connection=$cfg['connections'][$cfg['default']];
        $connection['type']=self::class;
        $name='c03_boundary_'.substr($instance->owner,0,12);
        $cfg['default']=$name; $cfg['connections'][$name]=$connection;
        app()->config->set($cfg,'database'); $config=new think\Config(); $config->set($cfg,'database'); think\facade\Db::setConfig($config);
        think\facade\Db::event('after_insert',static function($query): void {
            $boundary=self::boundary();
            if(($boundary['phase']??'')==='before-receipt-commit' && str_ends_with($query->getTable(),'tenant_file'))
                self::pause(['boundary'=>'uncommitted_tenant_file_insert']);
        });
    }

    private static function boundary(): array
    {
        $path=self::$dir.'/db-boundary.json';
        if(self::$instance===null || !is_file($path) || is_file(self::$dir.'/db-paused.json')) return [];
        $value=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        MarketIsolatedMysqlFixture::check(($value['owner']??'')===self::$instance->owner, 'DB boundary owner differs');
        return $value;
    }

    private static function pause(array $facts): void
    {
        MarketIsolatedMysqlFixture::atomic(self::$dir.'/db-paused.json',json_encode($facts+['pid'=>getmypid()],JSON_THROW_ON_ERROR));
        $deadline=microtime(true)+45;
        while(!is_file(self::$dir.'/db-release')){
            if(microtime(true)>$deadline)throw new RuntimeException('Owned DB boundary controller did not respond');
            usleep(20000);
        }
    }

    public function commit(): void
    {
        parent::commit();
        if($this->transTimes!==0)return;
        $boundary=self::boundary();
        if(!in_array($boundary['phase']??'',['after-receipt-commit','after-asset-commit'],true))return;
        $id=$boundary['asset_id']??null;
        MarketIsolatedMysqlFixture::check(is_int($id)&&$id>0,'Invalid private asset boundary');
        $pdo=self::$instance->pdo();
        $asset=$pdo->query('SELECT transfer_status,storage_meta FROM `'.self::$prefix.'ai_task_result_asset` WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
        if(!$asset)return;
        $meta=json_decode($asset['storage_meta'],true,32,JSON_THROW_ON_ERROR);
        $files=(int)$pdo->query('SELECT COUNT(*) FROM `'.self::$prefix.'tenant_file`')->fetchColumn();
        if($boundary['phase']==='after-receipt-commit' && $asset['transfer_status']!=='stored' && !empty($meta['transfer_checkpoint']['receipt']['file_id']) && $files===1)
            self::pause(['boundary'=>'committed_file_receipt_before_asset_terminal','file_records'=>$files]);
        if($boundary['phase']==='after-asset-commit' && $asset['transfer_status']==='stored' && $files===1)
            self::pause(['boundary'=>'committed_asset_before_business_projection','file_records'=>$files]);
    }
}
