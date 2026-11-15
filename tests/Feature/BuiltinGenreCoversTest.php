<?php
namespace Tests\Feature;
use app\common\service\app\BuiltinGenreCovers;
use PHPUnit\Framework\TestCase;
final class BuiltinGenreCoversTest extends TestCase
{
 public function testBundledCoverRefreshPreservesTenantArtworkAndRenamedGenres(): void
 {
  foreach(['aigc_store_visit','aigc_story_commerce']as$app){
   $defaults=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/'.$app.'/data/genres.json'),true);
   foreach($defaults as$default){
    $code=$default['code'];$legacy='/resource/image/apps/'.$app.'/types/'.$code.'.webp';
    foreach(['',$legacy,'https://site.test'.$legacy,'/apps/'.$app.'/static/genres/'.$code.'.png']as$cover){$row=$default;$row['cover_url']=$cover;self::assertSame($default['cover_url'],BuiltinGenreCovers::apply([$row],$app,$defaults)[0]['cover_url']);}
    foreach(['/uploads/custom.webp','https://cdn.test/custom.webp']as$cover){$row=$default;$row['cover_url']=$cover;self::assertSame([$row],BuiltinGenreCovers::apply([$row],$app,$defaults));}
    $row=$default;$row['name']='自定义类型';$row['cover_url']='';self::assertSame([$row],BuiltinGenreCovers::apply([$row],$app,$defaults));
    self::assertFileExists(dirname(__DIR__,2).'/public'.$default['cover_url']);
   }
  }
 }
 public function testNewAppAssetsArePortableAndAnimatedCoversAreBounded(): void
 {
  foreach(['aigc_motion_sync','aigc_product_video','aigc_story_commerce','aigc_store_visit']as$app){
   $base=dirname(__DIR__,2);$manifest=json_decode(file_get_contents($base.'/app/apps/'.$app.'/manifest.json'),true);
   self::assertStringEndsWith('/cover-v3.gif',$manifest['cover']);$gif=$base.'/public/'.$manifest['cover'];self::assertLessThan(230000,filesize($gif));self::assertSame('image/gif',getimagesize($gif)['mime']);
   foreach($manifest['public_assets']as$asset)self::assertSame(hash_file('sha256',$base.'/app/apps/'.$app.'/'.$asset['source']),hash_file('sha256',$base.'/'.$asset['target']),$asset['target']);
  }
 }
}
