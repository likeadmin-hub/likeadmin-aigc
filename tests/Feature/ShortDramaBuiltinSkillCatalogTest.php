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
        self::assertGreaterThan(1, count($skills));

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

    public function testAudioWorkflowSkillOnlyRequestsBackgroundMusicPrompt(): void
    {
        $skills = ShortDramaBuiltinSkillCatalog::all();
        $audio = array_values(array_filter($skills, static fn(array $skill): bool => ($skill['skill_key'] ?? '') === 'short_drama_audio_plan'));
        self::assertCount(1, $audio);
        self::assertStringContainsString('纯背景音乐生成提示词', $audio[0]['description']);
        self::assertStringContainsString('不包含角色说话、对白、旁白', $audio[0]['definition']['stages']['workflow']);
    }
}
