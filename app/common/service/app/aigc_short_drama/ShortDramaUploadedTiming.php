<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Timing adapter for the confirmed original of one uploaded episode. */
final class ShortDramaUploadedTiming
{
    private const NUMBER = '[0-9０-９]+(?:[.,，．][0-9０-９]{1,3})?';
    private const SEPARATOR = '(?:--?>|→|[-－‐‑‒–—−~～至到])';

    public static function read(string $source): array
    {
        $token = self::token();
        $pattern = '/(?<![0-9０-９:：.,])(' . $token . ')\s*' . self::SEPARATOR . '\s*(' . $token . ')(?![0-9０-９:：.,])/iu';
        preg_match_all('/[^\n]*(?:\n|$)/u', $source, $lines, PREG_OFFSET_CAPTURE);
        $candidates = []; $duration = 0.0; $columns = null; $overviewSection = false;
        foreach ($lines[0] as [$originalLine, $offset]) {
            $line = rtrim($originalLine, "\r\n");
            if ($line === '') continue;
            $heading = preg_replace('/^[\s#>*_]+|[\s:：*_]+$/u', '', $line);
            if (preg_match('/^(?:时间轴|时间轴概览|时间码概览|时间轴总览|叙事节奏|节奏表|时间安排|timeline\s*(?:overview)?)$/iu', $heading)) {
                $overviewSection = true; continue;
            }
            if (preg_match('/^(?:详细分镜|分镜脚本|详细时间轴|镜头脚本|正文|分镜详解|分镜表|镜头详解)$/u', $heading)) {
                $overviewSection = false; continue;
            }
            if (preg_match('/^\s*(?:[>#*]+\s*|[-+]\s+)?(?:镜头|鏡頭|分镜|分鏡|shot)\s*[0-9０-９一二三四五六七八九十百]+/iu', $line)) $overviewSection = false;
            $declared = self::declaredDuration($line);
            if ($declared !== null) {
                if ($duration > 0 && abs($duration - $declared) > 0.001) throw new RuntimeException('本集原文的时长要求存在冲突，请核对', 422);
                $duration = $declared;
            }
            $cells = preg_split('/[|｜\t]/u', trim($line, " |\t"));
            $cells = array_map('trim', $cells);
            $startColumn = $endColumn = null;
            foreach ($cells as $index => $cell) {
                if (preg_match('/^(?:开始(?:时间)?|起始(?:时间)?|入点|start)(?:[（(].*[)）])?$/iu', $cell)) $startColumn = $index;
                if (preg_match('/^(?:结束(?:时间)?|终止(?:时间)?|出点|end)(?:[（(].*[)）])?$/iu', $cell)) $endColumn = $index;
            }
            if ($startColumn !== null && $endColumn !== null) { $columns = [$startColumn, $endColumn]; continue; }
            if ($columns && isset($cells[$columns[0]], $cells[$columns[1]])
                && preg_match('/^(?:' . $token . ')$/iu', $cells[$columns[0]]) && preg_match('/^(?:' . $token . ')$/iu', $cells[$columns[1]])) {
                [$startCell, $endCell] = [$cells[$columns[0]], $cells[$columns[1]]];
                $candidate = self::segment($startCell, $endCell, $startCell . '-' . $endCell);
                unset($cells[$columns[0]], $cells[$columns[1]]);
                $candidates[] = $candidate + ['offset'=>$offset, 'content_start'=>$offset + strlen($originalLine), 'prefix'=>implode(' ', $cells), 'overview'=>false];
                continue;
            }
            // Do not read "镜头1：00:00" as the clock "1：00". Scan
            // beyond the structural label while keeping original byte offsets.
            $labelLength = preg_match('/^\s*(?:[>#*]+\s*|[-+]\s+)?(?:(?:第\s*)?(?:镜头|鏡頭|分镜|分鏡|场景|場景|shot|scene)\s*[0-9０-９一二三四五六七八九十百]+|时间码|時間碼|时间段|时间区间|时间|timecode)\s*[:：|｜]\s*/iu', $line, $label) ? strlen($label[0]) : 0;
            if (!preg_match_all($pattern, substr($line, $labelLength), $matches, PREG_OFFSET_CAPTURE)) continue;
            $beforeRange = substr($line, 0, $labelLength + $matches[0][0][1]);
            $overview = $overviewSection || preg_match('/时间轴|时间码概览|叙事节奏|节奏表|节奏[：:]|时间安排|timeline/iu', $beforeRange);
            foreach ($matches[0] as $index => [$range, $position]) {
                $position += $labelLength;
                $startText = $matches[1][$index][0]; $endText = $matches[2][$index][0];
                if (!preg_match('/[:：a-z秒分时]/iu', $startText . $endText)) continue;
                if (preg_match('/^\s*(?:后|内|左右|之间)/u', substr($line, $position + strlen($range)))) continue;
                $prefix = substr($line, 0, $position);
                if (!$overview && !self::atTimingPosition($prefix)) continue;
                $candidate = self::segment($startText, $endText, $range);
                $candidates[] = $candidate + ['offset'=>$offset + $position, 'content_start'=>$offset + $position + strlen($range), 'prefix'=>'', 'overview'=>(bool)$overview];
            }
        }
        // A compact rhythm overview must not duplicate the actual shot list.
        if (array_filter($candidates, static fn($item) => !$item['overview'])) {
            $candidates = array_values(array_filter($candidates, static fn($item) => !$item['overview']));
        }
        $segments = [];
        foreach ($candidates as $index => $candidate) {
            $end = $candidates[$index + 1]['offset'] ?? strlen($source);
            $text = trim($candidate['prefix'] . ' ' . substr($source, $candidate['content_start'], max(0, $end - $candidate['content_start'])));
            $segments[] = ['index'=>$index + 1] + array_intersect_key($candidate, array_flip(['time_range','start_seconds','end_seconds','duration_seconds']))
                + ['text'=>mb_substr($text, 0, 1200, 'UTF-8')];
        }
        return ['timeline'=>ShortDramaImportedScript::normalizeTimeline($segments), 'duration'=>$duration];
    }

