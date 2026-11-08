<?php
/** Disposable MySQL only. Real prices, wallets and duration probes; no paid generation. */
declare(strict_types=1);
if (getenv('AVATAR_BILLING_DISPOSABLE') !== '1' || getenv('PHP_DATABASE_HOSTNAME') !== 'avatar-billing-mysql'
    || getenv('PHP_DATABASE_DATABASE') !== 'avatar_billing_test') throw new RuntimeException('Disposable avatar fixture required');
require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App(dirname(__DIR__)))->setBaseEnvName('avatar-disposable')->setEnvName('avatar-disposable')->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,(string)$e."\n");exit(1);});
use think\facade\Db;
use app\common\service\power\MarketPicLipsyncAppRuntimeService as Pic;
use app\common\service\power\MarketLipsyncAppRuntimeService as Lipsync;
use app\common\service\app\aigc_digital_human\DigitalHumanMarketService as Quotes;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService as Digital;
use app\common\service\app\image_human\ImageHumanService as Full;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function privateCall(string $class, string $method, ...$args): mixed { return (new ReflectionMethod($class, $method))->invoke(null, ...$args); }
check((Db::query('SELECT DATABASE() db')[0]['db'] ?? '') === 'avatar_billing_test', 'Database mismatch');
\app\common\service\database\SqlMigrationExecutor::execute(file_get_contents(dirname(__DIR__).'/upgrade/zzzz_20261108_avatar_billing_quantity.sql'),'la_');
$failures=[];
function test(string $name, callable $operation): void { global $failures; try { $operation(); echo "PASS $name\n"; } catch (Throwable $e) { $failures[]=$name; fwrite(STDERR,"FAIL $name: {$e->getMessage()}\n"); } }
Db::startTrans();
$wav=public_path().'uploads/avatar-billing-'.bin2hex(random_bytes(5)).'.wav';
try {
    @mkdir(dirname($wav),0700,true);
    $samples=23040; // 2.88 seconds, 8 kHz, mono 16 bit PCM.
    $data=str_repeat("\0",$samples*2);
    file_put_contents($wav,'RIFF'.pack('V',36+strlen($data)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($data)).$data);
    $tenant=Db::name('tenant')->insertGetId(['sn'=>'avatarfixture','name'=>'Avatar fixture','point_balance'=>10000,'create_time'=>time()]);
    $user=Db::name('user')->insertGetId(['tenant_id'=>$tenant,'sn'=>987654321,'account'=>'avatar-fixture','user_money'=>10000,'create_time'=>time(),'update_time'=>time()]);
    request()->tenantId=$tenant; request()->userId=$user;
    $options=[];
    foreach (['pic_lipsync','lipsync'] as $app) {
        $product=Db::name('power_market_product')->insertGetId(['product_code'=>'avatar-'.$app,'name'=>'Avatar fixture','source_code'=>'avatar-fixture','upstream_resource_key'=>$app,'resource_type'=>'app_api','upstream_app_code'=>$app,'upstream_api_code'=>'submit','status'=>1]);
        $sku=Db::name('power_market_sku')->insertGetId(['product_id'=>$product,'sku_key'=>'fixture','title'=>'Fixture MAX','locked_params'=>json_encode($app==='pic_lipsync'?['quality'=>'max']:['model'=>'fixture-lipsync']),'usage_unit'=>'input_second','usage_unit_size'=>1,'upstream_price'=>4,'sale_points'=>4,'status'=>1,'sale_status'=>1]);
        Db::name('tenant_power_market_sku_price')->insert(['tenant_id'=>$tenant,'sku_id'=>$sku,'sale_points'=>5,'sale_status'=>1]);
        Db::name('power_market_price_rule')->insert(['owner_id'=>$tenant,'scope_type'=>'sku','scope_id'=>$sku,'mode'=>'manual','value'=>5]);
        $options[$app]=['market_product_id'=>$product,'market_sku_id'=>$sku];
    }
    $wallets=fn()=>[(float)Db::name('tenant')->where('id',$tenant)->value('point_balance'),(float)Db::name('user')->where('id',$user)->value('user_money')];
    test('fractional digital-human quote', function() use($tenant) {
        $q=Quotes::estimate($tenant,['duration'=>2.88]);
        check(abs((float)$q['billable_quantity']-2.88)<0.000001 && (float)$q['tenant_cost_points']===11.52,'2.88 seconds quote lost precision');
    });
    test('generation audio measurement preserves fractional seconds',function()use($wav,$tenant){
        $seconds=privateCall(Digital::class,'detectGenerateAudioDurationFromUri','uploads/'.basename($wav),$tenant);
        check(abs($seconds-2.88)<0.000001,'Measured 2.88 seconds became '.(string)$seconds);
    });
    test('digital-human continuation reserves measured fractions and rejects unknown audio',function()use($wav,$tenant,$user,$wallets){
        // Removing the disposable fixture's source prevents any upstream transport;
        // submit then exercises the normal failure/refund after the real reservation.
        Db::name('update_source')->where('id','>',0)->delete();
        $selection=Quotes::selection($tenant,[]);$estimate=Quotes::estimate($tenant,['duration'=>2.88]);$before=$wallets();
        $make=fn(string $uri)=>\app\common\model\app\aigc_digital_human\AigcDigitalHumanTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'duration'=>10,'status'=>'running','provider_stage'=>'lipsync_submitted','tts_audio_uri'=>$uri,'provider_payload_json'=>['market_selection'=>$selection,'market_estimate'=>$estimate]]);
        $task=$make('uploads/'.basename($wav));
        $request=new \app\common\service\app\aigc_digital_human\AigcDigitalHumanGenerateRequest('','',avatar:['media_url'=>'https://fixture.invalid/avatar.mp4'],providerParams:['audio_url'=>'https://fixture.invalid/input.wav']);
        privateCall(Digital::class,'advanceMarketLipsync',$task,$request,$selection,$estimate);
        $bill=Db::name('ai_consumption_log')->where(['app_code'=>'aigc_digital_human'])->order('id','desc')->find();
        check($bill!==null && (float)$bill['quantity']===2.88,'Generation continuation truncated measured quantity');
        check($bill['billing_status']==='refunded' && $wallets()===$before,'Unconfigured upstream did not refund exact reservation');
        $count=Db::name('ai_consumption_log')->count();$unknown=$make('uploads/missing-avatar.wav');
        privateCall(Digital::class,'advanceMarketLipsync',$unknown,$request,$selection,$estimate);
        check(Db::name('ai_consumption_log')->count()===$count && $wallets()===$before,'Unknown audio used task estimate as real billable seconds');
    });
    test('pic upload metadata zero probes bytes and ignores client placeholder',function()use($wav,$tenant,$user,$options){
        $avatar=Db::name('image_human_avatar')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'source'=>'mine','image_uri'=>'uploads/fixture.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'https://fixture.invalid']);
        $audio=Db::name('aigc_music_asset')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'asset_type'=>'pic_lipsync_audio','uri'=>'uploads/'.basename($wav),'duration'=>0,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'https://fixture.invalid']);
        $prepared=privateCall(\app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService::class,'prepare',$tenant,['avatar_id'=>$avatar,'audio_asset_id'=>$audio,'quality'=>'max','duration'=>10],true,$user);
        check($prepared['duration']===2.88,'Unknown metadata adopted client placeholder instead of probing bytes');
        $r=Pic::reserve($tenant,$user,'generate','pic-measured-upload',$options['pic_lipsync'],$prepared['request'],$prepared['duration']);
        privateCall(Pic::class,'settle',$r['consumption_id'],[['video_uri'=>'fixture.mp4']],'fixture','fixture',[]);
        check((float)Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->value('quantity')===2.88,'Measured upload did not reach final settlement');
    });
    foreach (['pic_lipsync'=>Pic::class,'lipsync'=>Lipsync::class] as $app=>$runtime) test($app.' fractional settlement, snapshot and replay',function()use($tenant,$user,$options,$wallets,$app,$runtime){
        $before=$wallets();$option=$options[$app];
        $request=$app==='pic_lipsync'?['image_url'=>'https://fixture.invalid/image.png','audio_url'=>'https://fixture.invalid/input.wav','quality'=>'max']:['video_url'=>'https://fixture.invalid/avatar.mp4','audio_url'=>'https://fixture.invalid/input.wav'];
        $key='settle-'.$app;
        $reserve=$runtime::reserve($tenant,$user,'generate',$key,$option,$request,10,'aigc_'.$app);
        check($wallets()===[$before[0]-40,$before[1]-50],'10 seconds did not reserve 40/50');
        check($runtime::reserve($tenant,$user,'generate',$key,$option,$request,10,'aigc_'.$app)['consumption_id']===$reserve['consumption_id'],'Reservation replay duplicated task');
        Db::name('power_market_sku')->where('id',$option['market_sku_id'])->update(['sale_points'=>99]);
        privateCall($runtime,'settle',$reserve['consumption_id'],[['video_uri'=>'fixture.mp4']],'fixture','fixture',['data'=>['usage'=>['input_second'=>2.88]],'duration'=>99]);
        $bill=Db::name('ai_consumption_log')->where('id',$reserve['consumption_id'])->find();
        check((float)$bill['quantity']===2.88 && (float)$bill['actual_tenant_cost']===11.52 && (float)$bill['actual_user_price']===14.4,'Final bill did not use 2.88 input seconds and frozen prices');
        check($wallets()===[round($before[0]-11.52,2),round($before[1]-14.4,2)],'Final wallet charge differs');
        $after=$wallets();privateCall($runtime,'settle',$reserve['consumption_id'],[['video_uri'=>'fixture.mp4']],'fixture','fixture',['input_second'=>10]);
        $runtime::fail($reserve['consumption_id'],'late failure');check($wallets()===$after,'Terminal replay charged/refunded again');
        Db::name('power_market_sku')->where('id',$option['market_sku_id'])->update(['sale_points'=>4]);
        $failed=$runtime::reserve($tenant,$user,'generate','fail-'.$app,$option,$request,2.88,'aigc_'.$app);
        $runtime::fail($failed['consumption_id'],'fixture failure');$runtime::fail($failed['consumption_id'],'fixture failure');
        check($wallets()===$after,'Failure refund did not restore exact fractional reservation');
        $rejected=false;try{$runtime::reserve($tenant,$user,'generate','unknown-'.$app,$option,$request,0,'aigc_'.$app);}catch(Exception){$rejected=true;}
        check($rejected && $wallets()===$after,'Unknown duration reserved or charged');
    });
    test('image-human unknown driver audio fails closed',function()use($tenant){
        $rejected=false;try{Full::estimate($tenant,['driver_audio_uri'=>'uploads/missing-avatar-audio.wav','duration'=>10]);}catch(Exception){$rejected=true;}
        check($rejected,'Client placeholder 10 seconds became an unknown-audio quote');
    });
    test('image-human completion uses admitted unit prices and settles once',function()use($tenant,$user,$wallets){
        $before=$wallets();
        $snapshot=['version'=>1,'estimate'=>['platform_unit_cost'=>4,'tenant_unit_price'=>5,'billing_unit'=>'second','duration'=>2.88]];
        $task=\app\common\model\app\image_human\ImageHumanTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'mode'=>'fast','duration'=>2.88,'tenant_cost_points'=>11.52,'user_charge_points'=>14.4,'status'=>'running','provider_stage'=>'storing','provider_payload_json'=>['generation_billing'=>$snapshot]]);
        Db::name('image_human_config')->where('tenant_id',0)->delete();
        Db::name('image_human_config')->insert(['tenant_id'=>0,'config_json'=>json_encode(['pricing'=>['platform_unit_cost'=>99,'tenant_unit_price'=>199]])]);
        $video=[['uri'=>'uploads/fixture.mp4','duration'=>2.88]];
        privateCall(Full::class,'finishTaskWithVideos',$task,$video);
        $bill=Db::name('image_human_billing')->where('task_id',$task['id'])->find();
        check($bill!==null && (float)$bill['platform_unit_cost']===4.0 && (float)$bill['tenant_unit_price']===5.0,'Completion replaced admitted units with mutable prices');
        check($wallets()===[round($before[0]-11.52,2),round($before[1]-14.4,2)],'Full generation wallet charge changed');
        $after=$wallets();privateCall(Full::class,'finishTaskWithVideos',$task,$video);check($wallets()===$after,'Full generation completion replay charged twice');
    });
    test('digital-human business ledger retains fractional settled quantity',function()use($tenant,$user,$wallets){
        $before=$wallets();
        $task=\app\common\model\app\aigc_digital_human\AigcDigitalHumanTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'duration'=>3,'status'=>'running','provider_stage'=>'market_lipsync']);
        $estimate=['market_settled'=>true,'market_consume_no'=>'fixture-already-settled','billing_unit'=>'input_second','billable_quantity'=>2.88,'duration'=>3,'platform_unit_cost'=>4,'tenant_unit_price'=>5,'tenant_cost_points'=>11.52,'user_charge_points'=>14.4];
        $selection=['channel'=>['code'=>'fixture'],'spec'=>['quality'=>'source','ratio'=>'adaptive']];
        privateCall(Digital::class,'finishTaskWithVideos',$task,['id'=>0],['id'=>0],$selection,$estimate,[['uri'=>'uploads/fixture.mp4']]);
        $bill=Db::name('aigc_digital_human_billing')->where('task_id',$task['id'])->find();
        check($bill!==null && (float)$bill['quantity']===2.88,'Business ledger truncated settled seconds');
        check($wallets()===$before,'Business projection charged settled market usage twice');
    });
    test('text reference sample is never final generation usage',function()use($tenant,$user,$options,$wallets){
        $request=['image_url'=>'https://fixture.invalid/image.png','audio_url'=>'https://fixture.invalid/sample.wav','quality'=>'max','mode'=>'text','content'=>'Fixture narration'];
        $before=$wallets();
        $r=Pic::reserve($tenant,$user,'generate','text-no-usage',$options['pic_lipsync'],$request,10);
        $reserved=$wallets();$rejected=false;
        try{privateCall(Pic::class,'settle',$r['consumption_id'],[['video_uri'=>'fixture.mp4']],'fixture','fixture',['duration'=>10]);}catch(Exception){$rejected=true;}
        check($rejected,'10-second reference sample silently finalized missing generation usage');
        check($wallets()===$reserved && Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->value('billing_status')==='reserved','Missing usage lost reservation');
        privateCall(Pic::class,'settle',$r['consumption_id'],[['video_uri'=>'fixture.mp4','measured_output_seconds'=>2.88]],'fixture','fixture',[]);
        $bill=Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->find();
        check((float)$bill['quantity']===2.88 && $wallets()===[round($before[0]-11.52,2),round($before[1]-14.4,2)],'Measured generation duration did not replace 10-second sample');
        $task=\app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'consumption_id'=>$r['consumption_id'],'input_seconds'=>10,'status'=>'running']);
        \app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService::syncConsumption((int)$task['id']);
        check((float)Db::name('aigc_pic_lipsync_task')->where('id',$task['id'])->value('input_seconds')===2.88,'Task and statistics still expose reference sample seconds');
        $r=Pic::reserve($tenant,$user,'generate','text-authoritative-usage',$options['pic_lipsync'],$request,10);
        privateCall(Pic::class,'settle',$r['consumption_id'],[['video_uri'=>'fixture.mp4','measured_output_seconds'=>10]],'fixture','fixture',['usage'=>['input_second'=>2.88]]);
        check((float)Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->value('quantity')===2.88,'Authoritative input usage lost precedence');
    });
    test('text quote and reservation scale with narration instead of reference sample',function()use($tenant,$user,$wallets){
        $avatar=Db::name('image_human_avatar')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'source'=>'mine','image_uri'=>'uploads/fixture.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'https://fixture.invalid']);
        $audio=Db::name('aigc_music_asset')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'asset_type'=>'pic_lipsync_audio','uri'=>'uploads/reference.wav','duration'=>10,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'https://fixture.invalid']);
        $input=['avatar_id'=>$avatar,'audio_asset_id'=>$audio,'quality'=>'max','mode'=>'text','content'=>str_repeat('文',1000),'_user_id'=>$user];
        $service=\app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService::class;
        $long=privateCall($service,'prepare',$tenant,$input,true,$user);
        $short=privateCall($service,'prepare',$tenant,array_replace($input,['content'=>'文']),true,$user);
        check((float)$long['duration']===250.0 && (float)$short['duration']===1.0,'1 and 1000 characters still share reference sample billing');
        check((float)$long['reference_sample_seconds']===10.0 && (float)$short['reference_sample_seconds']===10.0,'Reference sample measurement was replaced by estimate');
        check(privateCall(Full::class,'estimateScriptDuration',$input['content'])===250.0 && privateCall(Digital::class,'estimateAudioDuration',$input['content'])===250,'Narration estimate diverges from existing owner contract');
        $quote=Pic::quote($tenant,$long['selection'],$long['duration']);
        check((float)$quote['tenant_cost_points']===1000.0 && (float)$quote['user_charge_points']===1250.0,'Long narration quote did not scale by seconds');
        $before=$wallets();
        $r=Pic::reserve($tenant,$user,'generate','long-narration',$long['selection'],$long['request'],$long['duration']);
        check($wallets()===[round($before[0]-1000,2),round($before[1]-1250,2)],'Long narration did not reserve full estimate');
        $usage=json_decode(Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->value('usage_snapshot'),true);
        check(($usage['settlement_basis']??'')==='script_estimate','Narration reservation was recorded as actual audio usage');
        privateCall(Pic::class,'settle',$r['consumption_id'],[['video_uri'=>'fixture.mp4','measured_output_seconds'=>2.88]],'fixture','fixture',[]);
        check($wallets()===[round($before[0]-11.52,2),round($before[1]-14.4,2)],'Long narration estimate replaced actual fractional usage');
        $before=$wallets();$r=Pic::reserve($tenant,$user,'generate','different-narration-result',$long['selection'],$long['request'],$long['duration']);
        privateCall(Pic::class,'settle',$r['consumption_id'],[['video_uri'=>'fixture.mp4','measured_output_seconds'=>123.45]],'fixture','fixture',[]);
        check($wallets()===[round($before[0]-493.8,2),round($before[1]-617.25,2)],'Different actual duration did not refund estimate difference');
    });
    test('long text rejects insufficient tenant or user funds before creating an upstream task',function()use($tenant,$user,$wallets){
        $avatar=Db::name('image_human_avatar')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'source'=>'mine','image_uri'=>'uploads/fixture.png','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'https://fixture.invalid']);
        $audio=Db::name('aigc_music_asset')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'asset_type'=>'pic_lipsync_audio','uri'=>'uploads/reference.wav','duration'=>10,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'https://fixture.invalid']);
        Db::name('app')->where('code','aigc_pic_lipsync')->update(['status'=>'installed']);
        $input=['avatar_id'=>$avatar,'audio_asset_id'=>$audio,'quality'=>'max','mode'=>'text','content'=>str_repeat('文',1000)];
        $before=$wallets();
        try {
            foreach ([[999,1250,'tenant'],[1000,1249,'user']] as [$tenantBalance,$userBalance,$side]) {
                Db::name('tenant')->where('id',$tenant)->update(['point_balance'=>$tenantBalance]);
                Db::name('user')->where('id',$user)->update(['user_money'=>$userBalance]);
                $counts=[Db::name('aigc_pic_lipsync_task')->count(),Db::name('ai_consumption_log')->count()];
                $message='';try{\app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService::generate($tenant,$user,$input);}catch(Exception $e){$message=$e->getMessage();}
                check(str_contains($message,'不足') && ($side==='tenant'?str_contains($message,'租户'):!str_contains($message,'租户')),'Insufficient '.$side.' funds reached submit or failed for another reason: '.$message);
                check($wallets()===[(float)$tenantBalance,(float)$userBalance] && $counts===[Db::name('aigc_pic_lipsync_task')->count(),Db::name('ai_consumption_log')->count()],'Insufficient funds created a billable task or changed balances');
            }
        } finally {
            Db::name('tenant')->where('id',$tenant)->update(['point_balance'=>$before[0]]);
            Db::name('user')->where('id',$user)->update(['user_money'=>$before[1]]);
        }
    });
} finally { @unlink($wav);Db::rollback(); }
if($failures)exit(1);
