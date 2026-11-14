<?php
namespace Tests\Feature;

use app\common\service\database\{RuntimeGenerationSnapshotCapacity, RuntimeMigrationPlan};
use PHPUnit\Framework\TestCase;

/** Pure updater-admission and DDL guards; no database or supplier calls. */
final class RuntimeGenerationSnapshotCapacityTest extends TestCase
{
    public function testSignedHistoricalMigrationsKeepTheirOriginalBytes(): void
    {
        $root = dirname(__DIR__, 2);
        $baseline = require $root.'/config/runtime_migration_baseline.php';
        foreach ($baseline as $relative => $hash) {
            self::assertSame($hash, hash_file('sha256', $root.'/'.$relative), $relative);
        }
    }

    public function testNewSnapshotMigrationsHaveAnUnambiguousPackageOwner(): void
    {
        $files=glob(dirname(__DIR__,2).'/app/apps/*/migrations/upgrade_20261103_generation_snapshot_capacity*.sql');
        self::assertCount(11,$files);
        self::assertCount(11,array_unique(array_map('basename',$files)), 'System staging identifies migration ownership by package filename');
    }

    public function testPublishedCopywritingInstallIsImmutableAndUpgradeAddsDeletion(): void
    {
        $root=dirname(__DIR__,2).'/app/apps/aigc_copywriting/migrations/';
        self::assertSame('de7f8058049c514bb5bf66db50cae87d47bd065988baed5aa5f14224c6d1f664',hash_file('sha256',$root.'install.sql'));
        self::assertStringContainsString('ADD COLUMN `delete_time`',file_get_contents($root.'upgrade_20261103_history_delete.sql'));
    }

