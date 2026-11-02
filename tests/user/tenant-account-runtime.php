<?php
// In-memory SQLite only: never opens the configured customer/local MySQL database.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use app\api\validate\RegisterValidate;
use app\api\logic\LoginLogic;
use app\common\model\user\User;
use app\common\service\user\UserAccountService;
use think\facade\Config;
use think\facade\Db;

$app = new think\App();
$app->initialize();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, (string)$e.PHP_EOL); exit(1); });
Config::set(['default'=>'account_fixture', 'connections'=>['account_fixture'=>[
    'type'=>'sqlite', 'database'=>':memory:', 'prefix'=>'la_', 'fields_cache'=>false,
]]], 'database');
$db = Db::connect('account_fixture');
$db->execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, sn TEXT, delete_time INTEGER DEFAULT NULL)');
$db->execute('INSERT INTO la_tenant (id,tactics,sn) VALUES (1,0,11111111),(2,0,22222222),(3,1,33333333)');
$schema = '(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, sn INTEGER NOT NULL UNIQUE, account TEXT COLLATE NOCASE NOT NULL, password TEXT DEFAULT \'\', mobile TEXT DEFAULT \'\', delete_time INTEGER DEFAULT NULL, UNIQUE(tenant_id, account))';
$db->execute('CREATE TABLE la_user '.$schema);
$db->execute('CREATE TABLE la_user_33333333 '.$schema);
$assert = function ($value, string $message): void { if (!$value) throw new RuntimeException($message); echo 'PASS '.$message.PHP_EOL; };
$params = ['channel'=>4,'account'=>'alice1','password'=>'abc12345','password_confirm'=>'abc12345'];
$db->name('user')->insert(['tenant_id'=>1,'sn'=>11111111,'account'=>'alice1','password'=>'tenant1']);
request()->tenantId = 2;
$validator = new RegisterValidate();
$assert($validator->check($params), 'other tenant account may register');
$db->name('user')->insert(['tenant_id'=>2,'sn'=>22222222,'account'=>'alice1','password'=>'tenant2']);
$assert(!$validator->check($params) && $validator->getError() === '账号已存在', 'same tenant receives friendly duplicate');
$params['account'] = 'ALICE1';
$assert(!$validator->check($params), 'case-insensitive duplicate remains reserved');
$db->name('user')->where('tenant_id',2)->update(['delete_time'=>123]);
$assert(UserAccountService::exists('alice1'), 'soft-deleted account remains reserved in its tenant');
$assert(!$validator->check($params) && $validator->getError() === '账号已存在', 'deleted duplicate receives friendly validation');
$assert(LoginLogic::register($params) === false && LoginLogic::getError() === '账号已存在', 'registration logic also rejects reserved account before side effects');
try {
    $db->name('user')->insert(['tenant_id'=>2,'sn'=>44444444,'account'=>'ALICE1']);
    throw new RuntimeException('Database allowed duplicate account');
} catch (\think\db\exception\PDOException $e) {
    $assert(str_contains($e->getMessage(), 'SQLSTATE[23000]') && str_contains($e->getMessage(), 'tenant_id, account'), 'database constraint rejects same-tenant race winner duplicate');
}
request()->tenantId = 1;
$assert(User::where('account|mobile','alice1')->value('password') === 'tenant1', 'login user lookup is tenant scoped');
User::where('account','alice1')->update(['password'=>'reset1']);
$assert($db->name('user')->where('tenant_id',2)->value('password') === 'tenant2', 'password update cannot alter other tenant');
request()->tenantId = 3;
$assert(!UserAccountService::exists('alice1'), 'split tenant routes account validation to its own user table');
$db->table('la_user_33333333')->insert(['tenant_id'=>3,'sn'=>33333333,'account'=>'alice1']);
$assert(UserAccountService::exists('alice1'), 'split tenant duplicate is detected');
request()->tenantId = 1;
$seed = 20261029;
mt_srand($seed); $first=''; for ($i=0;$i<8;$i++) $first.=mt_rand(1,9);
$db->name('user')->insert(['tenant_id'=>2,'sn'=>(int)$first,'account'=>'other1','delete_time'=>123]);
mt_srand($seed);
$assert(User::createUserSn() !== $first, 'generated serial avoids other tenant and deleted serial collisions');
mt_srand($seed+1); $first=''; for ($i=0;$i<8;$i++) $first.=mt_rand(1,9);
$db->name('user')->insert(['tenant_id'=>1,'sn'=>55555555,'account'=>'u'.$first,'delete_time'=>123]);
mt_srand($seed+1);
$assert(User::createUserSn() !== $first, 'generated WeChat account avoids manually reserved account');
