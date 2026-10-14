<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\app\aigc_llm\agent\AgentWorkerHealth as H;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/agent-heartbeat-'.bin2hex(random_bytes(8));
try{
 $h=new H($dir);$rows=H::processes($dir);check(count($rows)===1&&$rows[0]['healthy'],'Live worker not recognized');
 $h->beat('busy');$file=glob($dir.'/*.json')[0];$row=json_decode(file_get_contents($file),true);$row['updated_at']=time()-100;file_put_contents($file,json_encode($row));check(H::processes($dir)[0]['healthy'],'Valid synchronous turn marked unhealthy');
 $row['state']='idle';file_put_contents($file,json_encode($row));check(!H::processes($dir)[0]['healthy'],'Stale idle worker marked healthy');
 $row['state']='busy';$row['updated_at']=time()-1600;file_put_contents($file,json_encode($row));check(!H::processes($dir)[0]['healthy'],'Expired busy worker marked healthy');
 $h->close();check(H::processes($dir)===[],'Stopped worker retained live heartbeat');
 echo "Agent health passed: live/idle/busy/stale heartbeat and graceful cleanup\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);@rmdir($dir);}
