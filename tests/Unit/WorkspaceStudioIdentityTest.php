<?php
use app\common\service\WorkspaceMediaPickerService;
use PHPUnit\Framework\TestCase;

final class WorkspaceStudioIdentityTest extends TestCase
{
    public function testIndexedOutputsKeepTheirExactOrdinal(): void
    {
        $parse = new ReflectionMethod(WorkspaceMediaPickerService::class, 'parseIdentity');
        $parse->setAccessible(true);
        foreach (['store_visit', 'story_commerce', 'product_video', 'canvas'] as $source) {
            $parts = $parse->invoke(null, $source.':task:11:2');
            self::assertSame([$source.':task', '11', '2'], array_slice($parts, 1));
        }
        self::assertSame(['motion_sync:result', '42'], array_slice($parse->invoke(null, 'motion_sync:result:42'), 1));
    }

    public function testIndexesCannotBeOmittedOrAttachedToSingleResults(): void
    {
        $parse = new ReflectionMethod(WorkspaceMediaPickerService::class, 'parseIdentity');
        $parse->setAccessible(true);
        foreach (['store_visit:task:11', 'story_commerce:task:11', 'product_video:task:11', 'canvas:task:11', 'store_visit:task:11:0', 'motion_sync:result:42:1', 'video:result:2:1'] as $ref) {
            try { $parse->invoke(null, $ref); self::fail('Invalid output identity accepted: '.$ref); }
            catch (InvalidArgumentException $e) { self::assertSame('素材标识无效', $e->getMessage()); }
        }
    }
}
