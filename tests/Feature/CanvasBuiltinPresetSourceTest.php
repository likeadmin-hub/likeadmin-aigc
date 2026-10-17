<?php
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
use app\common\service\app\aigc_canvas\{CanvasBuiltinPresetSourceStore as Store,CanvasBuiltinPresetSourceService as Source,CanvasBuiltinPresetService as Builtin};
use app\common\enum\AdminTerminalEnum;
use think\facade\Config;
$n=0;$check=static function($ok,$why)use(&$n){if(!$ok)throw new RuntimeException($why);$n++;};
$reject=static function($fn)use($check){try{$fn();}catch(InvalidArgumentException $e){$check(true,'rejected');return;}throw new RuntimeException('Expected source-write rejection');};
if (in_array('--immutable-probe',$argv ?? [],true)) {
    Config::set(['source_author_tenant_id'=>1],'canvas_presets');request()->tenantId=1;request()->source=AdminTerminalEnum::TENANT;request()->adminInfo=['tenant_id'=>1,'admin_id'=>1,'root'=>1];
    $check(getenv('LIKEADMIN_IMMUTABLE_ASSETS')==='1' && !Source::capability(1)['enabled'],'Immutable deployment allows source writes');
    echo "PASS immutable deployment rejects source authoring\n";exit;
}
if (($argv[1] ?? '')==='--symlink-probe') {
    $root=(string)($argv[2] ?? '');$check(str_starts_with($root,'/tmp/canvas-source-link-') && is_link($root.'/catalog/builtin-link.json'),'Symlink fixture missing');
    $before=file_get_contents($root.'/target.json');$store=new Store($root.'/catalog',$root.'/journal');
    $reject(static fn()=>$store->save(hash('sha256','link'),hash('sha256','link'),'update','builtin-link',1,static fn()=>throw new RuntimeException('Symlink reached builder')));
    $check(file_get_contents($root.'/target.json')===$before,'Symlink changed an outside file');echo "PASS symlink target is rejected before source write\n";exit;
}
$key=static fn($value)=>hash('sha256',$value);
$preset=static fn($id,$version,$title='Fixture')=>['filename'=>$id.'.json','media_count'=>0,'preset'=>['schema_version'=>1,'id'=>$id,'version'=>$version,'title'=>$title,'graph'=>['nodes'=>[['id'=>'text','type'=>'text','metadata'=>['content'=>'Portable prompt']]],'edges'=>[]]]];
$root=sys_get_temp_dir().'/canvas-source-test-'.bin2hex(random_bytes(8));mkdir($root,0770,true);mkdir($root.'/catalog',0770);
$store=new Store($root.'/catalog',$root.'/journal');$id='builtin-source-fixture';
$oldConfig=config('canvas_presets');$oldAdmin=request()->adminInfo ?? [];$oldTenant=request()->tenantId ?? 0;$oldSource=request()->source ?? '';
$cleanup=static function($path)use(&$cleanup){if(is_dir($path)&&!is_link($path)){foreach(new FilesystemIterator($path) as $file)$cleanup($file->getPathname());rmdir($path);}else unlink($path);};
try {
    $new=$store->save($key('new'),$key('payload'),'new','',0,static fn()=>$preset($id,1));
    $file=$root.'/catalog/'.$id.'.json';
    $check(is_file($file) && $new['saved_to_source'] && $new['preset']['version']===1,'New source file was not saved');
    $check(json_decode(file_get_contents($file),true)['graph']['nodes'][0]['metadata']['content']==='Portable prompt','Saved source lost graph');
    $retry=$store->save($key('new'),$key('payload'),'new','',0,static fn()=>throw new RuntimeException('Retry rebuilt media'));
    $check($retry==$new && count(glob($root.'/catalog/*.json'))===1,'Retry created another source file');
    $reject(static fn()=>$store->save($key('new'),$key('different'),'new','',0,static fn()=>$preset('builtin-other',1)));
    $second=$store->save($key('update'),$key('updated-payload'),'update',$id,1,static fn()=>$preset($id,2,'Updated'));
    $check($second['preset']['id']===$id && $second['preset']['version']===2 && count(glob($root.'/catalog/*.json'))===1,'Update did not replace the same file');
    $before=file_get_contents($file);
    $repeat=$store->save($key('update'),$key('updated-payload'),'update',$id,1,static fn()=>throw new RuntimeException('Update retried generation'));
    $check($repeat['preset']['version']===2 && file_get_contents($file)===$before,'Retry incremented version');
    $reject(static fn()=>$store->save($key('stale'),$key('stale'),'update',$id,1,static fn()=>$preset($id,2)));
    $reject(static fn()=>$store->save($key('collision'),$key('collision'),'new','',0,static fn()=>$preset($id,1)));
    $reject(static fn()=>$store->save($key('path'),$key('path'),'update','../../escape',2,static fn()=>$preset($id,3)));
    $reject(static fn()=>$store->save($key('wrong-id'),$key('wrong-id'),'update',$id,2,static fn()=>$preset('builtin-wrong',3)));
    $reject(static fn()=>$store->save($key('failed-build'),$key('failed-build'),'update',$id,2,static fn()=>throw new InvalidArgumentException('Media unavailable')));
    $check(file_get_contents($file)===$before && !glob($root.'/catalog/.canvas-preset-*'),'Failure damaged source or exposed a temporary JSON');
    $store->save($key('lock'),$key('lock'),'new','',0,static function()use($store,$key,$preset,$reject){
        $reject(static fn()=>$store->save($key('parallel'),$key('parallel'),'new','',0,static fn()=>$preset('builtin-parallel',1)));
        return $preset('builtin-serialized',1);
    });
    $check(!is_file($root.'/catalog/builtin-parallel.json'),'Concurrent save bypassed catalog lock');
    if (function_exists('symlink')) {
        symlink($file,$root.'/catalog/builtin-link.json');
        $reject(static fn()=>$store->save($key('link'),$key('link'),'update','builtin-link',2,static fn()=>$preset('builtin-link',3)));
    }
    chmod($file,0444);clearstatcache();
    $reject(static fn()=>$store->save($key('readonly'),$key('readonly'),'update',$id,2,static fn()=>$preset($id,3)));
    chmod($file,0644);chmod($root.'/catalog',0555);clearstatcache();$reject(static fn()=>$store->assertWritable());chmod($root.'/catalog',0770);clearstatcache();
    // An interrupted request with a durable pending receipt resumes the same ID instead of rebuilding.
    $pendingId='builtin-pending';$pending=$preset($pendingId,1);
    $pendingResult=$pending+['saved_to_source'=>true,'source_path'=>'server/app/apps/aigc_canvas/data/presets/'.$pendingId.'.json'];
    $portable=Builtin::validate($pending['preset']);$pendingResult['preset']=$portable;
    $content=json_encode($portable,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($root.'/journal/'.$key('pending').'.json',json_encode(['fingerprint'=>$key('pending-payload'),'checksum'=>hash('sha256',$content),'committed'=>false,'result'=>$pendingResult]));
    $resumed=$store->save($key('pending'),$key('pending-payload'),'new','',0,static fn()=>throw new RuntimeException('Pending receipt rebuilt'));
    $check($resumed['preset']['id']===$pendingId && file_get_contents($root.'/catalog/'.$pendingId.'.json')===$content,'Interrupted write did not recover');
    // The globally shared source directory is never granted to an arbitrary tenant or role.
    request()->tenantId=1;request()->source=AdminTerminalEnum::TENANT;request()->adminInfo=['tenant_id'=>1,'admin_id'=>1,'root'=>1];
    Config::set(['source_author_tenant_id'=>0],'canvas_presets');$check(!Source::capability(1)['enabled'],'Default installation allows source writes');
    $reject(static fn()=>Source::save(1,[]));
    Config::set(['source_author_tenant_id'=>1],'canvas_presets');$check(Source::capability(1)['enabled'],'Designated author is unavailable');
    request()->adminInfo=['tenant_id'=>1,'admin_id'=>2,'root'=>0];$check(!Source::capability(1)['enabled'],'Non-root author can write source');$reject(static fn()=>Source::save(1,[]));
    request()->tenantId=2;request()->adminInfo=['tenant_id'=>2,'admin_id'=>1,'root'=>1];$check(!Source::capability(2)['enabled'],'Other tenant root can write source');$reject(static fn()=>Source::save(2,[]));
    request()->tenantId=1;request()->adminInfo=['tenant_id'=>2,'admin_id'=>1,'root'=>1];$check(!Source::capability(1)['enabled'],'Admin tenant mismatch accepted');
    request()->adminInfo=['tenant_id'=>1,'admin_id'=>1,'root'=>1];request()->source=AdminTerminalEnum::USER;$check(!Source::capability(1)['enabled'],'User API context can write source');
    echo "PASS $n atomic create/replace, retry, version, concurrency, failure recovery, path, permission and tenant-author checks; only temporary fixtures written\n";
} finally {
    Config::set($oldConfig,'canvas_presets');request()->adminInfo=$oldAdmin;request()->tenantId=$oldTenant;request()->source=$oldSource;
    chmod($root.'/catalog',0770);$cleanup($root);
}
