<?php

namespace Tests\Feature;

use app\common\service\app\AppDisplayConfigService;
use app\common\service\database\SqlMigrationExecutor;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AppDisplayPcTitlesTest extends TestCase
{
    private const RENAMES = [
        'aigc_image' => ['图片生成', '图像创作'],
        'aigc_video' => ['视频生成', '视频创作'],
        'aigc_music' => ['AI音乐生成', '音乐创作'],
        'aigc_llm' => ['AIGC 对话', 'Agent对话'],
        'aigc_canvas' => ['无限画布', '画布'],
        'aigc_short_drama' => ['AI短剧', '短剧'],
        'aigc_geo' => ['GEO营销优化系统', 'GEO营销'],
        'image_human' => ['全驱数字人', '全驱动数字人'],
        'aigc_digital_human' => ['数字人视频', '对口型数字人'],
    ];

    public function testNewInstallationAndTenantDefaultsFollowPcNames(): void
    {
        $defaults = new \ReflectionMethod(AppDisplayConfigService::class, 'defaultConfig');
        $defaults->setAccessible(true);
        self::assertCount(31, AppDisplayConfigService::PC_APP_TITLES);
        self::assertArrayNotHasKey('aigc_micro_video', AppDisplayConfigService::PC_APP_TITLES);
        foreach (AppDisplayConfigService::PC_APP_TITLES as $code => $title) {
            self::assertContains($code, AppDisplayConfigService::DEFAULT_APP_CODES);
            $row = $defaults->invoke(null, $code);
            self::assertSame($title, $row['title'], $code);
            self::assertSame(1, $row['status']);
        }
        foreach (self::RENAMES as $code => [$old, $title]) {
            self::assertSame($title, $defaults->invoke(null, $code)['title']);
        }
        self::assertSame('custom_app', $defaults->invoke(null, 'custom_app')['title']);
        self::assertSame(100, $defaults->invoke(null, 'aigc_image')['sort']);
        self::assertNotEmpty($defaults->invoke(null, 'aigc_image')['description']);
    }

    public function testUpgradeRenamesOnlyLegacyTitlesAndIsIdempotent(): void
    {
        if (getenv('APP_DISPLAY_TITLES_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Opt in to local MySQL temporary-table verification');
        }
        (new \think\App())->initialize();
        $db = Db::connect();
        self::assertContains($db->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        $prefix = 'pctitles_test_' . bin2hex(random_bytes(5)) . '_';
        $table = $prefix . 'tenant_app_config';
        $source = $db->getConfig('prefix') . 'tenant_app_config';
        $sql = file_get_contents(dirname(__DIR__, 2) . '/upgrade/20261109_pc_app_display_titles.sql');
        // Connection-local table: no persistent application rows are written.
        $db->execute("CREATE TEMPORARY TABLE `{$table}` LIKE `{$source}`");
        try {
            $expected = [];
            foreach (self::RENAMES as $code => [$old, $title]) {
                foreach ([0 => $old, 1 => $old, 2 => '运营自定义名称', 3 => $title] as $tenant => $value) {
                    $db->execute("INSERT INTO `{$table}` (tenant_id,app_code,title,description,cover_uri,sort,status,extra,create_time,update_time) VALUES (?,?,?,?,?,17,0,?,10,10)",
                        [$tenant, $code, $value, '保留说明', '/custom-cover.png', '{"is_recommend":1}']);
                    $expected[$tenant . ':' . $code] = $tenant < 2 ? $title : $value;
                }
            }
            $db->execute("INSERT INTO `{$table}` (tenant_id,app_code,title) VALUES (1,'aigc_copywriting','图片生成'),(4,'aigc_llm','AIGC对话'),(5,'aigc_llm','Aigc对话')");
            $expected['1:aigc_copywriting'] = '图片生成';
            $expected['4:aigc_llm'] = 'Agent对话';
            $expected['5:aigc_llm'] = 'Aigc对话';
            $before = $db->query("SELECT * FROM `{$table}` ORDER BY id");
            SqlMigrationExecutor::execute($sql, $prefix, $db);
            $after = $db->query("SELECT * FROM `{$table}` ORDER BY id");
            foreach ($after as $i => $row) {
                self::assertSame($expected[$row['tenant_id'] . ':' . $row['app_code']], $row['title']);
                $old = $before[$i];
                if ($old['title'] === $row['title']) self::assertSame($old['update_time'], $row['update_time']);
                unset($old['title'], $old['update_time'], $row['title'], $row['update_time']);
                self::assertSame($old, $row, 'All other configuration fields must remain unchanged');
            }
            SqlMigrationExecutor::execute($sql, $prefix, $db);
            self::assertSame($after, $db->query("SELECT * FROM `{$table}` ORDER BY id"));
        } finally {
            $db->execute("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
        }
    }
}
