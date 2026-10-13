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
 $payload=(new \app\Request())->withPost(['skill_configs'=>[['id'=>'fitting','enabled'=>1,'sort'=>12,'preview_type'=>'video','videos'=>['https://example.test/clip.mp4'],'video_poster'=>'https://example.test/loading.jpg','images'=>['https://example.test/unused.jpg']]]])->post();
 check($payload['skill_configs'][0]['sort']==='12','Request filter reproduction missing');Llm::saveConfig($t,$payload);
 $preview=array_column(Skills::detail($t),null,'id')['fitting'];check($preview['sort']===12&&$preview['enabled']===1&&$preview['preview_type']==='video'&&$preview['images']===[]&&$preview['video_poster_url']==='https://example.test/loading.jpg','Filtered save/video poster failed');
 Llm::saveConfig($t,['skill_configs'=>[['id'=>'fitting','preview_type'=>'image','images'=>['https://example.test/photo.jpg']]]]);$preview=array_column(Skills::detail($t),null,'id')['fitting'];check($preview['videos']===[]&&$preview['video_poster']==='','Image/video exclusion failed');
 foreach([[['id'=>'fitting','preview_type'=>'video','videos'=>[]]],[['id'=>'fitting','video_poster'=>'javascript:alert(1)']],[['id'=>'fitting','sort'=>'1.2']],[['id'=>'fitting','enabled'=>'yes']]] as $bad){try{Llm::saveConfig($t,['skill_configs'=>$bad]);throw new LogicException('Invalid media/numeric accepted');}catch(InvalidArgumentException){}}
 Llm::saveConfig($t,['skill_create'=>['name'=>'商品试穿','app_code'=>'aigc_fitting','prompt'=>'请生成试穿图','images'=>[]]]);
 $custom=array_values(array_filter(Skills::detail($t),fn($skill)=>!$skill['builtin']));check(count($custom)===1&&$custom[0]['tool']==='aigc_fitting','Custom create failed');$id=$custom[0]['id'];
 check(!in_array($id,array_column(Skills::forTools($t,[]),'id'),true),'Custom skill bypasses tool authorization');
 try{Llm::saveConfig($t+1,['skill_configs'=>[['id'=>$id,'enabled'=>0]]]);throw new LogicException('Cross tenant update accepted');}catch(InvalidArgumentException){}
 Llm::saveConfig($t,['skill_configs'=>[['id'=>$id,'enabled'=>0]]]);check(array_column(Skills::detail($t),null,'id')[$id]['enabled']===0,'Toggle failed');
 Llm::saveConfig($t,['skill_configs'=>[['id'=>$id,'name'=>'改名','app_code'=>'aigc_llm']]]);$edited=array_column(Skills::detail($t),null,'id')[$id];check($edited['name']==='改名'&&!isset($edited['tool'])&&$edited['enabled']===0,'Edit replaced unrelated fields or retained old tool');
 Llm::saveConfig($t,['skill_delete_id'=>$id]);check(!in_array($id,array_column(Skills::detail($t),'id'),true),'Custom delete failed');
 Llm::saveConfig($t,['skill_delete_id'=>'image']);Llm::saveConfig($t,['skill_configs'=>[['id'=>'plan','sort'=>20]]]);Llm::saveConfig($t,['config_json'=>['system_prompt'=>'after delete']]);check(!in_array('image',array_column(Skills::detail($t),'id'),true),'Deleted built-in resurrected after another save');
 foreach([['skill_create'=>['name'=>'不允许','prompt'=>'test','app_code'=>'unknown']],['skill_create'=>['name'=>'','prompt'=>'test','app_code'=>'aigc_llm']],['skill_configs'=>[['id'=>'image','enabled'=>1]]],['skill_delete_id'=>'unknown'],['skill_create'=>[],'skill_delete_id'=>'plan']] as $bad){try{Llm::saveConfig($t,$bad);throw new LogicException('Invalid CRUD accepted');}catch(InvalidArgumentException){}}
 $store=\app\common\service\app\aigc_llm\agent\AgentStore::class;
 $prefs=$store::preferences($t,1999999906,['favorite_skill'=>['id'=>'plan','favorite'=>'1']]);check($prefs['skill_favorites']===['plan'],'Favorite not saved');
 $prefs=$store::preferences($t,1999999906,['mode'=>'auto','budget'=>10,'models'=>['image'=>'chosen']]);check($prefs['skill_favorites']===['plan'],'Preference save erased favorites');
 $prefs=$store::preferences($t,1999999906,['favorite_skill'=>['id'=>'plan','favorite'=>'1']]);check($prefs['mode']==='auto'&&$prefs['models']['image']==='chosen'&&count($prefs['skill_favorites'])===1,'Favorite changed preferences or duplicated');
 check(empty($store::preferences($t,1999999907)['skill_favorites'])&&empty($store::preferences($t+1,1999999906)['skill_favorites']),'Favorite ownership leak');
 try{$store::preferences($t,1999999906,['favorite_skill'=>['id'=>'missing','favorite'=>'1']]);throw new LogicException('Unknown skill favorited');}catch(InvalidArgumentException){}
 check($store::preferences($t,1999999906,['favorite_skill'=>['id'=>'plan','favorite'=>'0']])['skill_favorites']===[],'Unfavorite failed');
 echo "Central Agent skills passed: seven defaults, legacy media, independent text skills, base preservation, ownership and immutable tools; rolled back\n";
}finally{Db::rollback();}
