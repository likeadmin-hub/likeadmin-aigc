<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationSchema as Schema;
use app\common\service\power\MarketGenerationRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MarketGenerationSchemaMessagesTest extends TestCase
{
    /** @dataProvider invalidInputs */
    public function testValidationExplainsTheInvalidFieldWithoutExposingInput(array $schema, array $values, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Schema::validate($schema, $values);
    }

    public static function invalidInputs(): array
    {
        return [
            'prompt required' => [['prompt'=>['type'=>'string','required'=>true]], [], '请填写必填参数：提示词'],
            'schema required and custom title' => [['required'=>['prompt'],'properties'=>['prompt'=>['title'=>'角色外观描述','type'=>'string']]], [], '请填写必填参数：角色外观描述'],
            'known field with English title' => [['prompt'=>['title'=>'Prompt','required'=>true]], [], '请填写必填参数：提示词'],
            'unknown required field' => [['x_vendor_secret'=>['required'=>true]], [], '请填写必填参数：参数'],
            'malformed title' => [['prompt'=>['title'=>['bad'],'required'=>true]], [], '请填写必填参数：提示词'],
            'type' => [['duration'=>['type'=>'integer']], ['duration'=>'credential-like-input'], '参数格式不正确：时长'],
            'minimum' => [['duration'=>['type'=>'integer','minimum'=>2]], ['duration'=>1], '参数超出范围：时长'],
            'maximum with custom title' => [['duration'=>['title'=>'镜头时长','type'=>'integer','maximum'=>15]], ['duration'=>16], '参数超出范围：镜头时长'],
            'enum' => [['ratio'=>['enum'=>['9:16']]], ['ratio'=>'secret'], '参数选项不支持：画面比例'],
            'const' => [['model'=>['const'=>'fixed']], ['model'=>'secret'], '参数选项不支持：模型'],
            'length' => [['prompt'=>['maxLength'=>2]], ['prompt'=>'secret'], '参数长度不正确：提示词'],
            'pattern' => [['seed'=>['pattern'=>'^\\d+$']], ['seed'=>'secret'], '参数格式不正确：随机种子'],
            'item count' => [['reference_images'=>['type'=>'array','maxItems'=>1]], ['reference_images'=>['a','b']], '素材或参数数量不正确：参考图片'],
            'nested required' => [['settings'=>['title'=>'渲染设置','type'=>'object','properties'=>['prompt'=>['required'=>true]]]], ['settings'=>['other'=>'secret']], '请填写必填参数：渲染设置.提示词'],
            'array item type' => [['reference_images'=>['type'=>'array','items'=>['type'=>'string']]], ['reference_images'=>[[]]], '参数格式不正确：参考图片[0]'],
            'nested array required' => [['scenes'=>['title'=>'分镜','type'=>'array','items'=>['type'=>'object','properties'=>['prompt'=>['required'=>true]]]]], ['scenes'=>[['other'=>'secret']]], '请填写必填参数：分镜[0].提示词'],
            'unknown disallowed' => [['additionalProperties'=>false,'properties'=>[]], ['secret_token'=>'sensitive'], '当前参数组合不支持：参数'],
            'nested disallowed' => [['settings'=>['title'=>'渲染设置','type'=>'object','additionalProperties'=>false,'properties'=>[]]], ['settings'=>['secret_token'=>'sensitive']], '当前参数组合不支持：渲染设置.参数'],
            'value union' => [['quality'=>['anyOf'=>[['const'=>'high'],['const'=>'low']]]], ['quality'=>'secret'], '参数组合不正确：画质'],
        ];
    }

    public function testLabelsNeverRenameSchemaFieldsOrThePreparedPayload(): void
    {
        $snapshot=['market_metadata'=>['params_schema'=>['prompt'=>['title'=>'角色描述','type'=>'string','required'=>true]]]];
        self::assertSame(['prompt'=>'保留人物服饰'],MarketGenerationRequest::normalizeLegacyPayload($snapshot,['prompt'=>'保留人物服饰'],['prompt'=>'保留人物服饰']));
    }

    public function testDeferredPreliminaryPromptDoesNotDeferNestedRequiredInput(): void
    {
        Schema::validate(['prompt'=>['required'=>true]], [], '', '', ['prompt']);
        $this->expectExceptionMessage('请填写必填参数：渲染设置.提示词');
        Schema::validate(['settings'=>['title'=>'渲染设置','type'=>'object','properties'=>['prompt'=>['required'=>true]]]], ['settings'=>['other'=>true]], '', '', ['prompt']);
    }
}
