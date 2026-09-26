<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog as Catalog;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace as Workspace;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Stops at the actual provider boundary. No HTTP, billing or generated artifacts. */
class ShortDramaPromptProviderCaptureTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNewScriptContractKeepsCreativeRulesButExcludesSkillsAtProviderBoundary(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_provider_capture.php';
        $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'revision' => 12,
            'overrides' => ['script.role' => '必须保留的租户创作规则']], []);
        try {
            Catalog::run($snapshot, static function () use ($snapshot): void {
                $method = new ReflectionMethod(AigcShortDramaService::class, 'generateScriptPlanResult');
                $method->setAccessible(true);
                $method->invoke(null, 701, 23, '保留原始结局与完整对白', [
                    '_input_contract_version' => 1, '_prompt_snapshot' => $snapshot, 'target_duration_seconds' => 60,
                    'skill_id' => '不应出现的技能标记', 'skill_inputs' => ['prompt' => '不应出现的技能标记'],
                    '_skill_snapshot' => ['system_prompt' => '不应出现的技能标记'],
                ], '测试', ['model_code' => 'capture-only']);
            });
            self::fail('Provider was not intercepted');
        } catch (\ShortDramaCapturedRequest $captured) {
            $text = $captured->params['content'] . $captured->params['system_prompt'];
            self::assertStringContainsString('必须保留的租户创作规则', $text);
            self::assertStringContainsString('保留原始结局与完整对白', $text);
            self::assertStringNotContainsString('不应出现的技能标记', $text);
            self::assertTrue($captured->params['_require_final_content']);
            self::assertTrue($captured->params['_disable_model_fallback']);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDocumentsReachRealTextProviderForAllStagesAndRepair(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_provider_capture.php';
        $snapshot = Workspace::resolve(701, ['mode' => 'documents', 'document_settings' => ['script' => ['mode' => 'custom', 'body' => '策划文档实发标记'], 'storyboard' => ['mode' => 'custom', 'body' => '分镜文档实发标记']]], []);
        foreach (['single', 'story', 'episodes', 'production', 'revision', 'repair'] as $stage) {
            try {
                Catalog::run($snapshot, static function () use ($snapshot, $stage): void {
                    $method = new ReflectionMethod(AigcShortDramaService::class, $stage === 'repair' ? 'repairScriptPlanResultWithLlm' : 'generateScriptPlanResult');
                    $method->setAccessible(true);
                    $request = ['_prompt_snapshot' => $snapshot, 'target_duration_seconds' => 60];
                    if (in_array($stage, ['story', 'episodes', 'production'], true)) $request += ['multi_episode' => true, 'episode_count' => 3, 'multi_episode_stage' => $stage];
                    if ($stage === 'revision') $request['revision_message'] = '用户明确修改：保留最后 3 秒';
                    $args = [701, 23, '用户明确：总长 60 秒，保留全部时间节点。', $request, '测试', ['model_code' => 'capture-only']];
                    if ($stage === 'repair') $args[] = ['storyboard' => []];
                    $method->invokeArgs(null, $args);
                });
                self::fail('Provider was not intercepted');
            } catch (\ShortDramaCapturedRequest $captured) {
                $text = $captured->params['content'] . $captured->params['system_prompt'];
                self::assertSame(1, substr_count($text, '策划文档实发标记'), $stage);
                self::assertSame(1, substr_count($text, '分镜文档实发标记'), $stage);
                self::assertStringNotContainsString(Catalog::defaults()['script.role'], $text);
                self::assertStringNotContainsString(Catalog::defaults()['script.visible_action'], $text);
                self::assertStringContainsString('保留全部时间节点', $text);
                self::assertStringNotContainsString('_prompt_snapshot', $text);
            }
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testScriptAndRepairSubmitConfiguredMessagesToModel(): void
    {
        require __DIR__ . '/../fixtures/short_drama_text_provider_capture.php';
        $snapshot = Workspace::resolve(701, ['mode' => 'workspace', 'revision' => 12, 'overrides' => ['script.role' => '租户策划角色标记', 'repair.preserve' => '租户修复规则标记']], []);
        foreach (['script', 'repair'] as $stage) {
            try {
                Catalog::run($snapshot, static function () use ($snapshot, $stage): void {
                    $method = new ReflectionMethod(AigcShortDramaService::class, $stage === 'script' ? 'generateScriptPlanResult' : 'repairScriptPlanResultWithLlm');
                    $method->setAccessible(true);
                    $args = [701, 23, '用户明确要求：总长 60 秒，保留最后 3 秒镜头。', ['_prompt_snapshot' => $snapshot, 'target_duration_seconds' => 60], '测试', ['model_code' => 'capture-only']];
                    if ($stage === 'repair') $args[] = ['storyboard' => []];
                    $method->invokeArgs(null, $args);
                });
                self::fail('Expected capture boundary');
            } catch (\ShortDramaCapturedRequest $captured) {
                $params = $captured->params;
                self::assertSame(701, $captured->tenantId);
                if ($stage === 'script') {
                    self::assertStringContainsString('租户策划角色标记', $params['system_prompt']);
                    self::assertStringNotContainsString(Catalog::defaults()['script.role'], $params['system_prompt']);
                    self::assertStringContainsString('JSON schema:', $params['content']);
                    self::assertStringNotContainsString('_prompt_snapshot', $params['content']);
                } else {
                    self::assertStringContainsString('租户修复规则标记', $params['content']);
                    self::assertStringContainsString('只返回合法 JSON', $params['system_prompt']);
                }
                self::assertStringContainsString('保留最后 3 秒镜头', $params['content']);
                self::assertNull(Catalog::snapshot());
            }
        }
    }
}
