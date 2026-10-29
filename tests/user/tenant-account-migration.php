<?php

use app\common\service\database\TenantUserAccountMigration;
use app\common\service\user\UserAccountService;
require dirname(__DIR__, 2).'/vendor/autoload.php';

final class TenantUserAccountMigrationTest
{
    private static function fail(string $message): void { throw new LogicException($message); }
    private static function assertTrue($value): void { if ($value !== true) self::fail('Expected true'); }
    private static function assertFalse($value): void { if ($value !== false) self::fail('Expected false'); }
    private static function assertSame($expected, $actual): void { if ($expected !== $actual) self::fail('Values differ'); }
    private static function assertCount(int $expected, array $actual): void { self::assertSame($expected, count($actual)); }
    private static function assertStringContainsString(string $expected, string $actual): void { if (!str_contains($actual, $expected)) self::fail('Missing '.$expected.' in '.$actual); }

    private function database(array $tables, array $tenants = [['sn'=>'12345678','tactics'=>1],['sn'=>'alpha_8','tactics'=>1]]): object
    {
        return new class($tables, $tenants) {
            public array $executed = [];
            public function __construct(public array $tables, public array $tenants) {}
            public function query(string $sql): array
            {
                if (str_contains($sql, 'information_schema.TABLES')) return array_map(fn($name) => ['TABLE_NAME'=>$name], array_keys($this->tables));
                if (str_starts_with($sql, 'SELECT `sn`')) return str_contains($sql,'WHERE `tactics`=1') ? array_values(array_filter($this->tenants,fn($tenant)=>(int)$tenant['tactics']===1)) : $this->tenants;
                preg_match('/FROM `([^`]+)`/', $sql, $match);
                $table = $this->tables[$match[1]];
                if (str_starts_with($sql, 'SHOW INDEX')) return $table['indexes'];
                if (str_contains($sql, 'HAVING COUNT(*)')) return ($table['duplicates'] ?? false) ? [['conflict'=>1]] : [];
                throw new RuntimeException('Unexpected query: '.$sql);
            }
            public function execute(string $sql): void { $this->executed[] = $sql; }
        };
    }

    private function index(string $name, array $columns, int $nonUnique = 0): array
    {
        return array_map(fn($column, $position) => ['Key_name'=>$name, 'Column_name'=>$column, 'Seq_in_index'=>$position+1, 'Non_unique'=>$nonUnique, 'Sub_part'=>null], $columns, array_keys($columns));
    }

    public function testMigratesSharedAndSplitUserTablesWithCustomPrefixOnly(): void
    {
        $legacy = ['indexes'=>$this->index('account', ['account'])];
        $db = $this->database(['custom_user'=>$legacy, 'custom_user_12345678'=>$legacy, 'custom_user_alpha_8'=>$legacy, 'custom_tenant_admin'=>$legacy, 'custom_user_auth'=>$legacy, 'la_user'=>$legacy]);
        $plans = TenantUserAccountMigration::run('custom_', $db);
        self::assertCount(3, $plans);
        self::assertSame($plans, $db->executed);
        self::assertStringContainsString('ADD UNIQUE INDEX `uk_tenant_account` (`tenant_id`,`account`)', $plans[0]);
        self::assertStringContainsString('DROP INDEX `account`', $plans[0]);
        self::assertStringContainsString('`custom_user_12345678`', $plans[1]);
        self::assertStringContainsString('`custom_user_alpha_8`', $plans[2]);
    }

    public function testRepeatedMigrationAndDryRunDoNotMutate(): void
    {
        $db = $this->database(['la_user'=>['indexes'=>$this->index('uk_tenant_account', ['tenant_id','account'])]]);
        self::assertSame([], TenantUserAccountMigration::run('la_', $db));
        $db->tables['la_user']['indexes'] = $this->index('account', ['account']);
        self::assertCount(1, TenantUserAccountMigration::run('la_', $db, true));
        self::assertSame([], $db->executed);
    }

