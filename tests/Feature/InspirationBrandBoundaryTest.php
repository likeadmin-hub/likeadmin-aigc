<?php
/** Offline fixtures: exercise compatibility without writes or paid requests. */
namespace app\common\model\file {
    class TenantFile {
        public static function where($key, $tenant) { throw new \LogicException('tenant-ownership-check:'.$tenant); }
    }
}
namespace {
require dirname(__DIR__, 2).'/vendor/autoload.php';
use app\common\service\InspirationBrandService as Brand;
use app\common\service\app\aigc_short_drama\ShortDramaTemplateService;
use app\common\service\home\LatestNewsService;
function verify($ok, $message) { if (!$ok) throw new \RuntimeException($message); }

$stored = ['home_style'=>'inspiration','inspiration_background'=>['enabled'=>false,'items'=>[['type'=>'video','url'=>'/uploads/original.mp4','poster_url'=>'/uploads/original.jpg']]],'models'=>['grok-imagine-video-1.5'],'status'=>1];
$old = Brand::publicPresentation($stored, false);
// Simulate an unchanged save by a cached form that only knows the old keys.
$saved = Brand::configuration(['home_style'=>$old['home_style'],'imagine_background'=>$old['imagine_background'],'models'=>$old['models'],'status'=>$old['status']]);
verify($saved == $stored, 'Cached admin save must preserve the full background and model settings');
verify(Brand::publicPresentation($stored,true) === $stored, 'Modern admin must receive canonical data unchanged');
$website = ['pc_home_style'=>'inspiration','pc_home_top_mode'=>'shortcuts','pc_home_shortcuts_editor'=>['draft'=>['cards'=>[1,2,3]]]];
$oldWebsite = Brand::publicPresentation($website,false);
verify($oldWebsite['pc_home_style']==='imagine', 'Cached website editor must select its original style');
verify(Brand::configuration($oldWebsite)===$website, 'Website editor round trip must retain layout and shortcuts');
foreach (['/imagine-home/custom.mp4','imagine-home/custom.jpeg','/imagine-home/social.jpeg.bak','/uploads/imagine-home/social.jpeg','https://cdn.example.com/imagine-home/social.jpeg'] as $uri) {
    verify(Brand::assetPath($uri)===$uri, 'Non-bundled media URL must remain exact: '.$uri);
}
$payload = new \ReflectionMethod(ShortDramaTemplateService::class,'payload'); $payload->setAccessible(true);
foreach (['character','fashion','snooker','sunglasses','social'] as $name) {
    $base=['title'=>'原模板','cover_asset_id'=>0,'status'=>1,'workflow_nodes'=>[],'workflow_edges'=>[],'input_slots'=>[]];
    $legacy=$payload->invoke(null,7,$base+['cover_url'=>'/imagine-home/'.$name.'.jpeg']);
    $modern=$payload->invoke(null,7,$base+['cover_url'=>'/inspiration-home/'.$name.'.jpeg']);
    verify($legacy===$modern, 'Old bundled cover must remain editable: '.$name);
}
foreach (['/imagine-home/custom.jpeg','/inspiration-home/custom.jpeg','https://example.com/inspiration-home/social.jpeg'] as $uri) {
    try { $payload->invoke(null,7,['title'=>'租户素材','cover_url'=>$uri]); throw new \RuntimeException('Ownership check bypassed'); }
    catch (\LogicException $e) { verify($e->getMessage()==='tenant-ownership-check:7','Tenant scope changed'); }
}
$curate=new \ReflectionMethod(LatestNewsService::class,'curateReferenceNews'); $curate->setAccessible(true);
$rows=[['id'=>'reference-999999','media_url'=>'https://cdn-imagine.vyro.ai/original.mp4','title'=>'用户原动态','enabled'=>0,'link'=>['kind'=>'none','path'=>'']]];
verify($curate->invoke(null,$rows)===$rows,'Brand cleanup must not expand news filtering to preserved CDN media');
echo "Brand boundary passed: cached admin round trips, bundled cover editing, tenant ownership and media preservation\n";
}
