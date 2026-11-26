<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;

final class SaleVideoWorkflow
{
    /** Only product detail links can start a product import. */
    public static function target(string $input): array
    {
        $input = trim($input);
        $parts = parse_url($input);
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($host, ['www.tiktok.com', 'tiktok.com', 'shop.tiktok.com', 'shop.tiktokglobalshop.com'], true)) {
            return ProductVideoAmazon::target($input);
        }
        $path = $parts['path'] ?? '';
        if (strlen($input) > 2000 || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['port']) || preg_match('/[\x00-\x20\\\\]/', $input)
            || !preg_match('~^/(?:view/product|shop/product|(?:shop/)?(?:[a-z]{2}/)?pdp(?:/[^/?#]+)?)/[0-9]+/?$~iD', $path)) {
            throw new \InvalidArgumentException('请填写 TikTok Shop 商品详情页链接');
        }
        return ['url'=>'https://'.$host.$path, 'provider'=>'tiktok'];
    }

    public static function fetchShop(string $url, bool $image): array
    {
        $page = \app\common\service\open_platform\OpenSafeHttp::get($url, $image ? 10485760 : 2097152, 15);
        if (!$image && (self::target($page['url'])['provider'] ?? '') !== 'tiktok') {
            throw new \InvalidArgumentException('商品页面暂不可读取，请手动上传商品图');
        }
        return $page;
    }

    public const DIRECTION = '全片由同一位出镜者亲自讲解和展示商品。出镜者看向镜头，用目标语言与观众说话，拿起、佩戴或使用商品时，用手上的动作说明正在讲的点。'
        .'正脸说话时口型同步，切到手部或商品细节时由本人接着说。保持人物外观、声音、商品结构及操作前后的状态连贯。双手操作时固定机位，留出能看清动作的时间。';
    public const UGC = '以第一人称和观众说话，像给朋友看手边的一件东西。用手机自拍或把手机架在身旁，选择日常使用商品时自然会有的视角。根据商品和参考图挑一个能当场演示的特点，说到它时就展示给观众看。'
        .'可以从一个实际问题、一个动作或一个细节开场。场景、人物造型和镜头数量随素材及片长决定。结尾可以停在演示结果上，适合时再自然邀请观众看看。'
        .'用目标语言里自然、能在所选时长内说完的短句。只讲有依据的特点，不编造亲身经历、测评结论、优惠或功效。';
    public const WRITE = '写一份可直接拍摄的带货口播脚本。保留用户已写的台词、动作和语气，补足拍摄细节。结合销售地区选用自然的表达，台词使用目标语言。'
        .'以输入 duration（秒）为总时长。从 0 秒连续写到选定时长，按自然语速试读台词，给演示和停顿留时间。很短时只写一镜、一个动作和一句短话；片长有余量再切细节或补充用法。'
        .'先用一两句交代谁在什么场景展示什么，再逐镜写起止秒数、机位、出镜者的具体动作，并给出口播或本人画外音的原句。按需引用参考图，从 image_mentions 选取已有名称，前面加 @，说明画面要对准的细节。'
        .'只写与本件商品有关的连续性要求，如扣件的位置或穿戴方向。默认保留商品原标识，画面不额外叠字，用户明确要求字幕时按要求处理。'
        .'只输出合法 JSON 对象：video_prompt 是含完整多行脚本的字符串，duration_seconds 是脚本实际结束的秒数。';
    public const SCRIPT_FORMAT_ERROR = '模型已返回内容，但未能从中读取完整的口播脚本。';

    public static function parseScript(string $json, array $request): array
    {
        $data = json_decode($json, true, 64);
        if (!is_array($data) || array_is_list($data)) throw new \RuntimeException(self::SCRIPT_FORMAT_ERROR);
        $prompt = self::scriptText($data['video_prompt'] ?? null);
        $duration = $data['duration_seconds'] ?? null;
        if ($prompt === '') {
            $shots = $data['shots'] ?? null;
            if (!is_array($shots) || !$shots || !array_is_list($shots)) throw new \RuntimeException(self::SCRIPT_FORMAT_ERROR);
            $lines = [self::scriptText($data['video_title'] ?? null), self::scriptText($data['concept'] ?? null)];
            $notes = is_array($data['production_notes'] ?? null) ? $data['production_notes'] : [];
            foreach (['outfit'=>'服装', 'hairstyle_and_makeup'=>'妆发', 'location_setup'=>'场景', 'lighting_style'=>'光线', 'pacing_and_transitions'=>'节奏与转场'] as $key => $label) {
                $note = self::scriptText($notes[$key] ?? null);
                if ($note !== '') $lines[] = $label.'：'.$note;
            }
            $mentions = array_column($request['snapshot']['images'] ?? [], 'mention_name', 'reference_slot_id');
            $hasSpeech = false;
            $lastEnd = null;
            foreach ($shots as $index => $shot) {
                if (!is_array($shot)) throw new \RuntimeException(self::SCRIPT_FORMAT_ERROR);
                $speech = self::scriptText($shot['spoken_dialogue_or_voiceover_script'] ?? $shot['dialogue'] ?? $shot['voiceover'] ?? null);
                $visual = self::scriptText($shot['visual_description'] ?? $shot['description'] ?? null);
                if ($speech === '' && $visual === '') throw new \RuntimeException(self::SCRIPT_FORMAT_ERROR);
                $hasSpeech = $hasSpeech || $speech !== '';
                $start = $shot['start_time'] ?? $shot['start'] ?? null;
                $end = $shot['end_time'] ?? $shot['end'] ?? null;
                $heading = '镜头 '.($index + 1);
                if (is_numeric($start) && is_numeric($end) && $start >= 0 && $end > $start) {
                    $heading .= '（'.$start.'–'.$end.' 秒）';
                    $lastEnd = max((float)$lastEnd, (float)$end);
                }
                foreach ((array)($shot['source_slot_ids'] ?? $shot['source_slots'] ?? []) as $slot) {
                    if (is_string($slot) && isset($mentions[$slot])) $heading .= ' @'.$mentions[$slot];
                }
                $lines[] = "\n".$heading;
                if ($visual !== '') $lines[] = $visual;
                foreach (['camera_movement'=>'运镜', 'on_screen_text'=>'画面文字'] as $key => $label) {
                    $value = self::scriptText($shot[$key] ?? null);
                    if ($value !== '') $lines[] = $label.'：'.$value;
                }
                if ($speech !== '') $lines[] = '口播：'.$speech;
            }
            if (!$hasSpeech) throw new \RuntimeException(self::SCRIPT_FORMAT_ERROR);
            $prompt = trim(implode("\n", array_filter($lines, static fn($line) => $line !== '')));
            if ($lastEnd !== null) $duration = max($lastEnd, is_numeric($duration) ? (float)$duration : 0);
        }
        $result = ['video_prompt'=>ProductVideoPolicy::text($prompt, 12000)];
        $requested = (float)($request['duration'] ?? 0);
        if ($requested > 0 && is_numeric($duration) && $duration > 0 && abs($requested - (float)$duration) > 0.01) {
            $result['notice'] = '原脚本时长为 '.(float)$duration.' 秒，帮写时选定的视频时长为 '.$requested.' 秒。可精简台词或调整视频时长。';
        }
        return $result;
    }

    private static function scriptText(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
