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

    public const DIRECTION = '制作有人声的出镜带货短视频。用目标语言自然说出台词；正脸讲话时口型同步，商品特写时延续同一声音。按选定时长安排能说完的短句，留出实际演示时间。主体、手持或佩戴方式与引用图片一致。';
    public const UGC = '以使用者第一人称自拍分享。开头用一句与商品相关的生活场景引起兴趣，中段实际演示一个有依据的卖点，穿插对应商品细节，最后自然收尾。使用口语，不编造使用经历、价格或效果。';
    public const WRITE = '按输入地区和目标语言写可直接拍摄的口播脚本。以输入 duration（秒）为成片总时长，从 0 秒写到所选时长。时长很短时用单镜头、一个动作和一句能说完的短台词。整体设定说明出镜人、场景和商品外观，逐镜写时间、动作、对应的 @图片名称、正脸台词或画外音。只引用提供的素材，遵守用户已有台词及事实。只输出一个 JSON 对象。必填 video_prompt 字符串，内含完整的多行可编辑脚本，另用数字字段 duration_seconds 填写脚本实际结束秒数。';
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