    public function testSharedTenantSerialsDoNotBlockAndHyphenSplitIsSupported(): void
    {
        $legacy=['indexes'=>$this->index('account',['account'])];
        $db=$this->database(['la_user'=>$legacy,'la_user_split-blue'=>$legacy,'la_user_p3-private-signature'=>$legacy,'la_user_auth'=>$legacy,'la_tenant_admin'=>$legacy], [['sn'=>'p3-private-signature','tactics'=>0],['sn'=>'split-blue','tactics'=>1]]);
        $plans=TenantUserAccountMigration::run('la_',$db,true);
        self::assertCount(2,$plans);
        self::assertStringContainsString('`la_user_split-blue`',$plans[1]);
        self::assertSame([],$db->executed);
    }

    public function testUnsafeSplitSerialAndDatabasePrefixAreRejectedBeforeDdl(): void
    {
        $legacy=['indexes'=>$this->index('account',['account'])];
        foreach ([['la_', 'bad`serial'],['bad-prefix_', 'valid']] as [$prefix,$sn]) {
            $db=$this->database(['la_user'=>$legacy],[['sn'=>$sn,'tactics'=>1]]);
            try { TenantUserAccountMigration::run($prefix,$db); self::fail('Unsafe identifier accepted'); }
            catch (RuntimeException $e) { self::assertStringContainsString('Invalid',$e->getMessage()); }
            self::assertSame([],$db->executed);
        }
    }

    public function testAllTablesArePreflightedBeforeAnyDdl(): void
    {
        $legacy = ['indexes'=>$this->index('account', ['account'])];
        $db = $this->database(['la_user'=>$legacy, 'la_user_12345678'=>array_merge($legacy, ['duplicates'=>true])]);
        try { TenantUserAccountMigration::run('la_', $db); self::fail('Expected duplicate conflict'); }
        catch (RuntimeException $e) { self::assertStringContainsString('la_user_12345678', $e->getMessage()); }
        self::assertSame([], $db->executed);
    }

    public function testConflictingCompositeDefinitionIsRejected(): void
    {
        $db = $this->database(['la_user'=>['indexes'=>$this->index('uk_tenant_account', ['account'])]]);
        try { TenantUserAccountMigration::run('la_', $db); self::fail('Expected index conflict'); }
        catch (RuntimeException $e) { self::assertStringContainsString('索引定义冲突', $e->getMessage()); }
    }

    public function testOnlyAccountUniqueViolationsBecomeFriendlyDuplicates(): void
    {
        self::assertTrue(UserAccountService::isDuplicateAccount(new RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-alice1' for key 'la_user.uk_tenant_account'")));
        self::assertTrue(UserAccountService::isDuplicateAccount(new RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'alice1' for key 'account'")));
        self::assertFalse(UserAccountService::isDuplicateAccount(new RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '123' for key 'sn'")));
        self::assertFalse(UserAccountService::isDuplicateAccount(new RuntimeException('Connection lost while registering account')));
    }

    public function testUpgradeEntrypointAndInstallTemplatesStayInParity(): void
    {
        $root = dirname(__DIR__, 2);
        $sql = file_get_contents($root.'/upgrade/20261029_tenant_user_account_unique.sql');
        self::assertSame($sql, file_get_contents($root.'/public/upgrade/20261029_tenant_user_account_unique.sql'));
        $db = $this->database(['la_user'=>['indexes'=>$this->index('account', ['account'])]]);
        \app\common\service\database\SqlMigrationExecutor::execute($sql, 'la_', $db);
        self::assertCount(1, $db->executed);
        self::assertStringContainsString('ALTER TABLE `la_user`', $db->executed[0]);
        foreach (['public/install/db/like.sql'=>'la_user', 'app/platformapi/db/tenant.sql'=>'la_user_{tenantSn}'] as $file=>$table) {
            $content = file_get_contents($root.'/'.$file);
            preg_match('/CREATE TABLE `'.preg_quote($table, '/').'`\s*\((.*?)\) ENGINE/s', $content, $match);
            self::assertTrue(isset($match[1]));
            self::assertTrue(preg_match('/UNIQUE INDEX `uk_tenant_account`\s*\(`tenant_id`,\s*`account`\)/', $match[1]) === 1);
            self::assertFalse(preg_match('/UNIQUE (?:INDEX|KEY) `account`\s*\(`account`\)/', $match[1]) === 1);
        }
    }
}

$tests = new TenantUserAccountMigrationTest();
foreach (get_class_methods($tests) as $method) if (str_starts_with($method, 'test')) { $tests->$method(); echo "PASS $method\n"; }
