<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaBuiltinSkillCatalog;
use app\common\service\app\aigc_short_drama\ShortDramaSkillRuntime;
use PHPUnit\Framework\TestCase;

class ShortDramaBuiltinSkillCatalogTest extends TestCase
{
    public function testProductPromoSkillIsPackagedWithoutTenantMaterialReferences(): void
    {
        $skills = ShortDramaBuiltinSkillCatalog::all();
        self::assertCount(1, $skills);

        $skill = $skills[0];
        self::assertSame('product_promo_short', $skill['skill_key']);
        self::assertSame('商品宣传短片', $skill['name']);
        self::assertArrayNotHasKey('tenant_id', $skill);
        self::assertArrayNotHasKey('cover_asset_id', $skill);
        self::assertSame('video', $skill['cover_type']);
        self::assertStringStartsWith('https://', $skill['cover_url']);
        self::assertSame(['商业广告', '产品展示'], $skill['category_names']);
    }

    public function testPackagedDefinitionCoversEveryShortDramaStage(): void
    {
        $definition = ShortDramaBuiltinSkillCatalog::all()[0]['definition'];

        foreach (ShortDramaSkillRuntime::STAGES as $stage => $label) {
            self::assertArrayHasKey($stage, $definition['stages']);
            self::assertNotSame('', trim((string)$definition['stages'][$stage]), $label . ' must be defined');
        }
        self::assertSame(['剧本', '主体设定', '场景设定', '分镜', '图像', '视频', '音频'], $definition['output_policy']['required']);
    }
}
