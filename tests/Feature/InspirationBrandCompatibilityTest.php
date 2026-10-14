<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
use app\common\service\InspirationBrandService as Brand;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }

check(Brand::style('imagine') === 'inspiration', 'Old style must activate the same layout');
foreach (['default','immersive','coral','inspiration',null] as $style) check(Brand::style($style) === $style, 'Other styles must be unchanged');
$old = ['home_style'=>'imagine','imagine_background'=>['enabled'=>false,'items'=>[['type'=>'video','url'=>'/uploads/custom.mp4']]],'models'=>['grok-imagine-video-1.5-fast']];
$next = Brand::configuration($old);
check($next['inspiration_background'] === $old['imagine_background'], 'Legacy backgrounds must retain all content');
check(!isset($next['imagine_background']) && $next['models'] === $old['models'], 'Canonical output and supplier values');
check(Brand::configuration($next) === $next, 'Normalization must be idempotent');
check(Brand::configuration($old + ['inspiration_background'=>[]])['inspiration_background'] === [], 'Explicit new empty config must win');
$canonical = $next + ['website'=>['pc_home_style'=>'inspiration'],'user'=>['points'=>123.45]];
$legacy = Brand::publicPresentation($canonical, false);
check($legacy['home_style'] === 'imagine' && $legacy['website']['pc_home_style'] === 'imagine', 'Cached bundles must retain their original style switches');
check($legacy['imagine_background'] === $canonical['inspiration_background'], 'Cached bundles must retain original background fields');
check($legacy['models'] === $canonical['models'] && $legacy['user'] === $canonical['user'], 'Protocol values and balance must not change with presentation');
check(Brand::publicPresentation($canonical, true) === $canonical && !isset($canonical['imagine_background']), 'New clients and canonical storage must remain unchanged');
check(Brand::assetPath('/imagine-home/social.jpeg') === '/inspiration-home/social.jpeg', 'Old cover paths remain readable');
check(Brand::assetPath('/uploads/custom.jpeg') === '/uploads/custom.jpeg', 'Tenant paths must not change');
foreach (['https://cdn.web.imagine.art/banner.mp4','https://imagine.animagic.art/banner.mp4','https://cdn-imagine.vyro.ai/a.webp','//www.imagine.art/a'] as $url) check(Brand::isReferenceUrl($url), 'Reference host missed');
check(!Brand::isReferenceUrl('https://imagine.art.example.com/a'), 'Unrelated host must remain intact');
check(Brand::hasBrandName('Imagine Apps') && Brand::hasBrandName('IMAGINEAPPS'), 'Brand title detection');
$catalog = json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_video/data/video_effects.json'),true);
check(count($catalog) === 90, 'All original effect presets remain available');
foreach ($catalog as $effect) check(!empty($effect['poster']) && !empty($effect['video']), 'Original previews remain available');
echo "Inspiration compatibility passed: configuration, paths, supplier values and original media availability\n";
