<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_video\VideoEffectMediaService as Media;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$load=new ReflectionMethod(Media::class,'legacyHashes');$load->setAccessible(true);
$path=tempnam(sys_get_temp_dir(),'video-preview-');
try{
 check($load->invoke(null,$path.'-missing')===[],'Missing optional manifest failed');
 foreach(['{broken','null','"invalid"','[]'] as $body){file_put_contents($path,$body);check($load->invoke(null,$path)===[],'Invalid optional manifest accepted');}
 $hash=hash('sha256','https://example.test/poster.webp');
 file_put_contents($path,json_encode(['demo'=>['poster'=>$hash,'video'=>['invalid']], 'bad'=>'invalid','url'=>['poster'=>'https://example.test/poster.webp']]));
 check($load->invoke(null,$path)===['demo'=>['poster'=>$hash]],'Only exact URL fingerprints may enter the compatibility map');
 $defaults=[['id'=>'demo','poster'=>'https://example.test/original.webp','video'=>'original.mp4']];
 $file=['uri'=>'preview.webp','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
 $custom=[['id'=>'demo','poster'=>'tenant.webp','video'=>'']];
 check(Media::resolve($custom,$defaults,['demo'=>['poster'=>$file,'video'=>$file]],static fn()=>'/managed.webp')===$custom,'Tenant custom/blank preview overwritten');
 check(Media::resolve($defaults,$defaults,[],static fn()=>'/managed.webp')===$defaults,'Missing managed media lost defaults');
 echo "Video preview fallback passed: missing/corrupt fingerprint maps, malformed fields, tenant overrides and managed defaults\n";
}finally{unlink($path);}
