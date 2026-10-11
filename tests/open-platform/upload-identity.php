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
 $metadata=['sha256'=>hash_file('sha256',$one),'name'=>$a->getOriginalName(),'size'=>$a->getSize(),'mime'=>$a->getMime()];
 $config=['input'=>$schema,'example'=>['file'=>'asset.txt','caption'=>'fixture'],'example_files'=>['file'=>$metadata]];
 if(OpenFiles::exampleFingerprint($config)!==OpenFiles::fingerprint(['caption'=>'fixture'],['file'=>$a]))throw new RuntimeException('Reviewed upload example differs from multipart request');
 if(OpenFiles::exampleFingerprint($config)===OpenFiles::fingerprint(['caption'=>'fixture'],['file'=>$b]))throw new RuntimeException('Unreviewed content accepted');
 $config['input']=['type'=>'object','properties'=>['voices'=>['type'=>'array','items'=>['type'=>'string','format'=>'binary']]]];$config['example']=['voices'=>['asset.txt']];$config['example_files']=['voices[0]'=>$metadata];
 if(OpenFiles::exampleFingerprint($config)!==OpenFiles::fingerprint([],['voices'=>[$a]]))throw new RuntimeException('Reviewed multipart array identity');
 $config['example_files']=[];$rejected=false;try{OpenFiles::exampleFingerprint($config);}catch(OpenException $e){$rejected=true;}if(!$rejected)throw new RuntimeException('Upload preview without reviewed content accepted');
 echo "PASS upload content identity, multipart arrays, reviewed example fingerprints and required binary input\n";
}finally{@unlink($one);@unlink($two);}
