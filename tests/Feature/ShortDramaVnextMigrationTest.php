<?php
namespace Tests\Feature;

use app\common\service\database\SqlMigrationExecutor as Sql;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextMigrationTest extends TestCase
{
    public function testFreshInstallAndUpgradeSchemasMatchAndAreRepeatable(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local migration fixtures');
        (new \think\App())->initialize();
        $paths = ['app/apps/aigc_short_drama/migrations/install.sql', 'public/install/db/like.sql', 'upgrade/20261009_short_drama_vnext_execution.sql'];
        $suffixes = array_map(static fn($kind) => 'aigc_short_drama_vnext_' . $kind, ['job', 'stage', 'revision', 'outbox']);
        $prefix = 'vn' . bin2hex(random_bytes(3)) . '_'; $created = []; $schemas = [];
        try {
            foreach ($paths as $index => $path) {
                $currentPrefix = $prefix . $index . '_'; $statements = [];
                foreach (Sql::split(file_get_contents(root_path() . $path)) as $statement) {
                    if (preg_match('/^(?:CREATE TABLE IF NOT EXISTS|ALTER TABLE) `la_aigc_short_drama_vnext_/', $statement)) $statements[] = $statement . ';';
                }
                self::assertCount(6, $statements, $path);
                foreach ($suffixes as $suffix) $created[] = $currentPrefix . $suffix;
                Sql::execute(implode("\n", $statements), $currentPrefix);
                Sql::execute(implode("\n", $statements), $currentPrefix);
                foreach ($suffixes as $suffix) {
                    $schema = Db::query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$currentPrefix . $suffix]);
                    if ($index === 0) $schemas[$suffix] = $schema;
                    else self::assertSame($schemas[$suffix], $schema, $path . ':' . $suffix);
                }
            }
        } finally {
            foreach ($created as $table) Db::execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