    private static function token(): string
    {
        $n = self::NUMBER;
        $seconds = '(?:秒钟|秒鐘|秒|seconds?|sec|s)';
        $minutes = '(?:分钟|分鐘|分|minutes?|min|m)';
        $hours = '(?:小时|小時|时|時|hours?|hr|h)';
        $clock = '[0-9０-９]{1,3}[：:][0-9０-９]{1,2}(?:[：:][0-9０-９]{1,2})?(?:[.,，．][0-9０-９]{1,3})?';
        return '-?(?:' . $clock . '|'
            . $n . '\s*' . $hours . '(?:\s*' . $n . '\s*' . $minutes . ')?(?:\s*' . $n . '\s*' . $seconds . ')?|'
            . $n . '\s*' . $minutes . '(?:\s*' . $n . '\s*' . $seconds . ')?|'
            . $n . '\s*' . $seconds . '|' . $n . ')';
    }

    private static function atTimingPosition(string $prefix): bool
    {
        $prefix = preg_replace('/^\s*(?:[>#*]+\s*|[-+]\s+)/u', '', $prefix);
        $prefix = preg_replace('/^\s*(?:(?:第\s*)?(?:镜头|鏡頭|分镜|分鏡|场景|場景|shot|scene)\s*[0-9０-９一二三四五六七八九十百]+|时间码|時間碼|时间段|时间区间|时间|timecode)\s*/iu', '', $prefix);
        $prefix = preg_replace('/^\s*[|｜]?\s*[0-9０-９]+\s*[|｜]\s*/u', '', $prefix);
        $prefix = preg_replace('/^\s*[0-9０-９]+[.)、]\s+/u', '', $prefix);
        return preg_match('/^[\s|｜:：.、\[【(（*_]*$/u', $prefix) === 1;
    }

    private static function segment(string $startText, string $endText, string $range): array
    {
        $start = self::seconds($startText); $end = self::seconds($endText);
        if ($end <= $start) throw new RuntimeException('剧本时间码起止或时长无效，请核对', 422);
        return ['time_range'=>$range, 'start_seconds'=>$start, 'end_seconds'=>$end, 'duration_seconds'=>self::number($end - $start)];
    }

    private static function seconds(string $value): int|float
    {
        $value = strtr(trim($value), ['０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9','：'=>':','，'=>'.',','=>'.','．'=>'.']);
        if (str_starts_with($value, '-')) throw new RuntimeException('剧本时间码不能为负数，请核对', 422);
        if (str_contains($value, ':')) {
            $parts = explode(':', $value);
            if (count($parts) < 2 || count($parts) > 3) throw new RuntimeException('剧本时间码格式无效，请核对', 422);
            $hasHours = count($parts) === 3;
            $seconds = (float)array_pop($parts); $minutes = (int)array_pop($parts); $hours = (int)array_pop($parts);
            if ($seconds >= 60 || ($hasHours && $minutes >= 60)) throw new RuntimeException('剧本时间码格式无效，请核对', 422);
            return self::number($hours * 3600 + $minutes * 60 + $seconds);
        }
        if (is_numeric($value)) return self::number((float)$value);
        preg_match_all('/([0-9]+(?:\.[0-9]+)?)\s*(小时|小時|时|時|hours?|hr|h|分钟|分鐘|分|minutes?|min|m|秒钟|秒鐘|秒|seconds?|sec|s)/iu', $value, $matches, PREG_SET_ORDER);
        if (!$matches) throw new RuntimeException('剧本时间码格式无效，请核对', 422);
        $total = 0;
        foreach ($matches as $match) {
            $factor = preg_match('/^(?:小时|小時|时|時|hours?|hr|h)$/iu', $match[2]) ? 3600 : (preg_match('/^(?:分钟|分鐘|分|minutes?|min|m)$/iu', $match[2]) ? 60 : 1);
            $total += (float)$match[1] * $factor;
        }
        return self::number($total);
    }

    private static function number(float $value): int|float
    {
        $value = round($value, 3);
        return abs($value - round($value)) < 0.000001 ? (int)round($value) : $value;
    }

    private static function declaredDuration(string $line): ?float
    {
        $line = preg_replace('/^[\s#>*_|｜]+|[\s*_｜|]+$/u', '', $line);
        $label = '(?:总时长|總時長|时长|時長|片长|片長|duration)\s*(?:为|是|约|:|：)?\s*';
        $episode = '(?:(?:本集|单集|第\s*[0-9０-９一二三四五六七八九十百]+\s*集)\s*)?';
        if (!preg_match('/^' . $episode . $label . '(' . self::token() . ')/iu', $line, $match)) {
            if (!preg_match('/^第\s*[0-9０-９一二三四五六七八九十百]+\s*集/u', $line)
                || !preg_match('/[（(]\s*' . $label . '(' . self::token() . ')/iu', $line, $match)) return null;
        }
        if (!preg_match('/[:：a-z秒分时]/iu', $match[1])) return null;
        $seconds = (float)self::seconds($match[1]);
        if ($seconds <= 0) throw new RuntimeException('本集原文缺少有效时长，请核对', 422);
        return $seconds;
    }
}
