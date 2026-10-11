<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenException;
use app\common\service\app\aigc_music\AigcMusicExportDownload as Download;
use app\common\model\app\aigc_music\AigcMusicExport;
use app\common\model\file\TenantFile;
use app\common\enum\FileEnum;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,string $type){try{$fn();}catch(OpenException $e){check($e->errorType===$type,'Wrong download rejection: '.$e->errorType);return;}throw new RuntimeException('Invalid export downloaded');}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
$dir=public_path().'uploads/aigc_music/export';$name='download-fixture-'.bin2hex(random_bytes(12)).'.wav';$path=$dir.'/'.$name;$uri='uploads/aigc_music/export/'.$name;
check(is_dir($dir)||mkdir($dir,0700,true),'Fixture directory unavailable');
$bytes='RIFF'.pack('V',40).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',4)."\0\0\0\0";
$wallets=static fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
Db::startTrans();try{
    check(file_put_contents($path,$bytes)===strlen($bytes),'Fixture file write failed');
    $file=TenantFile::create(['tenant_id'=>1,'source_id'=>1,'source'=>FileEnum::SOURCE_USER,'type'=>FileEnum::FILE_TYPE,'name'=>$name,'uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time()]);
    $export=AigcMusicExport::create(['tenant_id'=>1,'user_id'=>1,'result_id'=>0,'export_type'=>'wav','file_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','status'=>'success','result_json'=>['sha256'=>hash('sha256',$bytes),'file_size'=>strlen($bytes),'mime_type'=>'audio/wav'],'create_time'=>time()]);
    $id=(int)$export['id'];$download=Download::read(1,1,$id);check($download['body']===$bytes&&$download['mime']==='audio/wav'&&str_ends_with($download['name'],'.wav'),'Owned download differs');
    denied(fn()=>Download::read(1,2,$id),'not_found');denied(fn()=>Download::read(2,1,$id),'not_found');
    $file->save(['source_id'=>2]);denied(fn()=>Download::read(1,1,$id),'not_found');$file->save(['source_id'=>1]);
    $file->save(['storage_engine'=>'qcloud']);denied(fn()=>Download::read(1,1,$id),'result_unverified');$file->save(['storage_engine'=>'local']);
    file_put_contents($path,str_repeat('X',strlen($bytes)));denied(fn()=>Download::read(1,1,$id),'result_storage_failed');
    Db::name('aigc_music_export')->where('id',$id)->update(['status'=>'running']);denied(fn()=>Download::read(1,1,$id),'not_found');
    echo "PASS owned export downloads preserve bytes and MIME; foreign export/file, storage mismatch, damaged bytes and unfinished exports denied without billing\n";
}finally{Db::rollback();@unlink($path);}
check($wallets()===$before,'Download charged wallets');
