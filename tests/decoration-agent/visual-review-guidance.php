<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentDecision as D};
$n=0;function guidanceCheck(bool $ok,string $label): void {global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$method=new ReflectionMethod(S::class,'visualReviewContext');$method->setAccessible(true);
$context=['visual_review'=>['screenshot_width'=>750,'screenshot_height'=>1446],'approved_design'=>['run_id'=>99,'revision'=>3,'hash'=>str_repeat('a',64),'asset'=>['width'=>768,'height'=>1376],'blueprint'=>['regions'=>[['components'=>[['name'=>'title-bar','content'=>['font_size'=>14]]]]]]]];
$guide=$method->invoke(null,$context);
guidanceCheck($guide['image_dimensions']['logical_width_px']===375&&$guide['image_dimensions']['approved_design']['width_px']===768&&$guide['image_dimensions']['actual_capture']['width_px']===750,'uses authoritative raster dimensions rather than blueprint layout numbers');
guidanceCheck($guide['image_dimensions']['approved_design']['to_logical_px_scale']===375/768&&$guide['image_dimensions']['actual_capture']['to_logical_px_scale']===0.5,'normalizes each real image independently onto the logical mobile width');
guidanceCheck($guide['target']['revision']===3&&$guide['target']['hash']===$context['approved_design']['hash']&&!isset($guide['target']['blueprint']),'visual target is exact latest approved image identity, not prior proposed parameter values');
guidanceCheck(str_contains($guide['priority'],'批准图片优先')&&str_contains($guide['priority'],'不是视觉批准规范'),'prior blueprint and historical statements cannot override the visual reference');
guidanceCheck(count($guide['region_checks'])>=5&&str_contains(implode(' ',$guide['region_checks']),'可见bbox'),'requires region-level geometry, typography and visible glyph bounds evidence');
$unknown=$context;unset($unknown['approved_design']['asset']['width']);guidanceCheck($method->invoke(null,$unknown)['image_dimensions']['approved_design']['to_logical_px_scale']===null,'unknown image width never creates invented measurement');
guidanceCheck($method->invoke(null,[])===null,'ordinary conversations gain no visual-review target');
$prompt=D::prompt();guidanceCheck(str_contains($prompt,'批准图片优先')&&str_contains($prompt,'估算')&&str_contains($prompt,'旧蓝图'),'decision prompt explicitly treats measurements as estimates and prior parameters as subordinate');
guidanceCheck(str_contains($prompt,'可见bbox')&&str_contains($prompt,'独立图标')&&str_contains($prompt,'重新截图'),'review checks real visible image/glyph composition and requires a new screenshot after repair');
guidanceCheck(str_contains($prompt,'每轮')&&str_contains($prompt,'修复final也必须返回acceptance')&&!str_contains($prompt,'只有未发生任何编辑/生图、各类证据充分时才返回acceptance'),'every repair round retains six-category diagnosis instead of suppressing acceptance');
$checks=array_map(fn($category)=>['category'=>$category,'passed'=>false,'detail'=>'本轮输入旧截图的target/actual存在差异；本轮修复效果尚未验证。','evidence'=>['对应区域的具体target/actual对照']],['layout','typography','color','assets','icons','interaction']);
$diagnosis=['passed'=>false,'checks'=>$checks,'summary'=>'本轮只诊断输入原图，修复后需重新截图'];
$parsed=D::parse(S::json(['reply'=>'已生成修复候选，效果待截图验证','kind'=>'final','operations'=>[['type'=>'configure_page','fields'=>['content.show_nav'=>0]]],'acceptance'=>$diagnosis]));
guidanceCheck($parsed['acceptance']===$diagnosis&&$parsed['operations'][0]['type']==='configure_page','decision parser preserves complete explicit-false input-image diagnosis alongside repair operations');
echo "visual review guidance: $n checks passed (real context/prompt services, no DB/provider writes)\n";
