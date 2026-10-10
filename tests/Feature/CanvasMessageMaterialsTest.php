<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationMessageMaterials as Materials;

class CanvasMessageMaterialsTest extends TestCase
{
    public function testPreviewOwnershipAndFrozenReferenceIdentity(): void
    {
        if(getenv('SHORT_DRAMA_DB_TESTS')!=='1') $this->markTestSkipped('Transactional DB test');
        (new \think\App())->initialize();Db::startTrans();
        try {
            $tenant=random_int(1500000000,1900000000);
            $asset=['tenant_id'=>$tenant,'user_id'=>7,'canvas_id'=>9,'uri'=>'https://example.test/original.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''];
            $id=Db::name('aigc_short_drama_asset')->insertGetId($asset);
            $scope=['tenant_id'=>$tenant,'user_id'=>7,'canvas_id'=>9];
            $attachments=[['type'=>'image','name'=>'原图.png','asset_id'=>$id]];
            $context=['selected_nodes'=>[['id'=>'8','type'=>'image','title'=>'引用图','image_asset'=>['id'=>$id]+$asset]]];
            $items=Materials::project($scope,$attachments,$context);
            self::assertSame(['原图.png','引用图'],array_column($items,'name'));
            self::assertSame([$asset['uri'],$asset['uri']],array_column($items,'url'));
            $scope['user_id']=8;self::assertSame(['',''],array_column(Materials::project($scope,$attachments,$context),'url'));
            $scope['user_id']=7;$scope['tenant_id']++;
            self::assertSame(['',''],array_column(Materials::project($scope,$attachments,$context),'url'));
            $scope['tenant_id']=$tenant;$context['selected_nodes'][0]['image_asset']['uri']='https://example.test/replaced.png';
            self::assertSame('',Materials::project($scope,[],$context)[0]['url']);
            Db::name('aigc_short_drama_asset')->where('id',$id)->update(['delete_time'=>time()]);
            self::assertSame('',Materials::project($scope,$attachments,[])[0]['url']);
        } finally {Db::rollback();}
    }
}
