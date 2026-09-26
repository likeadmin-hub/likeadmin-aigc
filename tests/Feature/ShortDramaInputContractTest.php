<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaInputContract as Contract;
use app\common\service\app\aigc_short_drama\ShortDramaSubmission;
use PHPUnit\Framework\TestCase;

class ShortDramaInputContractTest extends TestCase
{
    public function testNewScriptContractRemovesOnlySkillFields(): void
    {
        $creative = ['prompt' => '原文不可改变', '_prompt_snapshot' => ['revision' => 3],
            'style_id' => 'a', 'subject_ids' => [1], 'revision_base_result' => ['dialogue' => '完整台词'],
            'model_selections' => ['script_plan' => ['id' => 1]], 'target_duration_seconds' => 0];
        $input = $creative + ['skill_id' => 5, 'skill_inputs' => ['x' => '不要注入'], '_skill_snapshot' => ['id' => 5], '_skill_asset_inventory' => ['x']];
        $new = Contract::begin($input);
        self::assertTrue(Contract::current($new));
        unset($new['_input_contract_version']);
        self::assertSame($creative, $new);
        self::assertFalse(Contract::current($input));
        self::assertSame(5, $input['skill_id']); // No mutation of historical snapshots.
    }

    public function testSubmissionIdentityIsStableAndScopeBound(): void
    {
        $input = ['submission_key' => 'sd:0123456789abcdef', 'prompt' => '完整输入', 'model' => 'a'];
        $first = ShortDramaSubmission::identity(1, 2, 'create', $input);
        self::assertSame($first, ShortDramaSubmission::identity(1, 2, 'create', array_reverse($input, true)));
        foreach ([[2, 2, 'create'], [1, 3, 'create'], [1, 2, 'revision']] as $scope) {
            self::assertNotSame($first['key'], ShortDramaSubmission::identity(...array_merge($scope, [$input]))['key']);
        }
        $changed = ShortDramaSubmission::identity(1, 2, 'create', array_replace($input, ['prompt' => '已修改']));
        self::assertSame($first['key'], $changed['key']);
        self::assertNotSame($first['hash'], $changed['hash']);
    }

    public function testMalformedKeyIsRejected(): void
    {
        $this->expectExceptionCode(422);
        ShortDramaSubmission::identity(1, 2, 'create', ['submission_key' => ['invalid']]);
    }
}
