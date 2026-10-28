<?php
require __DIR__.'/builtin.php';
$app=new think\App();$app->initialize();
use think\facade\Db;
use app\common\service\app\aigc_copywriting\CopywritingStore as Store;
use app\common\service\app\aigc_copywriting\CopywritingSeeder as Seed;
use app\common\service\app\aigc_copywriting\CopywritingCategoryService as Category;
check(count(Db::query("SHOW TABLES LIKE 'la_aigc_copywriting_tool'"))===1,'copywriting migration has not run');
$tenant=(int)Db::name('tenant')->order('id')->value('id');check($tenant>0,'local fixture tenant exists');
Db::startTrans();try{
 $before=Store::table('tool')->where('tenant_id',$tenant)->count();$seed=Seed::ensureTenant($tenant);check(Store::table('tool')->where('tenant_id',$tenant)->count()===max(12,$before),'seed complete');check(Seed::ensureTenant($tenant)['inserted']===0,'idempotent seed');
 $tool=Store::table('tool')->where('tenant_id',$tenant)->find();Store::table('tool')->where('id',$tool['id'])->update(['name'=>'租户自定义名称','status'=>0]);Seed::ensureTenant($tenant);check(Store::table('tool')->where('id',$tool['id'])->value('name')==='租户自定义名称','tenant customization survives');
 try{Category::delete($tenant,1,(int)$tool['category_id']);throw new RuntimeException('nonempty category deleted');}catch(InvalidArgumentException $e){}
 $other=Category::save($tenant,1,['name'=>'自建分类','is_visible'=>1]);Category::delete($tenant,1,(int)$tool['category_id'],(int)$other['id']);check(Store::table('tool')->where('id',$tool['id'])->value('category_id')==$other['id'],'category moved atomically');
 echo "copywriting lifecycle PASS (rolled back)\n";
}finally{Db::rollback();}
