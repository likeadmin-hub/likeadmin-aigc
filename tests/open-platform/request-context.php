<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\open_platform\OpenToolRequest;
$original=new \app\Request();$original->withGet(['id'=>'old','unrelated'=>'old'])->withPost(['id'=>'old']);$original->setMethod('POST');$original->param();$original->tenantId=21;$original->userId=34;$original->userInfo=['user_id'=>34,'tenant_id'=>21];
$fresh=OpenToolRequest::from($original,['id'=>55,'prompt'=>'new'],['app_code'=>'aigc_image','route'=>'POST tasks','controller'=>'Generate','action'=>'index']);
if((int)$fresh->param('id')!==55||$fresh->param('unrelated')!==null||$fresh->param('prompt')!=='new')throw new RuntimeException('Cached parameter contamination');
if($fresh->tenantId!==21||$fresh->userInfo['user_id']!==34)throw new RuntimeException('Trusted ownership lost');
if($original->param('id')!=='old')throw new RuntimeException('Original request mutated');
$tmp=tempnam(sys_get_temp_dir(),'open-context-upload-');file_put_contents($tmp,'synthetic upload');
try{
 $raw=['name'=>'voice.wav','tmp_name'=>$tmp,'type'=>'audio/wav','error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp)];
 $original->withFiles(['file'=>$raw,'references'=>array_map(static fn($v)=>[$v,$v],$raw)]);
 $uploads=OpenToolRequest::from($original,[],['app_code'=>'aigc_music','route'=>'POST asset/uploadAudio']);
 if(!$uploads->file('file') instanceof think\file\UploadedFile||$uploads->file('file')->getRealPath()!==$tmp||count($uploads->file('references'))!==2||$uploads->file('references.1')->getOriginalName()!=='voice.wav'||count($uploads->file())!==2)throw new RuntimeException('Tool request lost single or multiple original upload files');
}finally{@unlink($tmp);}
echo "PASS fresh tool parameters preserve trusted ownership without stale query cache\n";
