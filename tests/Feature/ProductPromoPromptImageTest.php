<?php
namespace Tests\Feature;

use app\common\service\app\aigc_product_promo_video\AigcProductPromoVideoService;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ProductPromoPromptImageTest extends TestCase
{
    protected function setUp(): void
    {
        class_alias(OfflinePromoPromptMedia::class, 'app\\common\\service\\ai\\StudioMediaInputService');
        class_alias(OfflinePromoPromptLlm::class, 'app\\common\\service\\app\\aigc_llm\\AigcLlmService');
        class_alias(OfflinePromoPromptAccess::class, 'app\\common\\service\\app\\AppAccessService');
        class_alias(OfflinePromoPromptType::class, 'app\\common\\model\\app\\aigc_product_promo_video\\AigcProductPromoVideoType');
    }

    public function testMissingAndUnuploadedImagesCannotReachTheModel(): void
    {
        foreach (['', 'blob:preview', 'data:image/png;base64,AAAA'] as $image) {
            try {
                AigcProductPromoVideoService::writePrompt(7, 9, ['source_image'=>$image]);
                self::fail('An unuploaded image was accepted');
            } catch (\Exception $e) {
                self::assertStringContainsString('上传', $e->getMessage());
            }
        }
        self::assertSame([], OfflinePromoPromptMedia::$validated);
        self::assertSame([], OfflinePromoPromptLlm::$calls);
    }

    public function testWriteUsesVerifiedImageContentAndKeepsTheVideoContextAndBillingOwner(): void
    {
        $result = AigcProductPromoVideoService::writePrompt(7, 9, ['source_image'=>'uploads/product.png','ratio'=>'16:9','duration'=>8,'video_type_code'=>'product','hint'=>'展示瓶盖细节']);
        self::assertSame([7,9,[['uri'=>'uploads/product.png']],1], OfflinePromoPromptMedia::$validated);
        self::assertSame(OfflinePromoPromptMedia::$images, OfflinePromoPromptMedia::$read);
        [$tenant,$user,$request] = OfflinePromoPromptLlm::$calls[0];
        self::assertSame([7,9], [$tenant,$user]);
        self::assertSame(['data:image/png;base64,dmVyaWZpZWQ='], $request['reference_images']);
        self::assertSame('aigc_product_promo_video', $request['source_app_code']);
        self::assertSame('prompt_helper', $request['source_type']);
        foreach (['识别','不编造','16:9','8秒','展示瓶盖细节'] as $text) self::assertStringContainsString($text, $request['content']);
        self::assertSame(['prompt'=>'图片对应的产品描述','charge_points'=>2,'model_code'=>'offline-vision'], $result);
    }

    public function testInvalidOrUnownedMediaStopsBeforeAnyLlmRequest(): void
    {
        OfflinePromoPromptMedia::$reject = true;
        try {
            AigcProductPromoVideoService::writePrompt(7, 9, ['source_image'=>'uploads/other.png']);
            self::fail('Invalid media was accepted');
        } catch (\RuntimeException $e) {
            self::assertSame('素材不存在或不属于当前用户', $e->getMessage());
        }
        self::assertSame([], OfflinePromoPromptLlm::$calls);
    }

    public function testExistingTextOptimizationDoesNotRequireAnImage(): void
    {
        AigcProductPromoVideoService::optimizePrompt(7, 9, ['prompt'=>'原描述词']);
        self::assertSame([], OfflinePromoPromptMedia::$validated);
        self::assertSame([], OfflinePromoPromptLlm::$calls[0][2]['reference_images']);
        self::assertStringContainsString('原描述词', OfflinePromoPromptLlm::$calls[0][2]['content']);
    }
}

final class OfflinePromoPromptMedia
{
    public static array $validated = [], $read = [];
    public static bool $reject = false;
    public static array $images = [['uri'=>'uploads/product.png','tenant_id'=>7,'user_id'=>9,'file_id'=>12]];
    public static function validateImages(int $tenant, int $user, array $images, int $max): array
    {
        self::$validated = [$tenant,$user,$images,$max];
        if (self::$reject) throw new \RuntimeException('素材不存在或不属于当前用户');
        return self::$images;
    }
    public static function visionInputs(array $images): array
    {
        self::$read = $images;
        return ['data:image/png;base64,dmVyaWZpZWQ='];
    }
}
final class OfflinePromoPromptLlm
{
    public static array $calls = [];
    public static function generateText(int $tenant, int $user, array $params): array
    {
        self::$calls[] = [$tenant,$user,$params];
        return ['content'=>' 图片对应的产品描述 ','charge_points'=>2,'model_code'=>'offline-vision'];
    }
}
final class OfflinePromoPromptAccess
{
    public static function assertTenantAvailable(int $tenant, string $code) { return null; }
}
final class OfflinePromoPromptType
{
    public static function where(...$args): self { return new self(); }
    public function __call(string $name, array $args): self { return $this; }
    public function isEmpty(): bool { return false; }
    public function toArray(): array { return ['name'=>'产品宣传']; }
}