    public function testEveryNewAppMigrationPassesTheExistingRuntimePlanner(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root.'/app/apps/*/migrations/upgrade_20261103_*.sql');
        self::assertCount(12, $files);
        foreach ($files as $file) {
            $plan = RuntimeMigrationPlan::build(file_get_contents($file));
            self::assertNotSame('', $plan['prepare'], $file);
            self::assertSame('', $plan['complete'], $file);
        }
        foreach (['20261101_generation_submission.sql', '20261103_copywriting_history_delete.sql', '20261103_generation_snapshot_capacity.sql'] as $file) {
            $sql = file_get_contents($root.'/upgrade/'.$file);
            self::assertSame($sql, file_get_contents($root.'/public/upgrade/'.$file), $file);
            self::assertNotSame('', RuntimeMigrationPlan::build($sql)['prepare']);
        }
    }

    public function testOnlyReviewedSnapshotColumnsAreAccepted(): void
    {
        self::assertSame(['aigc_video_task', 'pricing_snapshot'], RuntimeGenerationSnapshotCapacity::target('ALTER TABLE `la_aigc_video_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT'));
        self::assertSame(['ai_consumption_log', 'price_snapshot'], RuntimeGenerationSnapshotCapacity::target('ALTER TABLE `la_ai_consumption_log` MODIFY COLUMN `price_snapshot` MEDIUMTEXT'));
        foreach (['ALTER TABLE la_user MODIFY COLUMN pricing_snapshot MEDIUMTEXT',
                  'ALTER TABLE la_aigc_video_task MODIFY COLUMN prompt MEDIUMTEXT',
                  'ALTER TABLE la_ai_consumption_log MODIFY COLUMN pricing_snapshot MEDIUMTEXT',
                  'ALTER TABLE la_aigc_video_task MODIFY COLUMN pricing_snapshot MEDIUMTEXT, DROP COLUMN id'] as $sql) {
            self::assertNull(RuntimeGenerationSnapshotCapacity::target($sql));
            try { RuntimeMigrationPlan::build($sql); self::fail('Unreviewed SQL admitted'); }
            catch (\RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
        }
    }

    private function column(array $changes = []): array
    {
        return array_replace(['column_name'=>'pricing_snapshot', 'data_type'=>'text', 'column_type'=>'text',
            'is_nullable'=>'YES', 'column_default'=>"saved\\value'", 'character_set_name'=>'utf8mb4',
            'collation_name'=>'utf8mb4_bin', 'column_comment'=>"冻结\\快照'", 'extra'=>''], $changes);
    }

    public function testWideningPreservesNullableDefaultCharsetCollationAndComment(): void
    {
        foreach ([false, true] as $noBackslash) {
            $sql = RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>'InnoDB'], $this->column(), 'owned_', $noBackslash);
            self::assertStringStartsWith('ALTER TABLE `owned_aigc_video_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT CHARACTER SET `utf8mb4` COLLATE `utf8mb4_bin` NULL', $sql);
            self::assertStringContainsString($noBackslash ? "DEFAULT 'saved\\value'''" : "DEFAULT 'saved\\\\value'''", $sql);
            self::assertStringContainsString($noBackslash ? "COMMENT '冻结\\快照'''" : "COMMENT '冻结\\\\快照'''", $sql);
        }
        $sql = RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>'InnoDB'], $this->column(['is_nullable'=>'NO', 'column_default'=>null]), 'owned_');
        self::assertStringContainsString(' NOT NULL COMMENT ', $sql);
        self::assertStringNotContainsString(' DEFAULT ', $sql);
    }

    public function testLargerExistingTypesAreNeverNarrowed(): void
    {
        foreach (['mediumtext', 'longtext'] as $type) {
            self::assertNull(RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>'InnoDB'], $this->column(['data_type'=>$type, 'column_type'=>$type]), 'owned_'));
        }
    }

    public function testColumnNameCaseIsAcceptedWithoutRenamingTheOriginal(): void
    {
        $column = $this->column(['column_name'=>'PRICING_SNAPSHOT']);
        $sql = RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>'InnoDB'], $column, 'owned_');
        self::assertStringContainsString('MODIFY COLUMN `PRICING_SNAPSHOT` MEDIUMTEXT', $sql);
        self::assertNull(RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>'InnoDB'], array_replace($column, ['data_type'=>'longtext','column_type'=>'longtext']), 'owned_'));
        $this->expectException(\RuntimeException::class);
        RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>'InnoDB'], $this->column(['column_name'=>'other_snapshot']), 'owned_');
    }

    public function testUppercaseDriverKeysKeepMetadataGuardsAndRepeatability(): void
    {
        $connection = new class($this->column()) {
            public array $executed = [];
            public array $columns;
            public function __construct(array $column) { $this->columns = [$column]; }
            public function query(string $sql, array $bindings = []): array {
                if (str_contains($sql, 'information_schema.TABLES')) $rows = [['engine'=>'InnoDB']];
                elseif (str_contains($sql, 'information_schema.COLUMNS')) $rows = $this->columns;
                else $rows = [['sql_mode'=>'NO_BACKSLASH_ESCAPES']];
                return array_map(static fn(array $row): array => array_change_key_case($row, CASE_UPPER), $rows);
            }
            public function execute(string $sql): void {
                $this->executed[] = $sql;
                $this->columns[0]['data_type'] = $this->columns[0]['column_type'] = 'mediumtext';
            }
        };
        self::assertSame(['status'=>'widened'], RuntimeGenerationSnapshotCapacity::apply('aigc_video_task', 'pricing_snapshot', 'owned_', $connection));
        self::assertSame(['status'=>'already_sufficient'], RuntimeGenerationSnapshotCapacity::apply('aigc_video_task', 'pricing_snapshot', 'owned_', $connection));
        self::assertCount(1, $connection->executed);
        self::assertStringContainsString("COMMENT '冻结\\快照'''", $connection->executed[0]);
        $connection->columns[] = $connection->columns[0];
        try {
            RuntimeGenerationSnapshotCapacity::apply('aigc_video_task', 'pricing_snapshot', 'owned_', $connection);
            self::fail('Ambiguous metadata accepted');
        } catch (\RuntimeException $expected) {
            self::assertStringContainsString('无法确定原字段', $expected->getMessage());
        }
        self::assertCount(1, $connection->executed);
    }

    public function testUnknownSchemaOrPrefixCannotProduceDdl(): void
    {
        foreach ([['engine'=>'MyISAM', 'column'=>[]], ['engine'=>'InnoDB', 'column'=>['data_type'=>'varchar', 'column_type'=>'varchar(20)']],
                  ['engine'=>'InnoDB', 'column'=>['extra'=>'DEFAULT_GENERATED']], ['engine'=>'InnoDB', 'column'=>['is_nullable'=>'UNKNOWN']],
                  ['engine'=>'InnoDB', 'column'=>['collation_name'=>'utf8mb4_bin` DROP TABLE user']]] as $case) {
            try {
                RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>$case['engine']], $this->column($case['column']), 'owned_');
                self::fail('Unknown schema accepted');
            } catch (\RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
        }
        $this->expectException(\RuntimeException::class);
        RuntimeGenerationSnapshotCapacity::columnSql('aigc_video_task', 'pricing_snapshot', ['engine'=>'InnoDB'], $this->column(), 'bad-prefix');
    }
}
