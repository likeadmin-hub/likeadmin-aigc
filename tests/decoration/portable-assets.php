<?php
// Pure traversal/security tests. No application/bootstrap/database required.
$root=dirname(__DIR__,2);
require $root.'/app/common/service/decorate/DecorationPortableDocument.php';
require $root.'/app/common/service/decorate/DecorationPortableMedia.php';
require $root.'/app/common/service/storage/SvgSanitizer.php';
require $root.'/app/common/service/decorate/agent/DecorationAgentAssetReferences.php';
use app\common\service\decorate\DecorationPortableDocument as Document;
use app\common\service\decorate\DecorationPortableMedia as Media;
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function rejects($fn,$message){try{$fn();}catch(Throwable $e){check(true,$message);return;}check(false,$message);}
$widget=['name'=>'image-hotspot','content'=>['image'=>'/resource/a.png','areas'=>[['x'=>12.4,'y'=>50,'width'=>28,'height'=>19,'link'=>['path'=>'https://example.com/jump.png']]]],'styles'=>['icon_atlas'=>'/resource/atlas.png','background'=>'linear-gradient(red,blue), url("/resource/bg.png")','crop'=>['x'=>22,'y'=>33,'w'=>44,'h'=>55]],'extra'=>(object)[],'_decoration_asset_refs'=>['content.image'=>['kind'=>'file','id'=>99,'expected_value'=>'/resource/a.png']]];
$payload=['template'=>['cover'=>'/resource/cover.jpg','settings'=>['mobile_tabbar'=>['list'=>[['link'=>['path'=>'/pages/diy/diy','query'=>['code'=>'test']],'icon'=>'/resource/tab.png']]]]],'pages'=>[['data'=>json_encode([$widget]),'draft_data'=>json_encode([$widget]),'published_data'=>json_encode([$widget]),'meta'=>json_encode([['name'=>'page-meta','content'=>['bg_image'=>'/resource/meta.png']]]),'draft_meta'=>'[]','published_meta'=>'[]']]];
$seen=[];$out=Document::rewrite($payload,function($url,$location)use(&$seen){$seen[$url]=true;return 'https://cdn.example.test'.$url;});
check(count($seen)===6,'cover/settings/all versions/CSS/atlas/meta discovered and deduplicated');
check(!isset($seen['https://example.com/jump.png']),'navigation URL never downloaded');
$decoded=json_decode($out['pages'][0]['data']);check($decoded[0]->styles->crop->w===44,'atlas crop unchanged');
check($decoded[0]->content->areas[0]->x===12.4,'hotspot geometry unchanged');check($decoded[0]->extra instanceof stdClass,'empty JSON objects preserved');
check(!isset($decoded[0]->_decoration_asset_refs),'source refs removed');
check($decoded[0]->styles->background==='linear-gradient(red,blue), url("https://cdn.example.test/resource/bg.png")','CSS syntax preserved');
$html='<p><a href="https://example.com/b.png"><img src="/resource/a.png" srcset="/resource/a.png 1x, /resource/b.png 2x" style="background:url(/resource/c.png)"></a><video poster="/resource/poster.jpg" src="/resource/v.mp4"></video></p>';
$h=Document::rewrite(['template'=>['settings'=>['html'=>$html]],'pages'=>[]],fn($url)=>'https://cdn.example.test'.$url);
check(str_contains($h['template']['settings']['html'],'href="https://example.com/b.png"'),'anchor href unchanged');check(substr_count($h['template']['settings']['html'],'https://cdn.example.test')===6,'HTML src/srcset/poster/style all rewritten');
$seenHtml=[];
$edge=Document::rewrite(['template'=>['settings'=>['html'=>'<img alt="a>b" src=/resource/a.png><div style="background-image:url(&quot;/resource/bg.png&quot;)"></div><img srcset="/resource/a.png,/resource/b.png 2x">']],'pages'=>[]],function($url)use(&$seenHtml){$seenHtml[]=$url;return 'https://edge.test'.$url;});
check($seenHtml===['/resource/a.png','/resource/bg.png','/resource/a.png','/resource/b.png'],'DOM quoted> / unquoted src / entity CSS / comma srcset tokenized');
check(str_contains($edge['template']['settings']['html'],'a&gt;b')||str_contains($edge['template']['settings']['html'],'a>b'),'HTML alt semantics retained');
check(Document::rewrite(['template'=>['settings'=>['path'=>'/pages/foo','color'=>'#fff','icon'=>'i-home','text'=>'hello.png']],'pages'=>[]],fn()=>throw new LogicException('non asset transformed'))['template']['settings']['text']==='hello.png','text/color/font icons/routes preserved');
$catalog=json_decode(file_get_contents($root.'/app/common/service/decorate/agent/catalog/mobile-catalog.json'),true);$leaves=[];
$collect=function($node)use(&$collect,&$leaves){if(!is_array($node))return;foreach($node as $key=>$child){if(is_array($child)&&in_array($child['type']??null,['image','video','audio'],true))$leaves[$key]=true;$collect($child);}};$collect($catalog);
foreach(array_keys($leaves) as $field){$count=0;Document::rewrite(['template'=>['settings'=>[$field=>'/resource/catalog.png']],'pages'=>[]],function($url)use(&$count){++$count;return $url;});check($count===1,'catalog media leaf covered: '.$field);}
rejects(fn()=>Document::rewrite(['pages'=>[['data'=>'invalid JSON']]],fn($x)=>$x),'malformed embedded JSON rejected');
rejects(fn()=>Media::localPath('/uploads/../secret','/tmp'),'path traversal denied');
rejects(fn()=>Media::localPath('/resource/%2e%2e/secret','/tmp'),'encoded traversal denied');
rejects(fn()=>Media::localPath('/resource\\secret','/tmp'),'alternate separator denied');
rejects(fn()=>Media::validate('<html>error</html>'),'HTML cannot masquerade as image');
rejects(fn()=>Media::validate('<?php echo 1;'),'PHP cannot masquerade as image');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1sAAAAASUVORK5CYII=');
[$bytes,$mime,$ext]=Media::validate($png);check($mime==='image/png'&&$ext==='png'&&$bytes===$png,'real PNG content accepted');
[$bytes,$mime,$ext]=Media::validate('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>');check($mime==='image/svg+xml'&&!str_contains($bytes,'script'),'SVG sanitized before internal upload');
rejects(fn()=>Media::validate('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'),'SVG entity injection rejected');
$identity=new ReflectionMethod(app\common\service\decorate\agent\DecorationAgentAssetReferences::class,'storageAsset');
$asset=['storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''];
$prefix='uploads/decoration/17/'.str_repeat('a',24).'/'.str_repeat('b',64);
foreach(['svg','avif','ico'] as $extension){
    $candidate=$asset+['image_uri'=>$prefix.'.'.$extension];
    check($identity->invoke(null,$candidate,17)!==null,'validated portable '.$extension.' identity accepted for owning tenant');
    check($identity->invoke(null,$candidate,18)===null,'portable '.$extension.' identity cannot cross tenants');
    check($identity->invoke(null,$candidate)===null,'non-file claims cannot gain portable '.$extension.' identity');
    check($identity->invoke(null,$asset+['image_uri'=>'uploads/legacy.'.$extension],17)===null,'ordinary '.$extension.' identity whitelist unchanged');
}
check($identity->invoke(null,$asset+['image_uri'=>$prefix.'.mp4'],17)===null,'portable non-image identity rejected');
check($identity->invoke(null,$asset+['image_uri'=>str_replace(str_repeat('a',24),'short',$prefix).'.svg'],17)===null,'portable malformed namespace identity rejected');
require $root.'/app/common/service/open_platform/OpenException.php';require $root.'/app/common/service/open_platform/OpenPolicy.php';require $root.'/app/common/service/open_platform/OpenSafeHttp.php';
foreach(['http://127.0.0.1/a.png','http://10.0.0.1/a.png','http://169.254.169.254/latest','http://[::1]/a.png','file:///etc/passwd','https://user:secret@example.com/a.png','https://example.com:8443/a.png'] as $url)rejects(fn()=>app\common\service\open_platform\OpenSafeHttp::resolve($url,false),'SSRF/credentials/nonstandard port denied');
echo "PASS portable document/media/security: $checks checks\n";
