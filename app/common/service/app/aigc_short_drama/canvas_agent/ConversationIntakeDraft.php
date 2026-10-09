<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Model output is only an untrusted draft for the owner to review. */
final class ConversationIntakeDraft
{
    public static function availableSources(array $context): array
    {
        $sources=['message'];
        $messages=(array)($context['messages']??[]);
        $last=$messages ? end($messages) : [];
        foreach ((array)($last['attachments']??[]) as $attachment) {
            if (($attachment['type']??'')==='text') $sources[]='document';
            if (($attachment['type']??'')==='image') $sources[]='image';
        }
        if (!empty($context['attachment_images'])) $sources[]='image';
        foreach ((array)($context['selected_nodes']??[]) as $node) {
            if (($node['type']??'')==='image' && !empty($node['image_asset'])) $sources[]='image';
        }
        return array_values(array_unique($sources));
    }

    public static function instruction(array $slots): string
    {
        $labels=[];
        foreach ($slots as $slot) if (is_array($slot) && is_string($slot['key']??null)) {
            $labels[]=$slot['key'].'（'.(string)($slot['label']??'').'）';
        }
        return '结合对话中用户明确表达的创作要求、已授权附件和实际可见图片提取短剧设定，不把猜测当事实。'
            .'回顾已有用户消息，已明确的信息以 source=message 提取并直接沿用；用户后续明确修改时以最新要求为准。助手建议和默认值不代表用户确认。'
            .'例如用户说“一集60s的短视频，我在天庭当人事”，主题、集数和时长已有答案，不得再询问这些项目。'
            .'若文档尚未解析或图像不可见，不得声称读取了它。'
            .'intake 必须为 {"candidates":[],"questions":[]}。candidates 每项仅含 key、value、source、evidence、confidence；'
            .'key 限于 '.implode('、',$labels).'；source 可以是 message、document、image；未指定项的建议必须用 suggestion，不能伪称用户事实；'
            .'evidence 是简短原文摘录或可见画面依据；confidence 为 0 到 1。只有明确且高置信的事实才放入 candidates。'
            .'questions 每项仅含 key、ask、options；只针对尚未明确的信息，ask 要结合用户灵感与已有素材，options 最多五项。'
            .'不得输出费用、任务状态、媒体调用或画布 JSON；仅对尚未明确的信息及素材推断请求核对，用户在对话中明确的信息不重复展示为收集卡。';
    }

    /** Recover independent valid draft items; never infer missing evidence,
     * change source ownership, or promote an invalid fact to confirmed data. */
    public static function recover(mixed $raw,array $slots,array $availableSources=['message']): array
    {
        $result=['candidates'=>[],'questions'=>[]];
        if (!is_array($raw)) return $result;
        foreach (array_keys($result) as $group) {
            if (!is_array($raw[$group]??null) || !array_is_list($raw[$group])) continue;
            foreach (array_slice($raw[$group],0,count($slots)) as $item) {
                $candidate=$result;
                $candidate[$group][]=$item;
                try { self::parse($candidate,$slots,$availableSources);$result=$candidate; }
                catch (RuntimeException $error) { /* Leave this item unconfirmed. */ }
            }
        }
        return $result;
    }

    /** @return array{candidates:array,questions:array} */
    public static function parse(mixed $raw,array $slots,array $availableSources=['message']): array
    {
        if (!is_array($raw) || count($raw)!==2 || array_diff(['candidates','questions'],array_keys($raw))
            || !is_array($raw['candidates']) || !array_is_list($raw['candidates'])
            || !is_array($raw['questions']) || !array_is_list($raw['questions'])
            || count($raw['candidates'])>count($slots) || count($raw['questions'])>count($slots)) throw new RuntimeException('INVALID_AGENT_INTAKE');
        $allowed=[];
        foreach ($slots as $slot) if (is_array($slot) && is_string($slot['key']??null)) $allowed[$slot['key']]=true;
        $candidates=[];$questions=[];
        foreach ($raw['candidates'] as $item) {
            if (!is_array($item) || count($item)!==5 || array_diff(['key','value','source','evidence','confidence'],array_keys($item))) throw new RuntimeException('INVALID_AGENT_INTAKE');
            ['key'=>$key,'value'=>$value,'source'=>$source,'evidence'=>$evidence,'confidence'=>$confidence]=$item;
            if (!is_string($key) || !isset($allowed[$key]) || isset($candidates[$key]) || !is_string($value)
                || trim($value)==='' || mb_strlen($value)>240 || !is_string($source)
                || !in_array($source,array_merge($availableSources,['suggestion']),true) || !is_string($evidence)
                || trim($evidence)==='' || mb_strlen($evidence)>160 || (!is_float($confidence) && !is_int($confidence))
                || $confidence<0 || $confidence>1) throw new RuntimeException('INVALID_AGENT_INTAKE');
            // Low-confidence material remains a question, never an inferred answer.
            if ($confidence>=0.8) $candidates[$key]=['value'=>trim($value),'source'=>$source,'evidence'=>trim($evidence)];
        }
        foreach ($raw['questions'] as $item) {
            if (!is_array($item) || count($item)!==3 || array_diff(['key','ask','options'],array_keys($item))) throw new RuntimeException('INVALID_AGENT_INTAKE');
            ['key'=>$key,'ask'=>$ask,'options'=>$options]=$item;
            if (!is_string($key) || !isset($allowed[$key]) || isset($questions[$key]) || !is_string($ask)
                || trim($ask)==='' || mb_strlen($ask)>160 || !is_array($options) || !array_is_list($options)
                || count($options)>5) throw new RuntimeException('INVALID_AGENT_INTAKE');
            foreach ($options as $option) if (!is_string($option) || trim($option)==='' || mb_strlen($option)>60) throw new RuntimeException('INVALID_AGENT_INTAKE');
            if (!isset($candidates[$key])) $questions[$key]=['ask'=>trim($ask),'options'=>array_values(array_unique($options))];
        }
        return ['candidates'=>$candidates,'questions'=>$questions];
    }

    public static function parseConversation(string $response,array $slots,array $availableSources=['message']): array
    {
        try { $value=ConversationResponseJson::decode($response,16); }
        catch (\Throwable $error) { throw new RuntimeException('INVALID_AGENT_INTAKE',0,$error); }
        if (!is_array($value) || array_is_list($value)
            || array_diff(array_keys($value),['reply_markdown','intake'])) throw new RuntimeException('INVALID_AGENT_INTAKE');
        $value['intake']=self::recover($value['intake']??null,$slots,$availableSources);
        return self::parseDirect(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$slots,$availableSources);
    }

    public static function parseDirect(string $response,array $slots,array $availableSources=['message']): array
    {
        try { $value=ConversationResponseJson::decode($response,16); }
        catch (\Throwable $error) { throw new RuntimeException('INVALID_AGENT_INTAKE',0,$error); }
        if (!is_array($value) || count($value)!==2 || array_diff(['reply_markdown','intake'],array_keys($value)) || !is_string($value['reply_markdown'])
            || trim($value['reply_markdown'])==='' || mb_strlen($value['reply_markdown'])>4000) throw new RuntimeException('INVALID_AGENT_INTAKE');
        self::parse($value['intake'],$slots,$availableSources);
        return ['reply_markdown'=>trim($value['reply_markdown']),'intake'=>$value['intake']];
    }
}
