<?php
/** Download only the three outputs of the already authorized synthetic acceptance run. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenSafeHttp as H;
use app\common\service\FileService as F;
use think\facade\Db;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local synthetic evidence only');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$source=S::json(file_get_contents($dir.'/local_text_01400e0a404f.json'));check($source['result']==='passed'&&$source['tenant_id']===1&&$source['user_id']===1,'Expected completed synthetic report');
$out=$dir.'/media-evidence';if(!is_dir($out))mkdir($out,0700,true);$report=['verified_at'=>date(DATE_ATOM),'source'=>$source['run'],'items'=>[]];
foreach(['aigc_product_suite'=>['aigc_product_suite_result','image','image_uri'],'smart_clip'=>['smart_clip_result','video','video_uri']] as $app=>[$table,$type,$field]){
 $case=$source['cases'][$app];$id=app\common\service\open_platform\OpenToolTasks::decode($app,$case['task_id']);$row=S::owner('request',1,1)->where('request_id',$case['request_id'])->find();check($row&&$row['status']==='success'&&$row['billing_status']==='settled'&&(int)$row['active']===0,'Original request changed');
 $results=Db::name($table)->where(['tenant_id'=>1,'user_id'=>1,'task_id'=>$id,'delete_time'=>0])->select()->toArray();check(count($results)===$case['result_count'],'Stored result count changed');
 foreach($results as $result){foreach(['storage_scope','storage_engine','storage_domain'] as $meta)check(!empty($result[$meta]),'Result storage metadata missing');$url=F::getFileUrlByStorage($result[$field],$result['storage_scope'],$result['storage_engine'],$result['storage_domain']);$wire=H::get($url,33554432,60);$bytes=$wire['body'];$filename=$app.'-'.$result['id'].($type==='image'?'.image':'.mp4');$path=$out.'/'.$filename;file_put_contents($path,$bytes);$item=['app_code'=>$app,'request_id'=>$case['request_id'],'task_id'=>$case['task_id'],'result_id'=>$result['id'],'storage_scope'=>$result['storage_scope'],'storage_engine'=>$result['storage_engine'],'http_status'=>$wire['status'],'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'file'=>'media-evidence/'.$filename];
 if($type==='image'){$size=getimagesize($path);check($size!==false&&$size[0]>0&&$size[1]>0,'Output is not a decodable image');$item+=['width'=>$size[0],'height'=>$size[1],'mime'=>$size['mime']];}
 else{$command='/usr/bin/ffprobe';check(is_executable($command),'ffprobe unavailable; preserve output for separate verification');$lines=[];$code=0;exec(escapeshellarg($command).' -v error -show_entries format=duration:stream=codec_type,width,height,codec_name -of json '.escapeshellarg($path).' 2>/dev/null',$lines,$code);$probe=S::json(implode("\n",$lines));check($code===0&&(float)($probe['format']['duration']??0)>0&&array_filter($probe['streams']??[],fn($stream)=>$stream['codec_type']==='video'),'Output video has no playable stream');$item+=['duration'=>(float)$probe['format']['duration'],'streams'=>$probe['streams']];}
 $report['items'][]=$item;echo S::encode($item),"\n";
 }
}
file_put_contents($dir.'/media-evidence.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));echo "PASS independent stored image/video content verification without new generation or charges\n";
