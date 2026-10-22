<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\workbench\OperationsRange as R;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$now=strtotime('2026-10-21 12:34:56 +0800');
$r=R::resolve(['range'=>'today'],$now);
check(count($r['labels'])===13 && $r['bucket']==='hour','today only elapsed hourly buckets');
check($r['end_at']-$r['start_at']===$r['previous_end_at']-$r['previous_start_at'],'comparison same elapsed duration');
$r=R::resolve(['range'=>'yesterday'],$now);
check(count($r['labels'])===24 && $r['end_at']-$r['start_at']===86400,'yesterday complete day');
$r=R::resolve(['range'=>'7d'],$now);check(count($r['labels'])===7 && $r['bucket']==='day','seven daily buckets');
$r=R::resolve(['range'=>'custom','start_date'=>'2026-07-24','end_date'=>'2026-10-21'],$now);check(count($r['labels'])===90,'90-day limit inclusive');
foreach ([['range'=>'bad'],['range'=>'custom','start_date'=>'2026-02-30','end_date'=>'2026-03-01'],['range'=>'custom','start_date'=>'2026-07-23','end_date'=>'2026-10-21'],['range'=>'custom','start_date'=>'2026-10-20','end_date'=>'2026-10-22']] as $p){$failed=false;try{R::resolve($p,$now);}catch(InvalidArgumentException){$failed=true;}check($failed,'invalid date rejected');}
check(!app\common\service\workbench\OperationsAccess::allowed([],true),'anonymous access rejected');
echo "PASS operations date range, timezone, comparison and boundary validation\n";
