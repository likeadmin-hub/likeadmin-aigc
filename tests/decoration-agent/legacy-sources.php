<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
use app\common\service\decorate\DecorateDataSourceService as Sources;
function sourcePage(array $content): array {
    return Sources::applyToPage(['data'=>json_encode([['name'=>'news','content'=>$content]]),'meta'=>'[]','page_code'=>'source-regression','terminal'=>'mobile'],['tenant_id'=>1,'user_id'=>0,'terminal'=>'mobile','preview'=>true]);
}
function sourceCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$legacy=['source'=>['key'=>'video_cases','params'=>['limit'=>1]],'future_field'=>'preserved'];
$page=sourcePage($legacy);$widget=json_decode($page['data'],true)[0];
sourceCheck($widget['content']['source_key']==='video_cases','Legacy source was overwritten by component defaults');
sourceCheck($widget['content']['source_params']===['limit'=>1],'Legacy source params were not promoted');
sourceCheck(isset($page['resolved_sources']['video_cases'])&&!isset($page['resolved_sources']['article']),'Real resolver read wrong source');
sourceCheck(count($page['resolved_sources']['video_cases']['items'])<=1,'Resolved source did not honor limit');
sourceCheck($widget['content']['future_field']==='preserved','Unknown historical content was discarded');
$canonical=$legacy+['source_key'=>'article','source_params'=>['limit'=>2]];
$page=sourcePage($canonical);$widget=json_decode($page['data'],true)[0];
sourceCheck($widget['content']['source_key']==='article'&&$widget['content']['source_params']===['limit'=>2],'Explicit canonical fields lost precedence');
sourceCheck(isset($page['resolved_sources']['article'])&&!isset($page['resolved_sources']['video_cases']),'Canonical source read stale alias');
$cleared=$legacy+['source_key'=>'','source_params'=>[],'data_mode'=>'hybrid','fallback_data'=>[['title'=>'备用说明']]];
$page=sourcePage($cleared);$widget=json_decode($page['data'],true)[0];
sourceCheck($widget['content']['source_key']===''&&$page['resolved_sources']===[],'Cleared canonical source fetched a legacy/default source');
sourceCheck($widget['content']['fallback_data']===[['title'=>'备用说明']],'Clearing source lost fallback content');
$page=sourcePage([]);$widget=json_decode($page['data'],true)[0];
sourceCheck($widget['content']['source_key']==='article'&&isset($page['resolved_sources']['article']),'Missing-field published legacy defaults regressed');
echo "legacy sources: 10 checks passed, real read-only source resolution\n";
