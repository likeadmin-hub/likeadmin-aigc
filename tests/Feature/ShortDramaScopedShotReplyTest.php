<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaShotFieldRepair as Repair;
use PHPUnit\Framework\TestCase;

final class ShortDramaScopedShotReplyTest extends TestCase
{
    private function part(): array
    {
        return ['storyboard'=>[
            ['shot_id'=>'s1','visual_description'=>'甲停下','recommended_duration_seconds'=>5,'subject_ref_ids'=>['p']],
            ['shot_id'=>'s2','visual_description'=>'乙在门内说话','dialogue'=>'等等，是我。','recommended_duration_seconds'=>5],
        ]];
    }
    private function targets(): array { return [['shot_id'=>'s2','fields'=>['recommended_duration_seconds']]]; }

    public function testCompleteReplyCanSupplyOnlyTheRequestedFieldWithoutRewritingOtherShots(): void
    {
        $part=$this->part();$reply=$part;$reply['storyboard'][1]['recommended_duration_seconds']=8;
        // JSON object key order is not an edit; list order and values are.
        $reply['storyboard'][0]=array_reverse($reply['storyboard'][0],true);
        self::assertSame(array_replace_recursive($part,['storyboard'=>[1=>['recommended_duration_seconds'=>8]]]),Repair::apply($part,$this->targets(),$reply));
    }

    /** @dataProvider expanded */
    public function testWholeReplyCannotExpandTheFieldScope(string $change): void
    {
        $part=$this->part();$reply=$part;$reply['storyboard'][1]['recommended_duration_seconds']=8;
        if($change==='text')$reply['storyboard'][1]['visual_description']='新增开门';
        elseif($change==='other_shot')$reply['storyboard'][0]['visual_description']='甲走了';
        elseif($change==='id')$reply['storyboard'][1]['shot_id']='new';
        elseif($change==='order')$reply['storyboard']=array_reverse($reply['storyboard']);
        elseif($change==='missing')array_shift($reply['storyboard']);
        elseif($change==='root')$reply['title']='越权';
        elseif($change==='field')unset($reply['storyboard'][1]['recommended_duration_seconds']);
        elseif($change==='type')$reply['storyboard'][0]['recommended_duration_seconds']='5';
        $this->expectException(\RuntimeException::class);$this->expectExceptionCode(422);
        $this->expectExceptionMessage('分镜字段修复结果不完整');
        Repair::apply($part,$this->targets(),$reply);
    }
    public static function expanded(): array { return array_map(static fn($v)=>[$v],['text','other_shot','id','order','missing','root','field','type']); }
}
