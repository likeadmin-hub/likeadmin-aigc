<?php
/** Export an explicitly selected published preset as source JSON. */
require dirname(__DIR__).'/vendor/autoload.php'; (new think\App())->initialize();
use app\common\service\app\aigc_canvas\CanvasBuiltinPresetExporter;
set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
$options=getopt('',['tenant:','template:','id:','title:','version:','write']);
if (PHP_SAPI!=='cli' || empty($options['tenant']) || empty($options['template']) || !preg_match('/^builtin-[a-z0-9_-]{1,56}$/D',$options['id'] ?? '')) {
    fwrite(STDERR,"Usage: php scripts/export-canvas-builtin-preset.php --tenant=1 --template=CARD_ID --id=builtin-slug [--title=TITLE] [--version=1] [--write]\nWithout --write only checks the selected snapshot. Writes source JSON to runtime/canvas-presets/; never modifies tenant catalog or source files.\n");exit(1);
}
request()->tenantId=(int)$options['tenant'];request()->source=app\common\enum\AdminTerminalEnum::TENANT;
$result=CanvasBuiltinPresetExporter::exportPublished((int)$options['tenant'],$options['template'],$options['id'],$options['title'] ?? null,(int)($options['version'] ?? 1),isset($options['write']));
if (!isset($options['write'])) {echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit;}
$directory=root_path().'runtime/canvas-presets';if(!is_dir($directory))mkdir($directory,0770,true);
$path=$directory.'/'.$result['filename'];
if (file_put_contents($path,json_encode($result['preset'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n")===false) throw new RuntimeException('无法写入导出文件');
echo "Exported source definition: $path (".$result['media_count']." object-storage files)\n";
