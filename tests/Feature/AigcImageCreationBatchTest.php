<?php
namespace Tests\Feature;

use app\common\service\app\aigc_image\AigcImageCreationBatch as Batch;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AigcImageCreationBatchTest extends TestCase
{
    public function testBatchSurvivesJsonStorageWithoutEnteringProviderPayload(): void
    {
        $batch = ['id' => 'pc-image-test-batch-1234', 'index' => 2, 'size' => 4];
        $request = ['quantity' => 1, 'prompt' => 'test', 'creation_batch' => $batch];
        $stored = Batch::takeFromRequest($request);
        self::assertSame(['quantity' => 1, 'prompt' => 'test'], $request);
        self::assertSame($batch, Batch::fromTask(['provider_params_json' => json_encode($stored)]));
        self::assertSame(['variant' => 'pro'], Batch::providerParams(array_merge(['variant' => 'pro'], $stored)));
    }

    public function testLegacyAndMalformedMetadataNeverGroupsUnrelatedTasks(): void
    {
        self::assertNull(Batch::fromTask([]));
        self::assertNull(Batch::fromTask(['provider_params_json' => '{broken']));
        foreach ([null, [], ['id' => '../invalid', 'index' => 1, 'size' => 4],
            ['id' => 'pc-image-test-batch-1234', 'index' => 5, 'size' => 4],
            ['id' => 'pc-image-test-batch-1234', 'index' => 1, 'size' => 5]] as $invalid) {
            self::assertNull(Batch::normalize($invalid));
        }
        $legacy = ['prompt' => 'same text'];
        self::assertSame([], Batch::takeFromRequest($legacy));
    }

    public function testBatchRequiresOneImagePerTask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $request = ['quantity' => 4, 'creation_batch' => ['id' => 'pc-image-test-batch-1234', 'index' => 1, 'size' => 4]];
        Batch::takeFromRequest($request);
    }
}
