<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service, ShortDramaEpisodeDuration as Duration,
    ShortDramaFileModelParser as Parser, ShortDramaImportedScript as Imported, ShortDramaSourceAlignment as Alignment,
    ShortDramaTimedScriptGeneration as Generation};
use PHPUnit\Framework\TestCase;

/** Local files and deterministic model receipts; never calls a provider. */
final class ShortDramaUploadedFileCompatibilityTest extends TestCase
{
    private function invoke(string $method, ...$arguments)
    {
        return (new \ReflectionMethod(Service::class, $method))->invoke(null, ...$arguments);
    }

    /** @dataProvider fileFormats */
    public function testFilesReachConfirmedEpisodesAndStoryboardWithoutLosingSource(string $extension, string $format): void
    {
        $first = "第一集 旧信\n00:00:00,000 --> 00:00:00,250\n甲转头。\n00:00:00,250 --> 00:00:05,000\n甲找到旧信。\n";
        $second = "第二集 故人\n乙走进旧宅，停顿3-5秒后打开日记。";
        $source = $first . $second;
        $bytes = $format === 'gbk' ? mb_convert_encoding($source, 'GBK', 'UTF-8') : "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $source);
        $path = tempnam(sys_get_temp_dir(), 'drama-upload-');
        try {
            if ($extension === 'docx') $this->docx($path, implode('', array_map(fn($line) => $this->paragraph($line), explode("\n", $source))));
            else file_put_contents($path, $bytes);
            $text = $this->invoke('normalizeUploadedScriptText', $this->invoke('readUploadedScriptText', $path, $extension));
            self::assertSame($source, $text);
            $start = count(Alignment::lines($first));
            $plan = Parser::attachSource($this->plan($start, count(Alignment::lines($text))), $text);
            $plan = $this->invoke('fileQaParsePlan', $plan, [], '旧信');
            $confirmed = Imported::confirmedOutline($plan, Imported::storyDraft($plan));
            self::assertSame($text, implode('', array_column($confirmed['episodes'], 'source_content')));
            $request = ['_upload_timing_rule_snapshot' => Duration::defaults()];
            foreach ($confirmed['episodes'] as $index => $episode) {
                $policy = $this->invoke('uploadedEpisodeTimingPolicy', $request, $episode, Duration::snapshot([], 0, 0, []));
                self::assertSame($index === 0 ? 'timeline' : 'default', $policy['source']);
                $durations = $index === 0 ? [0.25,4.75] : [5,5];
                $generated = $this->generate($policy, $durations);
                self::assertEquals($durations, array_column($generated['storyboard'], 'recommended_duration_seconds'));
                self::assertSame($episode['source_content'], $plan['episodes'][$index]['source_content']);
            }
        } finally { @unlink($path); }
    }

    public function fileFormats(): array
    {
        return [['txt','utf8'], ['txt','gbk'], ['md','utf8'], ['docx','utf8']];
    }

