<?php
use app\common\service\decorate\DecorationPortablePackage as Package;
$checks=0;
$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);++$checks;};
$make=function(array $files):string{$tmp=tempnam(sys_get_temp_dir(),'portable_test_');$z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);foreach($files as $name=>$body)$z->addFromString($name,$body);$z->close();try{return base64_encode(file_get_contents($tmp));}finally{unlink($tmp);}};
$reject=function(array $files,string $message)use($make,$check){try{Package::read($make($files));}catch(RuntimeException $error){$check(true,$message);return;}$check(false,$message);};
$base=['manifest.json'=>json_encode(['package_type'=>'likeadmin.decorate.template','schema_version'=>'1.0']),'template.json'=>json_encode(['template'=>['name'=>'old compatible'],'pages'=>[]]),'assets/.keep'=>''];
$old=Package::read($make($base));$check($old['payload']['template']['name']==='old compatible'&&$old['embedded']===[],'legacy schema1.0 without manifest imports');
$reject($base+['../evil.php'=>'bad'],'zip slip');$reject($base+['assets/../evil.php'=>'bad'],'assets traversal');$reject($base+['unknown.json'=>'{}'],'unknown zip root');$reject($base+['assets/too-big'=>str_repeat('0',21*1024*1024)],'compressed bomb uncompressed cap');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1sAAAAASUVORK5CYII=');$hash=hash('sha256',$png);$entry='assets/'.$hash.'.png';$url='https://private.example.test/a.png?e=1';$item=['url'=>$url,'entry'=>$entry,'sha256'=>$hash,'size'=>strlen($png),'mime'=>'image/png'];
$files=$base+[$entry=>$png,'assets/resources.json'=>json_encode(['version'=>1,'items'=>[$item]])];$p=Package::read($make($files));$check($p['embedded'][$url]===$png,'expired private URL retains validated embedded bytes');
$bad=$files;$bad[$entry]='corrupt';$reject($bad,'hash mismatch');$bad=$files;$bad['assets/resources.json']=json_encode(['version'=>1,'items'=>[array_replace($item,['entry'=>'assets/../secret'])]]);$reject($bad,'manifest entry traversal');$bad=$files;$bad['assets/resources.json']=json_encode(['version'=>1,'items'=>[array_replace($item,['mime'=>'image/jpeg'])]]);$reject($bad,'manifest MIME lie');
echo "PASS portable zip/embedded compatibility: $checks checks\n";
