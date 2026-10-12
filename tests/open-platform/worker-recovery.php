<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenRecovery as R;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
foreach([['HY000',2006,'server has gone away'],['HY000',2013,'Lost connection'],['08006',0,'transport closed']] as [$state,$code,$message]){
 $pdo=new PDOException($message);$pdo->errorInfo=[$state,$code,$message];
 check(R::connectionLost($pdo),'Raw connection failure ignored');
 check(R::connectionLost(new think\db\exception\PDOException($pdo,[],'private sql')),'ORM lost its driver information');
 check(R::connectionLost(new RuntimeException('business wrapper',0,$pdo)),'Wrapped failure ignored');
}
foreach([['40001',1213,'Deadlock found'],['23000',1062,'Duplicate entry']] as $info){$pdo=new PDOException($info[2]);$pdo->errorInfo=$info;check(!R::connectionLost($pdo),'Non-connection error retires worker');}
check(!R::connectionLost(new RuntimeException('customer text server has gone away')),'Untrusted error text retires worker');
echo "PASS PDO and ORM disconnect retirement, wrapped failures and non-connection classification\n";
