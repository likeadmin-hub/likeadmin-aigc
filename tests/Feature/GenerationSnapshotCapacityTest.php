<?php
namespace Tests\Feature;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\database\{SqlMigrationExecutor, RuntimeGenerationSnapshotCapacity};
use app\common\service\power\MarketGenerationRuntime;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** No supplier calls. DDL uses uniquely owned tables; billing fixtures roll back. */
class GenerationSnapshotCapacityTest extends TestCase
{
    private function sql(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/upgrade/20261103_generation_snapshot_capacity.sql');
    }

    public function testInstallAndUpgradePathsRetainTheSameCapacity(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertSame($this->sql(), file_get_contents($root.'/public/upgrade/20261103_generation_snapshot_capacity.sql'));
        $targets = array_map([RuntimeGenerationSnapshotCapacity::class, 'target'], SqlMigrationExecutor::split($this->sql()));
        self::assertCount(13, $targets);
        $install = file_get_contents($root.'/public/install/db/like.sql');
        foreach ($targets as $target) {
            self::assertNotNull($target);
            [$suffix, $column] = $target;
            $table = 'la_'.$suffix;
            self::assertMatchesRegularExpression('/CREATE TABLE[^\n]*`'.$table.'`.*?`'.$column.'`\s+mediumtext\b/is', $install);
            if ($table === 'la_ai_consumption_log') continue;
            $app = preg_replace('/_(?:script_|generation_)?task$/', '', substr($table, 3));
            $folder = $root.'/app/apps/'.$app.'/migrations/';
            $appTargets = array_map([RuntimeGenerationSnapshotCapacity::class, 'target'], SqlMigrationExecutor::split(file_get_contents($folder.'upgrade_20261103_generation_snapshot_capacity_'.$app.'.sql')));
            self::assertContains([$suffix, $column], $appTargets);
            // Signed historical install bytes stay intact. A fresh app install
            // reaches the same capacity through its new bounded upgrade intent.
            self::assertMatchesRegularExpression('/CREATE TABLE[^\n]*`'.$table.'`.*?`'.$column.'`\s+(?:text|mediumtext|longtext)\b/is', file_get_contents($folder.'install.sql'));
        }
    }

    private function localDatabase(): void
    {
        if (getenv('AIGC_VIDEO_PREPARATION_DB_TESTS') !== '1') self::markTestSkipped('Explicit local database fixtures required');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
    }

