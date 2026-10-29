<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\ShortDramaFileModelParser as Parser;
final class ShortDramaFileModelParserTest extends TestCase
{
    private function plan():array{return ['title'=>'归途','episodes'=>[
        ['episode_no'=>1,'source_range'=>['start_line'=>1,'end_line'=>2]],
        ['episode_no'=>2,'source_range'=>['start_line'=>3,'end_line'=>4]]]];}
    public function testOriginalBytesAreSlicedLocallyAndNeverTakenFromModel():void
    {
        $source="第一行\r\n第二行\r\n第三行\n第四行";$p=$this->plan();$p['episodes'][0]['content']='模型改写';
        $r=Parser::attachSource($p,$source);
        self::assertSame($source,implode('',array_column($r['episodes'],'source_content')));
        self::assertSame(hash('sha256',$source),$r['episodes'][1]['source_scope']['document_sha256']);
        self::assertArrayNotHasKey('content',$r['episodes'][0]);
    }
    public function testGapsAndOverlapsCannotBecomeImportedScripts():void
    {
        $this->expectException(\RuntimeException::class);$p=$this->plan();$p['episodes'][1]['source_range']['start_line']=2;Parser::attachSource($p,"甲\n乙\n丙\n丁");
    }
    public function testMissingTailCannotDiscardDocumentText():void
    {
        $this->expectException(\RuntimeException::class);$p=$this->plan();$p['episodes'][1]['source_range']['end_line']=3;Parser::attachSource($p,"甲\n乙\n丙\n丁");
    }
}
