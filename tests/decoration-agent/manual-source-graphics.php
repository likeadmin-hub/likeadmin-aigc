<?php
declare(strict_types=1);
namespace app\common\service\storage {
    final class StorageSignedUrlService {
        public static int $now=1000;
        public static function resolve(string $uri,string $scope,string $engine,string $domain,?int $tenantId=null): ?string {
            return $engine==='qiniu'?$domain.'/'.$uri.'?expires='.(self::$now+300):null;
        }
    }
}
namespace {
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new \AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(\Throwable $e):void{fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use think\facade\Db;
use app\common\enum\FileEnum;
use app\common\service\decorate\agent\{DecorationAgentAssetReferences as R,DecorationAgentCatalog as C,DecorationAgentService as S};
use app\common\service\decorate\DecorateTemplateService as Templates;
use app\common\service\storage\StorageSignedUrlService as Signed;
$checks=0;
function manualCheck(bool $ok,string $why):void{global $checks;if(!$ok)throw new \RuntimeException($why);$checks++;}
function manualReject(callable $call,string $why):void{try{$call();}catch(\InvalidArgumentException|\RuntimeException $e){manualCheck(true,$why);return;}throw new \RuntimeException('Accepted: '.$why);}
function manualPage(array $rows,array $refs=[]):array{$w=C::create('app-collection','apps');$w['content']['source_graphics']=$rows;if($refs)$w['_decoration_asset_refs']=$refs;return ['data'=>[$w],'meta'=>[],'name'=>'manual fixture'];}
function manualRow(string $key,string $image):array{return ['app_code'=>'fixture','entry_key'=>$key,'mode'=>'image','image'=>$image];}
function manualRef(string $kind,int $id,string $value):array{return ['kind'=>$kind,'id'=>$id,'expected_value'=>$value];}
function manualDeep(array $widget):array{for($i=0;$i<4;$i++){$parent=C::create('card','depth-'.$i);$parent['children']=[$widget];$widget=$parent;}return ['data'=>[$widget],'meta'=>[]];}
function manualDeepWidget(array $page):array{return $page['data'][0]['children'][0]['children'][0]['children'][0]['children'][0];}
function manualFile(array $changes=[]):int{return (int)Db::name('tenant_file')->insertGetId(array_replace(['tenant_id'=>1,'source'=>FileEnum::SOURCE_ADMIN,'type'=>FileEnum::IMAGE_TYPE,'uri'=>'uploads/manual-graphics/valid.png','storage_scope'=>'tenant','storage_engine'=>'qiniu','storage_domain'=>'https://fixture.invalid'],$changes));}
function manualSlot(array $asset,string $purpose=''):array{
    $slot=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>0,'run_id'=>0,'slot_key'=>'manual:'.bin2hex(random_bytes(5)),'status'=>'success','request_json'=>S::json(['purpose'=>$purpose,'widget_id'=>'apps','field'=>'content.source_graphics.0.image']),'result_json'=>S::json(['status'=>'success','billing_status'=>'settled','images'=>[$asset]])]);
    $task=(int)Db::name('ai_app_task')->insertGetId(['task_no'=>'manual:'.bin2hex(random_bytes(8)),'idempotency_key'=>'manual:'.bin2hex(random_bytes(8)),'tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_image_slot','business_id'=>$slot,'status'=>'success']);
    $receipt=(int)Db::name('ai_consumption_log')->insertGetId(['consume_no'=>'manual:'.bin2hex(random_bytes(8)),'tenant_id'=>1,'user_id'=>0,'app_task_id'=>$task,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled','response_summary'=>S::json(['images'=>[$asset]])]);
    Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['consumption_id'=>$receipt]);return [$slot,$receipt,$task];
}
Db::startTrans();try{
    $file=manualFile();$stable='/uploads/manual-graphics/valid.png';$field='content.source_graphics.0.image';
    $page=manualPage([manualRow('a',$stable)],[$field=>manualRef('file',$file,$stable)]);$copy=$page;
    $normalized=R::normalizeSourceGraphics($page,[],1);
    manualCheck($normalized===$page&&$page===$copy,'ADMIN file claim persists only canonical identity without mutating input');
    $hash=S::hash($normalized);$url=R::resolveUrls($normalized,1);Signed::$now=1500;$fresh=R::resolveUrls($normalized,1);
    manualCheck(str_contains($url[$stable]??'','expires=1300')&&str_contains($fresh[$stable]??'','expires=1800'),'private delivery re-signs from immutable storage');
    manualCheck(S::hash($normalized)===$hash,'wire signing preserves raw hash');
    $forged=$page;$forged['data'][0]['_decoration_asset_refs'][$field]['storage_domain']='https://forged.invalid';$forged['data'][0]['_decoration_asset_refs'][$field]['image_uri']='uploads/wrong.png';
    manualCheck(R::normalizeSourceGraphics($forged,[],1)===$normalized,'client storage metadata is discarded rather than trusted');
    foreach([['tenant_id'=>2],['source'=>FileEnum::SOURCE_USER,'source_id'=>0],['type'=>FileEnum::VIDEO_TYPE],['delete_time'=>time()],['uri'=>'uploads/../bad.png'],['storage_engine'=>'unknown']] as $changes){$id=manualFile($changes);$bad=$page;$bad['data'][0]['_decoration_asset_refs'][$field]['id']=$id;manualReject(fn()=>R::normalizeSourceGraphics($bad,[],1),'invalid ADMIN image/storage/owner claim rejected');}
    $bad=$page;$bad['data'][0]['content']['source_graphics'][0]['image']='/uploads/unrelated.png';manualReject(fn()=>R::normalizeSourceGraphics($bad,[],1),'claim expected value cannot approve another URI');
    foreach(['https://fixture.invalid/uploads/manual-graphics/valid.png?sign=expired',$stable,'/resource/image/untrusted.png'] as $image)manualReject(fn()=>R::normalizeSourceGraphics(manualPage([manualRow('a',$image)]),[],1),'nonempty image without authority rejects signed or guessed paths');
    foreach([true,0,'1.5'] as $id){$bad=$page;$bad['data'][0]['_decoration_asset_refs'][$field]['id']=$id;manualReject(fn()=>R::normalizeSourceGraphics($bad,[],1),'malformed claim ID rejected');}
    $old=manualPage([manualRow('a',''),manualRow('b',$stable)],['content.source_graphics.1.image'=>manualRef('file',$file,$stable),'content.data.0.image'=>['legacy'=>'preserve']]);
    $moved=manualPage([manualRow('b',$stable)],$old['data'][0]['_decoration_asset_refs']);$restored=R::normalizeSourceGraphics($moved,$old,1);
    manualCheck(($restored['data'][0]['_decoration_asset_refs'][$field]??null)===manualRef('file',$file,$stable)&&!isset($restored['data'][0]['_decoration_asset_refs']['content.source_graphics.1.image']),'same widget/tuple/value recovers moved proof at new index');
    manualCheck($restored['data'][0]['_decoration_asset_refs']['content.data.0.image']===['legacy'=>'preserve'],'unrelated legacy refs untouched');
    $wrong=manualPage([manualRow('different',$stable)]);manualReject(fn()=>R::normalizeSourceGraphics($wrong,$old,1),'different tuple cannot inherit old proof');$wrong['data'][0]['id']='other';manualReject(fn()=>R::normalizeSourceGraphics($wrong,$old,1),'different widget cannot inherit old proof');
    $empty=manualPage([manualRow('a','')],[$field=>manualRef('file',$file,$stable)]);manualCheck(!isset(R::normalizeSourceGraphics($empty,$page,1)['data'][0]['_decoration_asset_refs'][$field]),'cleared image removes stale proof');
    $removed=manualPage([],[$field=>manualRef('file',$file,$stable)]);manualCheck(!isset(R::normalizeSourceGraphics($removed,$page,1)['data'][0]['_decoration_asset_refs']),'removed rows remove new-field proof');
    foreach(['source','icon'] as $mode){$bad=manualPage([array_replace(manualRow('a',$stable),['mode'=>$mode])]);manualReject(fn()=>R::normalizeSourceGraphics($bad,[],1),'inactive graphic mode cannot bypass image ownership');}
    $ambiguous=$old;$ambiguous['data'][]=$old['data'][0];manualReject(fn()=>R::normalizeSourceGraphics(manualPage([manualRow('b',$stable)]),$ambiguous,1),'duplicate old widget IDs cannot transfer proof');
    $double=manualPage([manualRow('a',$stable),manualRow('b',$stable)],[$field=>manualRef('file',$file,$stable),'content.source_graphics.1.image'=>manualRef('file',manualFile(['storage_domain'=>'https://other.invalid']),$stable)]);manualReject(fn()=>R::normalizeSourceGraphics($double,[],1),'same stable path cannot mask conflicting storage identity');
    $builtin='/resource/image/tenantapi/default/banner001.png';manualCheck(R::normalizeSourceGraphics(manualPage([manualRow('a',$builtin)]),[],1)['data'][0]['content']['source_graphics'][0]['image']===$builtin,'exact public builtin directory entry allowed');
    $asset=['image_uri'=>'uploads/manual-graphics/generated.png','storage_scope'=>'tenant','storage_engine'=>'qiniu','storage_domain'=>'https://fixture.invalid'];[$slot,$receipt,$task]=manualSlot($asset);$generated='/'.$asset['image_uri'];$slotPage=manualPage([manualRow('a',$generated)],[$field=>manualRef('slot',$slot,$generated)]);
    $slotBefore=Db::name('decorate_agent_image_slot')->where('id',$slot)->find();$receiptBefore=Db::name('ai_consumption_log')->where('id',$receipt)->find();$taskBefore=Db::name('ai_app_task')->where('id',$task)->find();
    manualCheck(R::normalizeSourceGraphics($slotPage,[],1)===$slotPage,'fresh Agent regular settled slot proof remains valid on first apply');
    manualCheck(Db::name('decorate_agent_image_slot')->where('id',$slot)->find()===$slotBefore&&Db::name('ai_consumption_log')->where('id',$receipt)->find()===$receiptBefore&&Db::name('ai_app_task')->where('id',$task)->find()===$taskBefore,'normalization does not alter slot/task/financial state');
    $oldSlot=manualPage([manualRow('blank',''),manualRow('a',$generated)],['content.source_graphics.1.image'=>manualRef('slot',$slot,$generated)]);$newSlot=manualPage([manualRow('a',$generated)]);
    manualCheck(R::normalizeSourceGraphics($newSlot,$oldSlot,1)===$slotPage,'reordered generated asset restores the same settled slot proof');
    $deep=manualDeep($page['data'][0]);manualCheck(R::normalizeSourceGraphics($deep,[],1)===$deep,'deepest template-supported new list normalizes');
    manualCheck(isset(R::resolveUrls($deep,1)[$stable])&&isset(R::captureIdentities($deep,1)[$stable]),'deepest new leaf supports wire signing and capture identities');
    $deepSlot=manualDeep($slotPage['data'][0]);$unbound=$deepSlot;$unbound['data'][0]['children'][0]['children'][0]['children'][0]['children'][0]['_decoration_asset_refs']=[];
    $slotRow=Db::name('decorate_agent_image_slot')->where('id',$slot)->find();$attached=R::attachGenerated($unbound,$slotRow,$asset);
    manualCheck(manualDeepWidget($attached)['_decoration_asset_refs'][$field]===manualRef('slot',$slot,$generated),'deepest new leaf can attach a freshly completed generated slot');
    $material=R::attachMaterials($unbound,[['slot_id'=>$slot,'url'=>$generated,'asset'=>$asset]],1);
    manualCheck(manualDeepWidget($material)['_decoration_asset_refs'][$field]===manualRef('slot',$slot,$generated),'deepest new leaf can attach authorized existing material');
    $legacyDeep=$deep;$leaf=manualDeepWidget($legacyDeep);$leaf['content']['data'][0]['image']=$generated;$leaf['styles']['background_image']=$generated;$leaf['_decoration_asset_refs']['content.data.0.image']=manualRef('slot',$slot,$generated);$leaf['_decoration_asset_refs']['styles.background_image']=manualRef('slot',$slot,$generated);$legacyDeep=manualDeep($leaf);
    manualCheck(!isset(R::resolveUrls($legacyDeep,1)[$generated])&&!isset(R::captureIdentities($legacyDeep,1)[$generated]),'new fifth-level support does not expand old image/background refs');
    $manifest=R::freezeMaterials($legacyDeep,1,['tenant']);manualCheck(count($manifest)===1&&$manifest[0]['stable_uri']===$stable,'deepest freeze includes only the new declared leaf');
    $invalidDeep=manualDeep(manualPage([manualRow('a',$stable)])['data'][0]);manualReject(fn()=>R::normalizeSourceGraphics($invalidDeep,[],1),'deepest new list cannot bypass claim validation');
    manualReject(fn()=>R::normalizeSourceGraphics($slotPage,[],2),'same slot proof cannot cross tenant');
    Db::name('ai_consumption_log')->where('id',$receipt)->update(['user_id'=>7]);manualReject(fn()=>R::normalizeSourceGraphics($slotPage,[],1),'ordinary user image consumption is not a shareable slot');Db::name('ai_consumption_log')->where('id',$receipt)->update(['user_id'=>0]);
    Db::name('ai_consumption_log')->where('id',$receipt)->update(['billing_status'=>'pending_usage']);manualReject(fn()=>R::normalizeSourceGraphics($slotPage,$slotPage,1),'unsettled slot cannot be retained or reattached');Db::name('ai_consumption_log')->where('id',$receipt)->update(['billing_status'=>'settled']);
    Db::name('ai_app_task')->where('id',$task)->update(['business_id'=>$slot+1]);manualReject(fn()=>R::normalizeSourceGraphics($slotPage,[],1),'wrong task binding rejected');Db::name('ai_app_task')->where('id',$task)->update(['business_id'=>$slot]);
    [$design]=manualSlot($asset,'design');$bad=$slotPage;$bad['data'][0]['_decoration_asset_refs'][$field]['id']=$design;manualReject(fn()=>R::normalizeSourceGraphics($bad,[],1),'design slot cannot become a graphic');manualReject(fn()=>R::normalizeSourceGraphics($slotPage,[],1),'same design path is forbidden even through a regular slot alias');
    $evidence=manualFile(['uri'=>'uploads/manual-graphics/evidence.png']);Db::name('decorate_agent_run')->insert(['tenant_id'=>1,'admin_id'=>1,'session_id'=>0,'idempotency_key'=>'manual:'.bin2hex(random_bytes(5)),'status'=>'completed','result_json'=>'{}','events_json'=>'[]','input_json'=>'{}','context_json'=>S::json(['visual_review'=>['screenshot_file_id'=>$evidence]])]);$evidenceUri='/uploads/manual-graphics/evidence.png';manualReject(fn()=>R::normalizeSourceGraphics(manualPage([manualRow('a',$evidenceUri)],[$field=>manualRef('file',$evidence,$evidenceUri)]),[],1),'review screenshot cannot become decorative material');
    $legacy=['data'=>[C::create('banner','legacy')],'meta'=>[],'unknown'=>new \stdClass];manualCheck(R::normalizeSourceGraphics($legacy,[],1)===$legacy,'pages without new field are exactly unchanged including unknown objects');
    $nested=C::create('card','parent');$nested['children']=[$page['data'][0]];$mixed=['data'=>[$nested],'meta'=>[['name'=>'page-meta','content'=>['unknown'=>new \stdClass]]]];$result=R::normalizeSourceGraphics($mixed,[],1);manualCheck($result===$mixed,'nested new images normalize without touching unknown/meta legacy values');
    $oldWidget=C::create('banner','unknown-list');$oldWidget['content']['source_graphics']=['unknown'=>new \stdClass];$mixed['data'][]=$oldWidget;
    manualCheck(R::normalizeSourceGraphics($mixed,[],1)===$mixed,'same-name unknown legacy field on non-app widgets remains untouched');
    // Exercise the real save hook and its object-preserving patch, on an isolated draft only.
    request()->tenantId=1;$template=Templates::add(1,['name'=>'manual graphics isolated','terminal'=>'mobile']);$draft=Templates::pageLists(1,$template['id'],'mobile')[0];
    $params=['template_id'=>$template['id'],'id'=>$draft['id'],'terminal'=>'mobile','meta'=>'[]'];
    $raw="[ {\"id\":\"legacy\",\"name\":\"banner\",\"content\":{\"unknown\":{}},\"legacy\":{}} ]";
    $saved=Templates::savePage(1,$params+['data'=>$raw]);manualCheck($saved['draft_data']===$raw,'real save of a page without the new list preserves raw JSON bytes');
    $savePage=$forged;$savePage['data'][0]['content']['unknown']=new \stdClass;$savePage['data'][0]['legacy']=new \stdClass;$savePage['data'][0]['_decoration_asset_refs']['legacy.unknown']=new \stdClass;
    $saved=Templates::savePage(1,$params+['data'=>S::json($savePage['data'])]);$objects=json_decode($saved['draft_data']);
    manualCheck($objects[0]->content->unknown instanceof \stdClass&&$objects[0]->legacy instanceof \stdClass&&$objects[0]->_decoration_asset_refs->{'legacy.unknown'} instanceof \stdClass,'canonicalizing new file claim preserves unrelated legacy empty objects');
    $savedRefs=(array)$objects[0]->_decoration_asset_refs;manualCheck((array)$savedRefs[$field]===manualRef('file',$file,$stable),'normal save stores only the authoritative new-field claim');
    $reopened=Templates::pageDetail(1,$params);
    manualCheck(isset(R::resolveUrls(['data'=>S::decode($reopened['draft_data']),'meta'=>[]],1)[$stable]),'saved/reopened draft retains private image signing authority');
    $savedBefore=Db::name('decorate_page')->where('id',$draft['id'])->find();$invalid=manualPage([manualRow('a','https://fixture.invalid/uploads/missing.png?expired=1')]);
    manualReject(fn()=>Templates::savePage(1,$params+['data'=>S::json($invalid['data'])]),'real save refuses TTL-only new graphic');
    manualCheck(Db::name('decorate_page')->where('id',$draft['id'])->find()===$savedBefore,'rejected new graphic leaves draft bytes and timestamps untouched');
    $deepSave=Templates::savePage(1,$params+['data'=>S::json($deep['data'])]);$deepRead=Templates::pageDetail(1,$params);
    manualCheck(isset(R::resolveUrls(['data'=>S::decode($deepRead['draft_data']),'meta'=>[]],1)[$stable]),'deepest private new leaf saves and reopens with signing authority');
    $sixth=C::create('card','sixth');$sixth['children']=$deep['data'];manualReject(fn()=>Templates::savePage(1,$params+['data'=>S::json([$sixth])]),'sixth level remains rejected by existing template limit');
    Db::name('tenant_file')->where('id',$file)->update(['delete_time'=>time()]);manualReject(fn()=>R::normalizeSourceGraphics($moved,$old,1),'reordered old proof is revalidated after revocation');
}finally{Db::rollback();}
echo "manual source graphics: $checks checks passed (DB rolled back; no provider/storage calls)\n";
}
