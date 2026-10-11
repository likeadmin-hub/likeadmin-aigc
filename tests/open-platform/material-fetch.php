<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenCallContext as Context;
use app\common\service\open_platform\OpenToolRequest;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
check(!Context::active(),'Ordinary request changed its storage path');$original=app()->request;
app()->instance('request',OpenToolRequest::from(request(),[],['app_code'=>'aigc_music','route'=>'POST estimate']));
try{
 check(Context::active()&&Context::id()==='','Pre-admission estimate is not secured');
 foreach([
  ['aigc_music\\AigcMusicAssetService','downloadRemoteContent',['http://127.0.0.1/private']],
  ['aigc_digital_human\\AigcDigitalHumanAssetService','downloadRemoteContent',['http://127.0.0.1/private','audio']],
  ['aigc_digital_human\\AigcDigitalHumanService','detectRemoteAudioDuration',['http://127.0.0.1/private']],
  ['AiToolDownloadService','readRemoteContent',['http://127.0.0.1/private']],
  ['aigc_video\\AigcVideoPosterService','downloadVideo',['http://127.0.0.1/private','/nonexistent/','mp4']],
 ] as [$class,$method,$args]){$blocked=false;try{(new ReflectionMethod('app\\common\\service\\app\\'.$class,$method))->invoke(null,...$args);}catch(app\common\service\open_platform\OpenException $e){$blocked=$e->errorType==='invalid_url';}check($blocked,'Native material path bypassed private destination check: '.$class);}
 echo "PASS pre-admission tool probes, generated music/avatar media, result downloads and video posters reject private targets before network or writes\n";
}finally{app()->instance('request',$original);Context::leave();}
check(!Context::active(),'Scoped material policy leaked into ordinary pages');