    /** @dataProvider sqlModes */
    public function testMigrationIsRepeatableAndPreservesColumnSemantics(bool $noBackslash): void
    {
        $this->localDatabase();
        $prefix = 'snapshot_'.bin2hex(random_bytes(6)).'_';
        $tables = [$prefix.'aigc_video_task', $prefix.'ai_consumption_log', $prefix.'aigc_short_drama_generation_task'];
        $mode = (string)Db::query('SELECT @@SESSION.sql_mode AS mode')[0]['mode'];
        try {
            // Create comment under the original session semantics, then verify both parsing modes.
            Db::execute('CREATE TABLE `'.$tables[0]."` (`id` INT PRIMARY KEY, `pricing_snapshot` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT '模型快照') ENGINE=InnoDB");
            Db::execute('CREATE TABLE `'.$tables[1]."` (`id` INT PRIMARY KEY, `price_snapshot` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Frozen price') ENGINE=InnoDB");
            Db::execute('CREATE TABLE `'.$tables[2].'` (`id` INT PRIMARY KEY, `pricing_snapshot` LONGTEXT NULL) ENGINE=InnoDB');
            $modes = array_diff(explode(',', $mode), ['NO_BACKSLASH_ESCAPES']);
            if ($noBackslash) $modes[] = 'NO_BACKSLASH_ESCAPES';
            Db::execute('SET SESSION sql_mode = ?', [implode(',', $modes)]);
            $columns = static fn($table) => Db::query('SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
            $before = array_map($columns, $tables);
            Db::table($tables[0])->insert(['id'=>1, 'pricing_snapshot'=>json_encode(['original'=>'原始快照 \\ apostrophe \''])]);
            SqlMigrationExecutor::execute($this->sql(), $prefix);
            SqlMigrationExecutor::execute($this->sql(), $prefix);
            foreach ($tables as $i=>$table) {
                $after = $columns($table);
                self::assertSame($i === 2 ? 'longtext' : 'mediumtext', $after[1]['DATA_TYPE']);
                $after[1]['DATA_TYPE'] = $before[$i][1]['DATA_TYPE'];
                self::assertSame($before[$i], $after);
            }
            self::assertSame(['original'=>'原始快照 \\ apostrophe \''], json_decode(Db::table($tables[0])->where('id', 1)->value('pricing_snapshot'), true));
            $large = json_encode(['contract'=>str_repeat('完整模型参数', 10000)]);
            self::assertGreaterThan(65535, strlen($large));
            Db::table($tables[0])->insert(['id'=>2, 'pricing_snapshot'=>$large]);
            self::assertSame($large, Db::table($tables[0])->where('id', 2)->value('pricing_snapshot'));
        } finally {
            Db::execute('SET SESSION sql_mode = ?', [$mode]);
            foreach ($tables as $table) Db::execute('DROP TABLE IF EXISTS `'.$table.'`');
        }
    }

    public static function sqlModes(): array { return [[false], [true]]; }

    public function testLegacyTablesMissingSnapshotColumnsAreRepairedWithoutChangingRows(): void
    {
        $this->localDatabase();
        $prefix = 'snapshot_'.bin2hex(random_bytes(6)).'_';
        $targets = array_map([RuntimeGenerationSnapshotCapacity::class, 'target'], SqlMigrationExecutor::split($this->sql()));
        $tables = [];
        try {
            foreach ($targets as [$suffix, $column]) {
                $table = $prefix.$suffix;
                Db::execute('CREATE TABLE `'.$table.'` (`id` INT PRIMARY KEY, `legacy_payload` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
                $tables[] = $table;
                Db::table($table)->insert(['id'=>1, 'legacy_payload'=>'existing customer task']);
            }
            SqlMigrationExecutor::execute($this->sql(), $prefix);
            SqlMigrationExecutor::execute($this->sql(), $prefix);
            foreach ($targets as [$suffix, $column]) {
                $table = $prefix.$suffix;
                $metadata = Db::query('SELECT DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]);
                self::assertSame([['DATA_TYPE'=>'mediumtext', 'IS_NULLABLE'=>'YES', 'COLUMN_DEFAULT'=>null, 'COLLATION_NAME'=>'utf8mb4_bin']], $metadata);
                self::assertSame([['id'=>1, 'legacy_payload'=>'existing customer task', $column=>null]], Db::table($table)->select()->toArray());
                $large = json_encode(['contract'=>str_repeat('legacy capacity repair', 5000)]);
                // Use SQL across DDL: ThinkORM cached the legacy field list at insert time.
                Db::execute('UPDATE `'.$table.'` SET `'.$column.'`=? WHERE id=1', [$large]);
                self::assertSame($large, Db::table($table)->where('id', 1)->value($column));
            }
        } finally {
            foreach ($tables as $table) Db::execute('DROP TABLE IF EXISTS `'.$table.'`');
        }
    }

    public function testMissingOptionalTablesStayAbsentAndUnsupportedEnginesStayUntouched(): void
    {
        $this->localDatabase();
        $prefix = 'snapshot_'.bin2hex(random_bytes(6)).'_';
        self::assertSame(['status'=>'optional_table_absent'], RuntimeGenerationSnapshotCapacity::apply('image_human_task', 'pricing_snapshot', $prefix));
        $table = $prefix.'image_human_task';
        Db::execute('CREATE TABLE `'.$table.'` (`id` INT PRIMARY KEY) ENGINE=MyISAM');
        try {
            try {
                RuntimeGenerationSnapshotCapacity::apply('image_human_task', 'pricing_snapshot', $prefix);
                self::fail('Unsupported engine accepted');
            } catch (\RuntimeException $expected) {
                self::assertStringContainsString('InnoDB', $expected->getMessage());
            }
            self::assertSame(['id'], array_column(Db::query('SHOW COLUMNS FROM `'.$table.'`'), 'Field'));
        } finally {
            Db::execute('DROP TABLE `'.$table.'`');
        }
    }

    public function testLargeContractCanCreateBusinessTaskReserveAndRefundWithoutTruncation(): void
    {
        $this->localDatabase();
        $key = 'snapshot-capacity-'.bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            $user = (int)Db::name('user')->where('tenant_id', 1)->where('user_money', '>', 100)->order('user_money', 'desc')->value('id');
            self::assertGreaterThan(0, $user);
            Db::name('tenant')->where('id', 1)->lock(true)->find();
            Db::name('user')->where('id', $user)->lock(true)->find();
            $balances = static fn() => [Db::name('tenant')->where('id', 1)->value('point_balance'), Db::name('user')->where('id', $user)->value('user_money')];
            $before = $balances();
            $metadata = ['protocol'=>'', 'params_schema'=>['properties'=>[
                'prompt'=>['type'=>'string', 'description'=>str_repeat('完整模型规则', 5000)],
                'duration'=>['type'=>'integer', 'enum'=>[4]],
                'resolution'=>['type'=>'string', 'enum'=>['720P']],
                'aspect_ratio'=>['type'=>'string', 'enum'=>['16:9']],
            ], 'required'=>['prompt','duration']], 'supported_durations'=>[4],
                'material_capabilities'=>['modes'=>[['code'=>'text_to_video','method'=>'text_to_video','fields'=>[]]]]];
            $product = PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline large contract', 'resource_type'=>'model',
                'model_type'=>'video','source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>$key,
                'upstream_channel_code'=>'offline','source_payload'=>['market_metadata'=>$metadata],'status'=>1]);
            $sku = PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$key,'title'=>'Offline snapshot capacity',
                'locked_params'=>['resolution'=>'720P','duration'=>4],'usage_unit'=>'output_second','usage_unit_size'=>1,
                'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,
                'source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'output','rounding'=>'ceil']]]);
            $request = ['model_id'=>'market_video_model:'.$product['id'],'market_sku_id'=>$sku['id'],
                'generation_method'=>'text_to_video','prompt'=>'Offline snapshot round trip', 'duration'=>4,'quality'=>'720P','ratio'=>'16:9'];
            $runtime = MarketGenerationRuntime::selection('video', $request);
            $quote = $runtime->quote(1);
            self::assertGreaterThan(65535, strlen(json_encode($quote['market_snapshot'])));
            $task = AigcVideoTask::create(['tenant_id'=>1,'user_id'=>$user,'prompt'=>$key,'pricing_snapshot'=>$quote['market_snapshot']]);
            self::assertSame(json_decode(json_encode($quote['market_snapshot']), true), AigcVideoTask::find($task['id'])['pricing_snapshot']);
            $reserved = $runtime->reserve(1,$user,'aigc_video','video_generate','aigc_video_task',$key,$request);
            self::assertSame($reserved['consumption_id'], $runtime->reserve(1,$user,'aigc_video','video_generate','aigc_video_task',$key,$request)['consumption_id']);
            $receipt = AiConsumptionLog::find($reserved['consumption_id']);
            self::assertSame($metadata['params_schema']['properties']['prompt']['description'], $receipt['price_snapshot']['params_schema']['properties']['prompt']['description']);
            self::assertSame('', $receipt['upstream_task_id']);
            self::assertSame('reserved', $receipt['billing_status']);
            MarketVideoRuntimeService::fail((int)$receipt['id'], 'Offline capacity validation only');
            MarketVideoRuntimeService::fail((int)$receipt['id'], 'Duplicate failure');
            self::assertSame('refunded', AiConsumptionLog::find($receipt['id'])['billing_status']);
            self::assertSame($before, $balances());
        } finally { Db::rollback(); }
        self::assertSame(0, (int)Db::name('power_market_product')->where('product_code', $key)->count());
    }
}
