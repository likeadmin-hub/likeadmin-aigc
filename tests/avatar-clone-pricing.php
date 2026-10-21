<?php
/** Local integration test. All fixture users, settings, assets and bills are rolled back. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App(dirname(__DIR__) . '/'))->initialize();
use think\facade\Db;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanPricingService as Pricing;
use app\common\service\app\aigc_digital_human\AvatarClonePricingService as ClonePricing;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService as Digital;
use app\common\service\app\image_human\ImageHumanService as Image;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService as Picture;
use app\common\model\user\User;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanBilling as Billing;
use app\common\model\app\image_human\ImageHumanAvatar;
function check($ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function rejected(callable $fn, string $message): void { try { $fn(); } catch (\Throwable $e) { echo "PASS $message: ".$e->getMessage()."\n"; return; } throw new RuntimeException($message); }
$c=config('database.connections.mysql');
if (getenv('AIGC_LOCAL_BILLING_TEST') !== '1' || $c['hostname'] !== '127.0.0.1' || $c['database'] !== 'x_cn') throw new RuntimeException('Local opt-in required');
Db::startTrans();
try {
    $tenant=1;
    Pricing::savePlatform(['avatar_clone'=>['platform_unit_cost'=>0,'tenant_unit_price'=>9]]);
    Pricing::saveTenant($tenant,['avatar_clone'=>['tenant_unit_price'=>7]]);
    Image::saveTenantPricing($tenant,['pricing'=>['avatar_clone'=>['tenant_unit_price'=>4.25]]]);
    Picture::saveConfig($tenant,['pricing'=>['avatar_clone'=>['tenant_unit_price'=>6.5]]]);
    $apps=['aigc_digital_human'=>7,'image_human'=>4.25,'aigc_pic_lipsync'=>6.5];
    foreach($apps as $app=>$price) check((float)Pricing::estimateAvatar($tenant,$app)['user_charge_points']===$price*1.0,"independent price $app");
    check((float)Digital::config($tenant)['pricing']['avatar_clone']['tenant_unit_price']===7.0,'lip-sync public config agrees with settlement');
    check((float)ClonePricing::price($tenant,'image_human')['tenant_unit_price']===4.25,'full-drive price survives normalization');
    check((float)ClonePricing::price($tenant,'aigc_pic_lipsync')['tenant_unit_price']===6.5,'picture price remains independent of shared image assets');
    $other=987654321;
    check((float)Pricing::estimateAvatar($other,'aigc_digital_human')['user_charge_points']===9.0,'unconfigured tenant inherits platform lip-sync price');
    check((float)ClonePricing::price($other,'image_human')['tenant_unit_price']!==4.25,'tenant override does not leak');
    foreach([-1,'invalid',null] as $invalid) rejected(fn()=>ClonePricing::forSave($tenant,'image_human',['avatar_clone'=>['tenant_unit_price'=>$invalid]]),'invalid price rejected');
    rejected(fn()=>Pricing::estimateAvatar($tenant,'image_human',['expected_clone_price'=>3]),'changed price rejected before creating asset');
    $user=User::create(['tenant_id'=>$tenant,'account'=>'clone_test_'.bin2hex(random_bytes(6)),'nickname'=>'clone price fixture','user_money'=>100,'sn'=>random_int(10000000,99999999),'create_time'=>time(),'update_time'=>time()]);
    $uid=(int)$user['id'];
    foreach($apps as $app=>$price) {
        $params=['name'=>'fixture '.$app,'media_uri'=>'/uploads/test-avatar.jpg','image_uri'=>'/uploads/test-avatar.jpg','expected_clone_price'=>$price];
        $before=(float)User::find($uid)['user_money'];
        $row=$app==='aigc_digital_human'?Digital::saveAvatar($tenant,$uid,$params):Image::saveAvatar($tenant,$uid,$params,$app);
        check(abs((float)User::find($uid)['user_money']-($before-$price))<0.00001,"one creation charges configured price $app");
        $type=Pricing::estimateAvatar($tenant,$app)['billing_type'];
        check(Billing::where(['tenant_id'=>$tenant,'user_id'=>$uid,'billing_type'=>$type])->count()===1,"one traceable bill $app");
        $edit=['id'=>$row['id'],'name'=>'renamed','expected_clone_price'=>999];
        $app==='aigc_digital_human'?Digital::saveAvatar($tenant,$uid,$edit):Image::saveAvatar($tenant,$uid,$edit,$app);
        check(abs((float)User::find($uid)['user_money']-($before-$price))<0.00001,"editing does not charge $app");
        $bill=Billing::where(['tenant_id'=>$tenant,'user_id'=>$uid,'billing_type'=>$type])->find();
        check(($bill['extra_json']['app_code']??'')===$app && (float)$bill['extra_json']['pricing_snapshot']['user_charge_points']===$price*1.0,"bill captures source and price $app");
    }
    User::where('id',$uid)->update(['user_money'=>0]);
    $count=ImageHumanAvatar::where(['tenant_id'=>$tenant,'user_id'=>$uid])->count();
    rejected(fn()=>Image::saveAvatar($tenant,$uid,['image_uri'=>'/uploads/test-avatar.jpg'],'aigc_pic_lipsync'),'insufficient points prevent creation');
    check(ImageHumanAvatar::where(['tenant_id'=>$tenant,'user_id'=>$uid])->count()===$count,'no orphan asset after rejected charge');
    Image::saveTenantPricing($tenant,['pricing'=>['avatar_clone'=>['tenant_unit_price'=>0]]]);
    Image::saveAvatar($tenant,$uid,['image_uri'=>'/uploads/free.jpg']);
    check((float)User::find($uid)['user_money']===0.0,'explicit free price creates without charging');
    Picture::saveConfig($tenant,['status'=>1]);
    check((float)ClonePricing::price($tenant,'aigc_pic_lipsync')['tenant_unit_price']===6.5,'unrelated config save preserves price');
    echo "PASS all clone price integration checks\n";
} finally { Db::rollback(); echo "All fixture changes rolled back\n"; }