    public function testWordTablesKeepStartAndEndColumnsOnTheSameRow(): void
    {
        $rows = [['镜头','开始时间','结束时间','内容'], ['1','00:00','00:05','甲走入房间。'], ['2','00:05','00:12','乙打开旧信。']];
        $xml = $this->paragraph('第一集 旧信') . '<w:tbl>';
        foreach ($rows as $cells) {
            $xml .= '<w:tr>';
            foreach ($cells as $cell) $xml .= '<w:tc>' . $this->paragraph($cell) . '</w:tc>';
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl>' . $this->paragraph('第二集 故人') . $this->paragraph('乙找到故人。');
        $path = tempnam(sys_get_temp_dir(), 'drama-table-');
        try {
            $this->docx($path, $xml);
            $text = $this->invoke('normalizeUploadedScriptText', $this->invoke('readUploadedScriptText', $path, 'docx'));
            self::assertStringContainsString("1\t00:00\t00:05\t甲走入房间。", $text);
            $end = count(Alignment::lines(explode('第二集', $text)[0]));
            $plan = Parser::attachSource($this->plan($end, count(Alignment::lines($text))), $text);
            $plan = $this->invoke('fileQaParsePlan', $plan, [], '旧信');
            $policy = $this->invoke('uploadedEpisodeTimingPolicy', ['_upload_timing_rule_snapshot'=>Duration::defaults()], $plan['episodes'][0], Duration::snapshot([],0,0,[]));
            self::assertSame([5,7], array_column($policy['timeline_segments'], 'duration_seconds'));
            self::assertStringContainsString('乙打开旧信', $policy['timeline_segments'][1]['text']);
            self::assertSame($text, implode('', array_column($plan['episodes'], 'source_content')));
        } finally { @unlink($path); }
    }

    public function testAiPartitionsWithoutEpisodeHeadingsRetainIndependentTiming(): void
    {
        $text = "00:00-00:05 甲入场。\n00:05-00:10 甲离场。\n00:00-00:03 乙入场。\n00:03-00:08 乙离场。";
        $plan = Parser::attachSource($this->plan(2,4), $text);
        $plan = $this->invoke('fileQaParsePlan', $plan, ['parse_mode'=>'split_by_ai'], '旧信');
        $confirmed = Imported::confirmedOutline($plan, Imported::storyDraft($plan));
        foreach ($confirmed['episodes'] as $index => $episode) {
            $policy = $this->invoke('uploadedEpisodeTimingPolicy', ['_upload_timing_rule_snapshot'=>Duration::defaults()], $episode, Duration::snapshot([],0,0,[]));
            self::assertEquals($index === 0 ? 10 : 8, $policy['target_seconds']);
            self::assertSame(0,$policy['timeline_segments'][0]['start_seconds']);
        }
        self::assertSame($text,implode('',array_column($confirmed['episodes'],'source_content')));
    }

    private function plan(int $firstEnd, int $lastEnd): array
    {
        return ['title'=>'旧信','type_judgement'=>'悬疑','core_theme'=>'寻找真相','story_outline'=>'调查员寻找故人，发现旧信中的秘密。',
            'subjects'=>[['id'=>'a','name'=>'甲','category'=>'character','description'=>'寻找故人的调查员']],
            'locations'=>[['id'=>'room','name'=>'旧宅','description'=>'堆满旧信的旧宅']],
            'episodes'=>[
                ['episode_no'=>1,'title'=>'旧信','story_outline'=>'甲在旧宅发现旧信。','conflict_point'=>'署名与记忆矛盾。','ending_hook'=>'信中出现故人的地址。','source_range'=>['start_line'=>1,'end_line'=>$firstEnd]],
                ['episode_no'=>2,'title'=>'故人','story_outline'=>'乙赶往旧宅找到故人。','conflict_point'=>'故人拒绝开口。','ending_hook'=>'故人递出日记。','source_range'=>['start_line'=>$firstEnd+1,'end_line'=>$lastEnd]],
            ]];
    }

    private function generate(array $policy, array $durations): array
    {
        $skeleton = ['title'=>'旧信','story_outline'=>'甲寻找旧信并离开。','script_lines'=>['甲找到旧信。'],
            'subjects'=>[['id'=>'a','name'=>'甲']], 'locations'=>[['id'=>'room','name'=>'旧宅']],
            'scene_beats'=>array_map(static fn($seconds)=>['scene_ref_id'=>'room','goal'=>'寻找旧信','entry'=>'入场','exit'=>'离开',
                'key_events'=>['找到旧信'],'duration_seconds'=>$seconds,'shot_durations'=>[$seconds]],$durations)];
        return Generation::generate(['episode_duration_policy'=>$policy], ['system_prompt'=>'创作','content'=>'甲寻找旧信。'],
            static function ($key,$input) use ($skeleton) {
                if (str_contains($key,'skeleton')) return $skeleton;
                $context = json_decode(explode("\n以上全局剧情",$input['content'])[0],true);
                return ['storyboard'=>array_map(static fn($shot)=>['shot_id'=>$shot['shot_id'],'scene_ref_id'=>'room',
                    'subject_ref_ids'=>['a'],'visual_description'=>'甲完成寻找旧信的动作','dialogue'=>'',
                    'recommended_duration_seconds'=>$shot['duration_seconds']],$context['required_shots'])];
            },null);
    }

    private function paragraph(string $text): string
    {
        return '<w:p><w:r><w:t>' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r></w:p>';
    }

    private function docx(string $path, string $body): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE));
        $zip->addFromString('word/document.xml','<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
    }
}
