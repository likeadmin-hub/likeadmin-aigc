<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App(dirname(__DIR__,2).'/'))->initialize();
use app\common\service\app\aigc_llm\agent\AgentPlanService as Plan;
$view=['status'=>'failed','results'=>[],'generation_task'=>['schema_version'=>1,'app_task_id'=>73,'task_ref'=>'app_task:73','status'=>'failed','billing_status'=>'refunded','results'=>[]]];
if(!Plan::retryReady($view))throw new RuntimeException('Original nested unified refunded receipt rejected');
foreach(['pending','reserved','settled','pending_usage','unknown']as$status){$x=$view;$x['generation_task']['billing_status']=$status;if(Plan::retryReady($x))throw new RuntimeException('Unresolved fee authorized retry');}
foreach(['running','success','needs_review']as$status){$x=$view;$x['generation_task']['status']=$status;if(Plan::retryReady($x))throw new RuntimeException('Unresolved/result task authorized retry');}
foreach(['schema_version'=>2,'task_ref'=>'app_task:99','app_task_id'=>0,'results'=>[['image_uri'=>'owned.png']]]as$key=>$value){$x=$view;$x['generation_task'][$key]=$value;if(Plan::retryReady($x))throw new RuntimeException('Invalid or populated receipt authorized retry');}
$partial=$view;$partial['results']=[['image_uri'=>'owned.png']];if(Plan::retryReady($partial))throw new RuntimeException('Native retained result authorized retry');
if(!Plan::retryReady(['status'=>'failed','results'=>[],'billing_status'=>'refunded'])||Plan::retryReady(['status'=>'failed','results'=>[],'billing_status'=>'refunded','generation_task_unavailable'=>true]))throw new RuntimeException('Legacy compatibility or unavailable-receipt guard changed');
echo "Home Agent retry receipt passed: nested canonical refund, native compatibility and 14 unresolved/invalid/available-result guards; no task or wallet writes\n";
