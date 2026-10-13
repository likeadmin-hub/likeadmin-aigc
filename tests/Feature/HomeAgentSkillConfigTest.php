<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\AgentSkillConfigService as Skills;
use app\common\service\app\aigc_llm\AigcLlmService as Llm;
use app\common\service\app\AppDisplayConfigService as Display;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();try{
 $t=1999999906;
 Display::save($t,'aigc_fitting',['cover_uri'=>'https://example.test/cover.png','skill_preview_images'=>['https://example.test/old.png']]);
 $items=array_column(Skills::detail($t),null,'id');check(count($items)===7&&$items['fitting']['images']===['https://example.test/old.png'],'Legacy media/defaults missing');
 Llm::saveConfig($t,['status'=>1,'config_json'=>['system_prompt'=>'preserve me']]);
 Llm::saveConfig($t,['skill_configs'=>[['id'=>'fitting','name'=>'试穿效果','enabled'=>0,'sort'=>30,'prompt'=>'请先确认服装和模特','materials'=>'两张图','images'=>[],'videos'=>['https://example.test/demo.mp4']],['id'=>'document','images'=>['https://example.test/doc.png']]]]);
 $items=array_column(Skills::detail($t),null,'id');check($items['fitting']['name']==='试穿效果'&&$items['fitting']['enabled']===0&&$items['fitting']['images']===[]&&$items['fitting']['cover_url']==='https://example.test/cover.png','Saved setting/clear/cover failed');
 check($items['document']['images']===['https://example.test/doc.png']&&$items['script']['images']===[],'Text skill settings not independent');
 check(Llm::config($t)['config_json']['system_prompt']==='preserve me','Skills replaced base config');
 Llm::saveConfig($t,['config_json'=>['system_prompt'=>'changed base']]);check(array_column(Skills::detail($t),null,'id')['fitting']['name']==='试穿效果','Base save erased skills');
 check(array_column(Skills::detail($t+1),null,'id')['fitting']['videos']===[],'Tenant leak');
 check(count(Skills::forTools($t,['aigc_fitting']))===4,'Unauthorized tool settings exposed');
 foreach([[['id'=>'unknown']],[['id'=>'fitting','tool'=>'aigc_video']],[['id'=>'fitting','images'=>['javascript:alert(1)']]],[['id'=>'fitting','prompt'=>'']],[['id'=>'image'],['id'=>'image']]] as $bad){try{Llm::saveConfig($t,['skill_configs'=>$bad]);throw new LogicException('Invalid skill accepted');}catch(InvalidArgumentException){}}
 check(array_column(Skills::detail($t),null,'id')['fitting']['name']==='试穿效果','Invalid save changed settings');
 echo "Central Agent skills passed: seven defaults, legacy media, independent text skills, base preservation, ownership and immutable tools; rolled back\n";
}finally{Db::rollback();}
