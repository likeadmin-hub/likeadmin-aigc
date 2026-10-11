<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenFiles;
use app\common\service\open_platform\OpenException;
$one=tempnam(sys_get_temp_dir(),'open-upload-');$two=tempnam(sys_get_temp_dir(),'open-upload-');file_put_contents($one,'first');file_put_contents($two,'second');
try{
 $a=new \think\file\UploadedFile($one,'asset.txt','text/plain',UPLOAD_ERR_OK,true);$b=new \think\file\UploadedFile($two,'asset.txt','text/plain',UPLOAD_ERR_OK,true);
 if(OpenFiles::fingerprint([],['file'=>$a])===OpenFiles::fingerprint([],['file'=>$b]))throw new RuntimeException('Different uploads share identity');
 if(!isset(OpenFiles::flatten(['voices'=>[$a,$b]])['voices[1]']))throw new RuntimeException('Nested multipart files not preserved');
 $schema=['type'=>'object','properties'=>['file'=>['type'=>'string','format'=>'binary']],'required'=>['file']];
 if(OpenFiles::forSchema([],['file'=>$a],$schema)['file']!=='asset.txt')throw new RuntimeException('Binary field metadata');
 $rejected=false;try{OpenFiles::forSchema(['file'=>'fake'],[],$schema);}catch(OpenException $e){$rejected=true;}if(!$rejected)throw new RuntimeException('Accepted fake file parameter');
 echo "PASS upload content identity, multipart arrays and required binary input\n";
}finally{@unlink($one);@unlink($two);}
