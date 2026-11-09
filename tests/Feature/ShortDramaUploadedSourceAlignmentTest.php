<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service, ShortDramaUploadedSourceAlignment as Alignment,
    ShortDramaSourceBoundary as Boundary, ShortDramaFileParseFallback as Guard,
    ShortDramaImportedScript as Imported};
use PHPUnit\Framework\TestCase;

final class ShortDramaUploadedSourceAlignmentTest extends TestCase
{
    private function source(): string
    {
        return "人物设定：甲是寻找故人的信使。\r\n\r\n镜头1｜0-3秒 甲找到旧信。\r\n甲沿地址赶往旧宅。\r\n\r\n镜头2｜4-8秒 乙交出日记。\r\n乙坦白了信件的来历。\r\n\r\n统一负面提示词：禁止字幕与水印。\r\n";
    }
    private function episodes(): array
    {
        return [
            ['episode_no'=>1,'title'=>'旧信','story_outline'=>'甲找到旧信，沿地址赶往旧宅。','conflict_point'=>'信中地址与记忆矛盾。','ending_hook'=>'甲在门前遇到乙。',
                'content'=>"镜头１｜０–３秒 甲找到旧信。 甲沿地址赶往旧宅。",'source_range'=>['start_line'=>1,'end_line'=>2]],
            ['episode_no'=>2,'title'=>'日记','story_outline'=>'乙交出日记，坦白信件的来历。','conflict_point'=>'乙不愿揭开过去。','ending_hook'=>'日记揭示了另一段往事。',
                'content'=>"镜头2｜4–8秒 乙交出日记。\n乙坦白了信件的来历。",'source_range'=>['start_line'=>3,'end_line'=>4]],
        ];
    }
    private function rawPlan(): array
    {
        return ['title'=>'旧信','type_judgement'=>'悬疑','core_theme'=>'寻找故人','story_outline'=>'甲在旧宅向乙追问旧信的来历，乙交出日记。',
            'subjects'=>[['name'=>'甲','category'=>'character','description'=>'寻找故人的信使']],
            'locations'=>[['name'=>'旧宅','description'=>'藏着旧信的木屋']], 'episodes'=>$this->episodes()];
    }
    public function testParserCoordinatesAndFormattingAreMappedToExactFrozenUpload(): void
    {
        $original=$this->episodes();$mapped=Alignment::map($original,$this->source());
        self::assertSame($this->source(),implode('',array_column($mapped,'source_content')));
        self::assertSame([['start_line'=>1,'end_line'=>5],['start_line'=>6,'end_line'=>9]],array_column($mapped,'source_range'));
        self::assertStringContainsString('人物设定',$mapped[0]['source_content']);
        self::assertStringContainsString('负面提示词',$mapped[1]['source_content']);
        self::assertStringContainsString('0-3秒',$mapped[0]['source_content']);
        self::assertSame($original,$this->episodes(),'Raw provider receipt stays immutable');
        Guard::assertSources(['episodes'=>$mapped],['_file_parse_fallback'=>['text'=>$this->source()]]);
        self::assertSame($mapped,Alignment::map($mapped,$this->source()));
        self::assertSame($mapped,Boundary::episodes($mapped,'split_by_ai'));
        foreach($mapped as $episode) Boundary::assertRequest(['series_context'=>['current_episode'=>$episode]]);
    }
    public function testAdapterStoryConfirmationAndEpisodeValidationKeepFrozenSource(): void
    {
        $request=['parse_mode'=>'split_by_ai','_file_parse_fallback'=>['version'=>1,'text'=>$this->source()]];
        $plan=(new \ReflectionMethod(Service::class,'fileQaParsePlan'))->invoke(null,$this->rawPlan(),$request,'旧信');
        Guard::assertSources($plan,$request);
        $story=Imported::storyDraft($plan);$confirmed=Imported::confirmedOutline($plan,$story);
        self::assertSame('旧信',$story['title']);self::assertSame('story',$story['multi_episode_stage']);
        self::assertSame($this->source(),implode('',array_column($confirmed['episodes'],'source_content')));
        self::assertSame($plan['episodes'],$confirmed['episodes']);
        self::assertCount(2,\app\common\service\app\aigc_short_drama\ShortDramaEpisodeService::validateOutline($confirmed,2));
    }
    public function testSingleExcerptRetainsCompleteSingleEpisodeDocument(): void
    {
        $source="人物说明。\n甲找到旧信。\n附录。\n";
        $mapped=Alignment::map([['episode_no'=>1,'content'=>'甲找到旧信。']],$source);
        self::assertSame($source,$mapped[0]['source_content']);
        self::assertSame(['start_line'=>1,'end_line'=>3],$mapped[0]['source_range']);
    }
    public function testExplicitHeadingsKeepPreambleAndChapterBoundaries(): void
    {
        $source="剧名与人物介绍。\n第三集 旧信\n甲找到旧信。\n第四集 日记\n乙交出日记。\n附录。\n";
        $episodes=[['episode_no'=>1,'content'=>'甲找到旧信。'],['episode_no'=>2,'content'=>'乙交出日记。']];
        $mapped=Alignment::map($episodes,$source);
        Guard::assertSources(['episodes'=>$mapped],['_file_parse_fallback'=>['text'=>$source]]);
        self::assertSame($source,implode('',array_column($mapped,'source_content')));
        self::assertSame(4,$mapped[1]['source_range']['start_line']);
    }
    public function testWholeDocumentEnvelopesUseFrozenHeadingCoordinates(): void
    {
        $source="剧名。\r\n第一集 旧信\r\n甲找到旧信。\r\n第二集 日记\r\n乙交出日记。\r\n";
        $envelope='L000001 '.json_encode(['type'=>'file','filename'=>'fixture.docx','content'=>str_replace("\r\n","\n",$source)],JSON_UNESCAPED_UNICODE);
        $mapped=Alignment::map([['episode_no'=>1,'content'=>$envelope],['episode_no'=>2,'content'=>$envelope]],$source);
        self::assertSame($source,implode('',array_column($mapped,'source_content')));
        Guard::assertSources(['episodes'=>$mapped],['_file_parse_fallback'=>['text'=>$source]]);
    }
    public function testRepeatedWholeDocumentWithoutHeadingsRetainsEvidenceBasedAlignment(): void
    {
        $source="少女在寒潭救起陌生人。\n他醒来后向少女道谢。\n少女开设书店，收集城市旧书。\n他们共同经营书店。\n";
        $mapped=Alignment::map([
            ['episode_no'=>1,'content'=>$source,'summary'=>'少女在寒潭救起陌生人，他醒来后道谢。'],
            ['episode_no'=>2,'content'=>$source,'summary'=>'少女开设书店，收集城市旧书。']],$source);
        self::assertSame('outline_alignment',$mapped[0]['source_scope']['kind']);
        self::assertSame($source,implode('',array_column($mapped,'source_content')));
    }
    public function testRepeatedDialogueRequiresAnIndependentlyMatchingRange(): void
    {
        $source="开场设定。\n请开门。\n甲放下旧信。\n请开门。\n乙拿出日记。\n";
        $mapped=Alignment::map([
            ['episode_no'=>1,'content'=>'请开门。','source_range'=>['start_line'=>2,'end_line'=>2]],
            ['episode_no'=>2,'content'=>'请开门。','source_range'=>['start_line'=>4,'end_line'=>4]]],$source);
        self::assertSame($source,implode('',array_column($mapped,'source_content')));
        $this->expectExceptionCode(422);
        Alignment::map([['episode_no'=>1,'content'=>'请开门。']],$source);
    }
    /** @dataProvider invalidReceipts */
    public function testChangedReorderedOverlappingAndMissingExcerptsFailClosed(string $case): void
    {
        $episodes=$this->episodes();
        if($case==='changed')$episodes[0]['content']='甲烧毁旧信，决定离开。';
        if($case==='reordered'){[$episodes[0]['content'],$episodes[1]['content']]=[$episodes[1]['content'],$episodes[0]['content']];}
        if($case==='overlap')$episodes[1]['content']=$episodes[0]['content'];
        if($case==='missing')unset($episodes[0]['content']);
        if($case==='numbering')$episodes[1]['episode_no']=3;
        $this->expectExceptionCode(422);Alignment::map($episodes,$this->source());
    }
    public function invalidReceipts(): array { return [['changed'],['reordered'],['overlap'],['missing'],['numbering']]; }
    public function testModifiedPersistedScopeIsNeverSilentlyRepaired(): void
    {
        $mapped=Alignment::map($this->episodes(),$this->source());$mapped[0]['source_content'].='篡改。';
        $this->expectExceptionCode(422);Alignment::map($mapped,$this->source());
    }
    public function testScopeFromAnotherUploadCannotReplaceFrozenDocument(): void
    {
        $mapped=Alignment::map($this->episodes(),$this->source());
        $this->expectExceptionCode(422);Alignment::map($mapped,'其他文档。'.$this->source());
    }
    public function testHistoricalReceiptWithoutFrozenUploadKeepsExistingAdapterBehavior(): void
    {
        $raw=$this->rawPlan();$raw['episodes'][0]['source_range']=['start_line'=>1,'end_line'=>2];$raw['episodes'][1]['source_range']=['start_line'=>3,'end_line'=>4];
        $plan=(new \ReflectionMethod(Service::class,'fileQaParsePlan'))->invoke(null,$raw,[],'旧信');
        self::assertSame($raw['episodes'][0]['content'],$plan['episodes'][0]['source_content']);
    }
}
