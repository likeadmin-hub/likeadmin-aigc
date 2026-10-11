<?php
namespace Tests\Feature;

use app\common\service\app\aigc_image\AigcImageCreationBatch as Batch;
use app\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AigcImageCreationBatchTest extends TestCase
{
    public function testBatchSurvivesApplicationRequestFiltering(): void
    {
        for ($size = 2; $size <= 4; $size++) {
            for ($index = 1; $index <= $size; $index++) {
                $batch = ['id' => 'pc-image-test-batch-1234', 'index' => $index, 'size' => $size];
                $request = new Request();
                $request->withPost(json_decode(json_encode([
                    'quantity' => 1, 'prompt' => 'test', 'creation_batch' => $batch,
                ]), true));
                $params = $request->post();
                self::assertSame((string)$size, $params['creation_batch']['size']);
                self::assertSame((string)$index, $params['creation_batch']['index']);

                $stored = Batch::takeFromRequest($params);
                self::assertArrayNotHasKey('creation_batch', $params);
                self::assertSame($batch, Batch::fromTask(['provider_params_json' => json_encode($stored)]));
                self::assertSame([], Batch::providerParams($stored));
            }
        }
    }

    public function testInvalidNumericMetadataIsNotCoercedIntoValidPositions(): void
    {
        foreach ([true, false, null, [], 2.0, 2.5, '2.0', '2e0', '02', '+2', '2foo', '', '0', '5'] as $invalid) {
            foreach (['index', 'size'] as $field) {
                $batch = ['id' => 'pc-image-test-batch-1234', 'index' => 1, 'size' => 4];
                $batch[$field] = $invalid;
                self::assertNull(Batch::normalize($batch));
            }
        }
        self::assertNull(Batch::normalize(['id' => 'pc-image-test-batch-1234', 'index' => '4', 'size' => '2']));
    }

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
