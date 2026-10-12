<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
class PicLipsyncPackageContractTest extends TestCase
{
    private function root(): string { return dirname(__DIR__,2).'/app/apps/aigc_pic_lipsync/'; }
    public function testReleaseManifestFitsExistingMetadataColumns(): void
    {
        $manifest=json_decode(file_get_contents($this->root().'manifest.json'),true);
        self::assertLessThan(65535,strlen(json_encode($manifest)));
        self::assertSame(0,$manifest['is_builtin']);
        self::assertSame('/ai/avatar?tab=pic_lipsync',array_values(array_filter($manifest['frontend_entries'],fn($e)=>$e['terminal']==='pc'))[0]['path']);
        foreach($manifest['public_assets'] as $asset) {
            self::assertFileExists($this->root().$asset['source']);
            self::assertDoesNotMatchRegularExpression('#^public/(pc|_nuxt|mobile|mp-weixin)/#',$asset['target']);
        }
    }
    public function testAllManagementRoutesHaveControllersAndTenantPermissions(): void
    {
        $schema=json_decode(file_get_contents($this->root().'api_schema.json'),true);
        $permissions=json_decode(file_get_contents($this->root().'permissions/tenant.json'),true);
        $keys=array_column($permissions,'permission_key');
        foreach($schema['apis'] as $api) {
            if(!in_array($api['scene'],['tenant_admin','platform_admin'],true))continue;
            preg_match('#^app\.aigc_pic_lipsync\.([a-z_]+)/([a-z_]+)$#',$api['api_path'],$match);
            $controller=str_replace(' ','',ucwords(str_replace('_',' ',$match[1]))).'Controller';
            $namespace=$api['scene']==='tenant_admin'?'tenantapi':'platformapi';
            self::assertTrue(method_exists('app\\'.$namespace.'\\controller\\app\\aigc_pic_lipsync\\'.$controller,$match[2]),$api['api_path']);
            self::assertSame(1,$api['need_role_permission']);
            if($namespace==='tenantapi')self::assertContains($api['permission_key'],$keys);
        }
    }
    public function testPackageSignatureAndCompleteFrontendSnapshot(): void
    {
        $root=$this->root();$sig=json_decode(file_get_contents($root.'signature.json'),true);
        foreach($sig['sha256'] as $file=>$hash)self::assertSame($hash,hash_file('sha256',$root.$file),$file);
        $deployment=json_decode(file_get_contents($root.'frontend/deployment.json'),true);
        foreach($deployment['files'] as $file)self::assertSame($file['sha256'],hash_file('sha256',$root.$file['source']));
        self::assertGreaterThan(0,count($deployment['files']));
    }
}
