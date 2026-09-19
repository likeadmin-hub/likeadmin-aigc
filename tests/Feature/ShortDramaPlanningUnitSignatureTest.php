<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit;
use PHPUnit\Framework\TestCase;

class ShortDramaPlanningUnitSignatureTest extends TestCase
{
    private function input(): array
    {
        return ['content' => '剧本正文', 'model_selection' => ['id' => '1', 'model_code' => 'model-a',
            'display_icon' => 'http://localhost/icon.png', 'name' => '模型名称', 'default_params' => ['temperature' => 0.7]],
            'model_config' => ['max_tokens' => 4096]];
    }

    public function testWorkerDisplayDifferencesDoNotInvalidatePaidReceipts(): void
    {
        $input = $this->input(); $saved = $input;
        $saved['_unit_signature'] = 'historical-raw-signature';
        $input['model_selection']['display_icon'] = 'http:///icon.png';
        $input['model_selection']['name'] = '仅显示名称变化';
        self::assertSame(ShortDramaPlanningUnit::requestSignature($saved), ShortDramaPlanningUnit::requestSignature(array_reverse($input, true)));
    }

    public function testRealGenerationChangesStillInvalidateReceipts(): void
    {
        $baseline = ShortDramaPlanningUnit::requestSignature($this->input());
        foreach (['content', 'model', 'temperature', 'budget'] as $kind) {
            $input = $this->input();
            if ($kind === 'content') $input['content'] = '另一段剧情';
            if ($kind === 'model') $input['model_selection']['model_code'] = 'model-b';
            if ($kind === 'temperature') $input['model_selection']['default_params']['temperature'] = 1;
            if ($kind === 'budget') $input['model_config']['max_tokens'] = 8192;
            self::assertNotSame($baseline, ShortDramaPlanningUnit::requestSignature($input), $kind);
        }
    }
}
