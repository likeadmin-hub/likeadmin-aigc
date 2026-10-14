<?php
use app\common\service\WorkspaceAssetService;
use PHPUnit\Framework\TestCase;

final class WorkspaceAssetTest extends TestCase
{
    public function testDeduplicatesAndKeepsPatchPartial(): void
    {
        [$refs, $patch, $action] = WorkspaceAssetService::normalize(['refs'=>['image:result:2','image:result:2','video:task:3'],'favorite'=>0]);
        self::assertSame(['image:result:2','video:task:3'], $refs);
        self::assertSame(['favorite'=>0], $patch);
        self::assertSame('', $action);
        self::assertSame([['upload:result:5'], ['label'=>'review'], 'restore'], WorkspaceAssetService::normalize(['refs'=>['upload:result:5'],'label'=>'review','action'=>'restore']));
    }
    public function testFavoriteFlagsSupportRequestEncodingWithoutLooseCoercion(): void
    {
        foreach ([0, 1, '0', '1', false, true] as $flag) {
            self::assertSame(['favorite'=>(int)$flag], WorkspaceAssetService::normalize(['refs'=>['image:result:1'],'favorite'=>$flag])[1]);
        }
        foreach (['false', '', null, 2, '01', '1x', [], 0.5] as $flag) {
            try { WorkspaceAssetService::normalizeFavorite($flag); self::fail('Invalid favorite accepted'); }
            catch (InvalidArgumentException $e) { self::assertSame('收藏状态无效', $e->getMessage()); }
        }
    }
    public function testToolSourcesKeepTheirOwnReferences(): void
    {
        foreach (['pic_lipsync','product_image','style_transfer','product_promo_video','smart_clip','music','music_cover'] as $source) {
            $ref = $source . ':result:12';
            self::assertSame([$ref], WorkspaceAssetService::normalize(['refs'=>[$ref],'label'=>'todo'])[0]);
        }
    }
    public function testRejectsUnknownReferencesAndOperations(): void
    {
        foreach ([
            ['refs'=>[],'action'=>'trash'], ['refs'=>['image:result:0'],'action'=>'trash'],
            ['refs'=>['user:result:1'],'action'=>'trash'], ['refs'=>['upload:task:1'],'action'=>'trash'],
            ['refs'=>['image:result:1'],'favorite'=>'true'], ['refs'=>['image:result:1'],'label'=>'admin'],
            ['refs'=>['image:result:1'],'action'=>'delete_file'], ['refs'=>['image:result:1'],'uri'=>'https://example.com'],
            ['refs'=>array_fill(0,101,'image:result:1'),'action'=>'trash'], ['refs'=>['image:result:1']],
        ] as $input) {
            try { WorkspaceAssetService::normalize($input); self::fail('Invalid mutation accepted'); }
            catch (InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
    }
}
