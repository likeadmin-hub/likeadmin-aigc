<?php
/** Free estimates and admission budgets using owned fixtures. Never executes a task. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenToolRegistry as R;
use app\common\service\open_platform\OpenToolAdapter as A;
use app\common\service\open_platform\OpenMaterials as M;
use app\common\service\open_platform\OpenPolicy as P;
$db=config('database.connections.mysql');if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')throw new RuntimeException('Local DB only');
$user=Db::name('user')->where(['id'=>1,'tenant_id'=>1,'account'=>'xhadmin001'])->find();if(!$user)throw new RuntimeException('Authorized test identity missing');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$lock=fopen($dir.'/paid.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Acceptance lock busy');
$fixtures=S::json(file_get_contents($dir.'/local_text_01400e0a404f.json'))['assets']??[];
$image=$fixtures['image']['file_id']??'';$video=$fixtures['video']['file_id']??'';
if($image===''||$video==='')throw new RuntimeException('Owned authorized synthetic fixtures missing');
request()->tenantId=1;request()->userId=1;request()->source=app\common\enum\AdminTerminalEnum::USER;request()->userInfo=['user_id'=>1,'tenant_id'=>1,'terminal'=>4];
$wallet=(float)$user['user_money'];$tenantWallet=(float)Db::name('tenant')->where('id',1)->value('point_balance');$results=[];
Db::startTrans();try{
 foreach([0,1] as $tenant){$settings=S::settings($tenant);unset($settings['enabled']);$settings['apps']=array_keys(R::all());$row=S::table('setting')->where('tenant_id',$tenant)->find();$values=['enabled'=>1,'config'=>S::encode($settings),'update_time'=>time()];if($row)S::table('setting')->where('id',$row['id'])->update($values);else S::table('setting')->insert($values+['tenant_id'=>$tenant,'create_time'=>time()]);}
 S::requireEnabled(1);
 $common=['source_image'=>$image,'product_image'=>$image,'product_images'=>[$image],'source_images'=>[$image],'reference_images'=>[$image],'model_image'=>$image,'wear_image'=>$image,'person_image'=>$image,'reference_image'=>$image,'clothes_images'=>[$image],'garment_images'=>[$image],'model_images'=>[$image],'mask_image'=>$image,'prompt'=>'Synthetic bottle fixture on a plain background.','user_prompt'=>'Preserve the synthetic fixture.','channel'=>'market_image_model:154','quality'=>'1k','ratio'=>'1:1','quantity'=>1,'duration'=>15];
 $overrides=[
  'aigc_video'=>['channel'=>'market_video_model:157','quality'=>'720p','duration'=>6],
  'aigc_action_transfer'=>['video_uri'=>$video],
  'aigc_person_replacement'=>['video_uri'=>$video],
  'aigc_image_translate'=>['target_language'=>'en'],
  'aigc_product_suite'=>['module_codes'=>['hero','detail'],'language'=>'none'],
  'aigc_product_multi_angle'=>['view_codes'=>['front']],
  'aigc_one_click_cleanup'=>['option_codes'=>['watermark']],
  'aigc_product_image'=>['scene_mode'=>'custom','custom_scene_image'=>$image],
  'aigc_watermark_removal'=>['url'=>$video],
  'smart_clip'=>['api'=>'realman_broadcast','videoUrl'=>$video,'styleId'=>'6a2668a137004e003477b6ed'],
 ];
 foreach(R::all() as $app=>$tool){$entry=['app_code'=>$app,'name'=>$tool['name'],'acceptance'=>'not_submitted'];try{
   A::authorize(1,1,$app);$estimate=$tool['operations']['POST estimate']??null;
   if(!$estimate){$entry+=['estimate_status'=>'unavailable','reason'=>'No registered native estimate; requires published bounded usage contract'];}
   else{$params=array_replace($common,$overrides[$app]??[]);if(in_array($app,['aigc_digital_human','aigc_music','smart_clip','aigc_pic_lipsync','image_human'],true))foreach(['channel','quality','ratio'] as $field)unset($params[$field]);$params=M::resolve(1,1,M::nativeInputs($app,$params));$quote=A::call(request(),$estimate+['app_code'=>$app,'route'=>'POST estimate','params'=>[]],$params);$entry+=['estimate_status'=>'passed','quote'=>P::publicData($quote),'budget_bounded'=>A::estimateBudget($quote)!==null];
     // An estimate is not proof that native input, SKU and reference limits
     // permit submission. Probe the exact budget branch without admission,
     // reservation, execution, publication or a generation transport call.
     $submit=$tool['operations']['POST tasks']??null;
     if(!$submit)$entry+=['admission_status'=>'unavailable','admission_reason'=>'No registered native task submission'];
     else try{$budget=A::budget(request(),$submit+['app_code'=>$app,'route'=>'POST tasks','params'=>[]],$params);$entry+=['admission_status'=>'passed','admission_budget'=>['user'=>(float)$budget['user']],'admission_scope'=>'budget_preflight_only'];}
     catch(Throwable $error){$entry+=['admission_status'=>'blocked','admission_reason'=>$error->getMessage()];}
   }
  }catch(Throwable $error){$entry+=['estimate_status'=>'blocked','reason'=>$error->getMessage()];}
  $results[]=$entry;echo S::encode(['app_code'=>$app,'estimate_status'=>$entry['estimate_status'],'reason'=>$entry['reason']??'','admission_status'=>$entry['admission_status']??'not_probed','admission_reason'=>$entry['admission_reason']??'']),"\n";
 }
 if((float)Db::name('user')->where('id',1)->value('user_money')!==$wallet||(float)Db::name('tenant')->where('id',1)->value('point_balance')!==$tenantWallet)throw new RuntimeException('Free estimate changed a wallet');
 $report=['generated_at'=>date(DATE_ATOM),'tenant_id'=>1,'user_id'=>1,'tools'=>$results,'generation_submissions'=>0,'wallets_unchanged'=>true,'fixture_writes'=>'rolled_back'];
 file_put_contents($dir.'/tool-readiness.json',S::encode($report));echo "PASS 27 free estimate and native budget probes recorded, no generation submitted, both wallets unchanged, temporary settings rolled back\n";
}finally{Db::rollback();}
