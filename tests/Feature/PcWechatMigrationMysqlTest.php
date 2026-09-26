<?php

use app\common\service\database\PcWechatMigration;
use app\common\service\database\SqlMigrationExecutor;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * Explicit local opt-in. Creates only disposable, random-prefix tables.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PcWechatMigrationMysqlTest extends TestCase
{
    public function testBaseAndShardMigrationIsIdempotentAndIdentityIsScoped(): void
    {
        if (getenv('PC_WECHAT_MYSQL_TEST') !== '1') $this->markTestSkipped('Local MySQL opt-in required');
        (new \think\App())->initialize();
        $db = Db::connect();
        self::assertContains($db->getConfig('hostname'), ['127.0.0.1', 'localhost'], 'Run only against local MySQL');
        $prefix = 'pcwx_test_' . bin2hex(random_bytes(5)) . '_';
        $base = $prefix . 'user_auth'; $shard = $base . '_123';
        try {
            foreach ([$base, $shard] as $table) {
                $db->execute("CREATE TABLE `{$table}` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id int NOT NULL, user_id int NOT NULL, terminal int NOT NULL, openid varchar(128) NOT NULL, UNIQUE INDEX openid(openid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $db->execute("INSERT INTO `{$table}` (tenant_id,user_id,terminal,openid) VALUES (1,10,4,'legacy')");
            }
            self::assertCount(2, PcWechatMigration::run($prefix, $db, true));
            SqlMigrationExecutor::execute(file_get_contents(root_path() . 'upgrade/20260926_pc_wechat_identity.sql'), $prefix, $db);
            self::assertSame([], PcWechatMigration::run($prefix, $db));
            foreach ([$base, $shard] as $table) {
                self::assertSame('', $db->query("SELECT appid FROM `{$table}` WHERE id=1")[0]['appid']);
                $db->execute("INSERT INTO `{$table}` (tenant_id,user_id,terminal,appid,openid) VALUES (1,10,4,'wxA','same'),(2,20,4,'wxA','same'),(1,10,4,'wxB','same'),(1,10,1,'','same')");
                try {
                    $db->execute("INSERT INTO `{$table}` (tenant_id,user_id,terminal,appid,openid) VALUES (1,99,4,'wxA','same')");
                    self::fail('Duplicate identity must be rejected');
                } catch (\Throwable $e) { self::assertStringContainsString('Duplicate', $e->getMessage()); }
                self::assertSame(5, (int)$db->query("SELECT COUNT(*) AS n FROM `{$table}`")[0]['n']);
            }
            $cfg = $db->getConfig();
            $other = new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . ($cfg['hostport'] ?: 3306) . ';dbname=' . $cfg['database'], $cfg['username'], $cfg['password']);
            $key = 'pcwx:' . substr(hash('sha256', $cfg['database'] . ':identity:1'), 0, 55);
            self::assertSame(1, (int)$db->query('SELECT GET_LOCK(?, 0) AS acquired', [$key])[0]['acquired']);
            try {
                $stmt = $other->prepare('SELECT GET_LOCK(?, 0)'); $stmt->execute([$key]);
                self::assertSame(0, (int)$stmt->fetchColumn(), 'Other connections cannot enter the same identity lock');
            } finally { $db->query('SELECT RELEASE_LOCK(?)', [$key]); }
        } finally {
            foreach ([$base, $shard] as $table) $db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testConflictsStopBeforeAnyTableIsAltered(): void
    {
        if (getenv('PC_WECHAT_MYSQL_TEST') !== '1') $this->markTestSkipped('Local MySQL opt-in required');
        (new \think\App())->initialize(); $db = Db::connect();
        self::assertContains($db->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        $prefix = 'pcwx_test_' . bin2hex(random_bytes(5)) . '_'; $table = $prefix . 'user_auth';
        try {
            $db->execute("CREATE TABLE `{$table}` (tenant_id int, user_id int, terminal int, openid varchar(128)) ENGINE=InnoDB");
            $db->execute("INSERT INTO `{$table}` VALUES (1,10,4,'same'),(1,20,4,'same')");
            try { PcWechatMigration::run($prefix, $db); self::fail('Conflict must stop migration'); }
            catch (RuntimeException $e) { self::assertStringContainsString('冲突', $e->getMessage()); }
            self::assertNotContains('appid', array_column($db->query("SHOW COLUMNS FROM `{$table}`"), 'Field'));
        } finally { $db->execute("DROP TABLE IF EXISTS `{$table}`"); }
    }
}
