<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
use app\common\service\LikeAdminBrandService as Brand;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }

check(Brand::style('imagine') === 'likeadmin', 'Old style must activate the same layout');
foreach (['default','immersive','coral','likeadmin',null] as $style) check(Brand::style($style) === $style, 'Other styles must be unchanged');
$old = ['home_style'=>'imagine','imagine_background'=>['enabled'=>false,'items'=>[['type'=>'video','url'=>'/uploads/custom.mp4']]],'models'=>['grok-imagine-video-1.5-fast']];
$next = Brand::configuration($old);
check($next['likeadmin_background'] === $old['imagine_background'], 'Legacy backgrounds must retain all content');
check(!isset($next['imagine_background']) && $next['models'] === $old['models'], 'Canonical output and supplier values');
check(Brand::configuration($next) === $next, 'Normalization must be idempotent');
check(Brand::configuration($old + ['likeadmin_background'=>[]])['likeadmin_background'] === [], 'Explicit new empty config must win');
$canonical = $next + ['website'=>['pc_home_style'=>'likeadmin'],'user'=>['points'=>123.45]];
$legacy = Brand::publicPresentation($canonical, false);
check($legacy['home_style'] === 'imagine' && $legacy['website']['pc_home_style'] === 'imagine', 'Cached bundles must retain their original style switches');
check($legacy['imagine_background'] === $canonical['likeadmin_background'], 'Cached bundles must retain original background fields');
check($legacy['models'] === $canonical['models'] && $legacy['user'] === $canonical['user'], 'Protocol values and balance must not change with presentation');
check(Brand::publicPresentation($canonical, 3) === $canonical && !isset($canonical['imagine_background']), 'New clients and canonical storage must remain unchanged');
check(Brand::assetPath('/imagine-home/social.jpeg') === '/likeadmin-home/social.jpeg', 'Old cover paths remain readable');
check(Brand::assetPath('/uploads/custom.jpeg') === '/uploads/custom.jpeg', 'Tenant paths must not change');
foreach (['https://cdn.web.imagine.art/banner.mp4','https://imagine.animagic.art/banner.mp4','https://cdn-imagine.vyro.ai/a.webp','//www.imagine.art/a'] as $url) check(Brand::isReferenceUrl($url), 'Reference host missed');
check(!Brand::isReferenceUrl('https://imagine.art.example.com/a'), 'Unrelated host must remain intact');
check(Brand::hasBrandName('Imagine Apps') && Brand::hasBrandName('IMAGINEAPPS'), 'Brand title detection');
$catalog = json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_video/data/video_effects.json'),true);
check(count($catalog) === 90, 'All original effect presets remain available');
foreach ($catalog as $effect) check(!empty($effect['poster']) && !empty($effect['video']), 'Original previews remain available');
check(Brand::style('inspiration') === 'likeadmin', 'Intermediate style must remain readable');
foreach (['imagine', 'inspiration', 'likeadmin'] as $prefix) {
    $row = ['home_style'=>$prefix, $prefix.'_background'=>$old['imagine_background'], 'models'=>$old['models']];
    $normalized = Brand::configuration($row);
    check($normalized['home_style'] === 'likeadmin' && $normalized['likeadmin_background'] === $old['imagine_background'], 'Every previous field name must migrate without losing media');
    foreach ([0=>'imagine', 1=>'imagine', 2=>'inspiration', 3=>'likeadmin'] as $schema=>$wire) {
        $out = Brand::publicPresentation($normalized, $schema);
        check($out['home_style'] === $wire && $out[$wire.'_background'] === $old['imagine_background'], 'Wire schema must match the client');
        check(Brand::configuration($out) === $normalized, 'Old client saves must round-trip exactly');
    }
}
check(Brand::configuration($old + ['inspiration_background'=>['enabled'=>false], 'likeadmin_background'=>[]])['likeadmin_background'] === [], 'Own brand explicit empty values must outrank both aliases');
check(Brand::configuration($old + ['inspiration_background'=>[]])['likeadmin_background'] === [], 'Intermediate empty value must outrank oldest alias');
check(\app\common\service\InspirationBrandService::configuration($old) === Brand::configuration($old), 'Previous class name remains callable by extensions');
check(Brand::publicPresentation($canonical, true) === Brand::publicPresentation($canonical, 2), 'Boolean extension API remains schema 2');
check(Brand::assetPath('/inspiration-home/social.jpeg?rev=2') === '/likeadmin-home/social.jpeg?rev=2', 'Intermediate URL and query survive');
echo "LikeAdmin compatibility passed: configuration, paths, supplier values and original media availability\n";
