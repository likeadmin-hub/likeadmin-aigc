<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\point_alert\AlertPolicy as P;
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
function rejects($f){try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected invalid config');}
$r=P::normalize(['enabled'=>true,'thresholds'=>['1000','200','50','0']]);
ok($r['thresholds']===['1000.00','200.00','50.00','0.00'],'sorted decimal levels');
rejects(fn()=>P::normalize(['thresholds'=>['0.001']]));
rejects(fn()=>P::normalize(['thresholds'=>[-1]]));
rejects(fn()=>P::normalize(['max_cycle'=>0]));
rejects(fn()=>P::normalize(['min_interval_minutes'=>-1]));
rejects(fn()=>P::normalize(['thresholds'=>range(1,11)]));
$now=strtotime('2026-10-19 12:00:00 Asia/Shanghai');
$d=P::decide($r,[],12000,$now);ok($d['send']&&$d['threshold']==='200.00','merge crossed levels');
$s=$d['state'];ok($s['cycle_count']===1&&$s['day_count']===1,'one round counted');
ok(!P::decide($r,$s,11000,$now+4000)['send'],'same level no repeat');
ok(!P::decide($r,$s,4000,$now+10)['send'],'interval cannot bypass');
$d=P::decide($r,$s,4000,$now+4000);ok($d['send']&&$d['threshold']==='50.00','escalation');
$s=$d['state'];$s['cycle_count']=3;ok(!P::decide($r,$s,0,$now+8000)['send'],'zero obeys cap');
$s['cycle_count']=1;$s['day_count']=3;ok(!P::decide($r,$s,0,$now+8000)['send'],'daily cap');
$s['day_count']=1;$d=P::decide($r,$s,15000,$now+8000);ok(!$d['send']&&$d['state']['cycle_count']===1,'small topup no reset');
$d=P::decide($r,$s,120000,$now+8000);ok($d['state']['cycle_count']===1,'stable recovery wait');
$d=P::decide($r,$d['state'],120000,$now+8601);ok($d['state']['cycle_count']===0&&$d['state']['day_count']===1,'recovery preserves daily');
$r['repeat_enabled']=true;$r['repeat_hours']=24;$s=P::decide($r,[],12000,$now)['state'];
ok(P::decide($r,$s,12000,$now+86401)['send'],'configured repeat');
$r['enabled']=false;ok(!P::decide($r,[],0,$now)['send'],'disabled');
$r=P::normalize(['enabled'=>true,'thresholds'=>['1000','200','50'],'min_interval_minutes'=>60]);
$s=P::decide($r,[],12000,$now)['state'];
$s=P::decide($r,$s,120000,$now+1)['state'];$s=P::decide($r,$s,120000,$now+602)['state'];
ok(!P::decide($r,$s,12000,$now+603)['send'],'recovery cannot bypass minimum interval');
echo "PASS point-alert policy\n";
