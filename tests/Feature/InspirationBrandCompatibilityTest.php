<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
use app\common\service\InspirationBrandService as Brand;
use app\common\service\app\aigc_video\VideoEffectMediaService as Media;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }

check(Brand::style('imagine') === 'inspiration', 'Old style must activate the same layout');
foreach (['default','immersive','coral','inspiration',null] as $style) check(Brand::style($style) === $style, 'Other styles must be unchanged');
$old = ['home_style'=>'imagine','imagine_background'=>['enabled'=>false,'items'=>[['type'=>'video','url'=>'/uploads/custom.mp4']]],'models'=>['grok-imagine-video-1.5-fast']];
$next = Brand::configuration($old);
check($next['inspiration_background'] === $old['imagine_background'], 'Legacy backgrounds must retain all content');
check(!isset($next['imagine_background']) && $next['models'] === $old['models'], 'Canonical output and supplier values');
check(Brand::configuration($next) === $next, 'Normalization must be idempotent');
check(Brand::configuration($old + ['inspiration_background'=>[]])['inspiration_background'] === [], 'Explicit new empty config must win');
check(Brand::assetPath('/imagine-home/social.jpeg') === '/inspiration-home/social.jpeg', 'Old cover paths remain readable');
check(Brand::assetPath('/uploads/custom.jpeg') === '/uploads/custom.jpeg', 'Tenant paths must not change');
foreach (['https://cdn.web.imagine.art/banner.mp4','https://imagine.animagic.art/banner.mp4','https://cdn-imagine.vyro.ai/a.webp','//www.imagine.art/a'] as $url) check(Brand::isReferenceUrl($url), 'Reference host missed');
check(!Brand::isReferenceUrl('https://imagine.art.example.com/a'), 'Unrelated host must remain intact');
$defaults = [['id'=>'camera','poster'=>'/resource/image/inspiration/camera.svg','video'=>'/resource/image/inspiration/camera.mp4']];
$legacy = ['camera'=>['poster'=>'https://own-cdn.example/old-reference.webp','video'=>'https://own-cdn.example/old-reference.mp4']];
$rows = [['id'=>'camera','poster'=>$legacy['camera']['poster'],'video'=>'https://imagine.animagic.art/old.mp4','template'=>['prompt'=>'original','product_id'=>7]]];
$clean = Media::brandlessPreviews($rows, $defaults, $legacy, []);
check($clean[0]['poster'] === $defaults[0]['poster'] && $clean[0]['video'] === $defaults[0]['video'], 'Both relocated and remote previews must be replaced');
check($clean[0]['template'] === $rows[0]['template'], 'Generation template must remain identical');
$custom = [['id'=>'camera','poster'=>'https://tenant.example/custom.webp','video'=>'']];
check(Media::brandlessPreviews($custom, $defaults, $legacy, []) === $custom, 'Custom/blank tenant previews must survive');
check(Media::brandlessPreviews($clean, $defaults, $legacy, []) === $clean, 'Media normalization must be idempotent');
$catalog = json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_video/data/video_effects.json'),true);
foreach ($catalog as $effect) foreach (['poster','video'] as $field) {
    check(!Brand::isReferenceUrl($effect[$field]), 'Reference URL in new catalog');
    check(is_file(dirname(__DIR__,2).'/public'.$effect[$field]), 'Owned preview must be shipped as source');
}
echo "Inspiration compatibility passed: configuration, paths, supplier values, previews, custom data and idempotency\n";
