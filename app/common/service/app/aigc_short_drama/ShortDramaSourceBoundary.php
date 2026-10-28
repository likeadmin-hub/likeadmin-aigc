<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Validate provider imports before any episode can become paid generation input. */
final class ShortDramaSourceBoundary
{
    public static function text(string $source): string
    {
        // Some file parsers return a numbered JSON file envelope as one line.
        // Decode only a recognized envelope, never arbitrary screenplay JSON.
        $candidate = preg_replace('/^\s*L\d{6,}\s+/u', '', $source);
        for ($i = 0; $i < 3; $i++) {
            $value = json_decode($candidate, true);
            if (!is_array($value) || !is_string($value['content'] ?? null)
                || !(($value['type'] ?? '') === 'file' || isset($value['filename'], $value['file_type']))) break;
            $source = $value['content'];
            $candidate = $source;
        }
        return $source;
    }

    /** Offsets refer to decoded source bytes; line numbers to the decoded document. */
    public static function sections(string $source): array
    {
        preg_match_all('/^[\t ]*(?:L\d{6,}[\t ]+)?(?:#{1,6}[\t ]*)?(?:\*\*|__)?第[\t ]*([0-9０-９一二三四五六七八九十百零〇两]+)[\t ]*集(?:\*\*|__)?(?:[\t :：·—（(][^\r\n]*)?$/mu', $source, $matches, PREG_OFFSET_CAPTURE);
        $sections = [];
        foreach ($matches[0] as $index => [$heading, $offset]) {
            $number = self::number($matches[1][$index][0]);
            if ($number < 1 || $number > 500 || isset($sections[$number])) {
                throw new RuntimeException('剧本分集标题重复或无法识别，请核对原文后重新解析', 422);
            }
            $end = $matches[0][$index + 1][1] ?? strlen($source);
            $body = substr($source, $offset, $end - $offset);
            $sections[$number] = ['source_content' => $body, 'source_range' => [
                'start_line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'end_line' => substr_count(substr($source, 0, $end), "\n") + ($end === strlen($source) ? 1 : 0),
            ]];
        }
        return $sections;
    }

    private static function number(string $text): int
    {
        $text = mb_convert_kana($text, 'n', 'UTF-8');
        if (ctype_digit($text)) return (int)$text;
        $digits = ['零'=>0,'〇'=>0,'一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];
        $total = 0; $digit = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (isset($digits[$char])) $digit = $digits[$char];
            elseif ($char === '十' || $char === '百') { $total += ($digit ?: 1) * ($char === '十' ? 10 : 100); $digit = 0; }
        }
        return $total + $digit;
    }

    public static function episode(array $episode): array
    {
        $original = ShortDramaImportedScript::sourceContent($episode);
        if ($original === '') return $episode; // Ordinary outline-only creation remains valid.
        $scope = (array)($episode['source_scope'] ?? []);
        if (($scope['version'] ?? 0) === 2) {
            $range = (array)($episode['source_range'] ?? []);
            $start = $range['start_line'] ?? null; $end = $range['end_line'] ?? null;
            if (!in_array($scope['kind'] ?? '', ['document_line_range', 'outline_alignment'], true)
                || ($scope['episode_number'] ?? 0) !== (int)($episode['episode_number'] ?? $episode['episode_no'] ?? 0)
                || !is_int($start) || !is_int($end) || $start < 1 || $end < $start
                || $end > ($scope['document_line_count'] ?? 0)
                || $start !== ($scope['start_line'] ?? null) || $end !== ($scope['end_line'] ?? null)
                || count(ShortDramaSourceAlignment::lines($original)) !== $end - $start + 1
                || !preg_match('/^[a-f0-9]{64}$/', (string)($scope['document_sha256'] ?? ''))
                || !hash_equals((string)($scope['content_sha256'] ?? ''), hash('sha256', $original))) {
                throw new RuntimeException('本集原文与已确认的分集范围不一致，请重新解析并确认大纲', 422);
            }
            $episode['source_content'] = $original;
            unset($episode['content']);
            return $episode;
        }
        $source = self::text($original);
        $sections = self::sections($source);
        $number = (int)($episode['episode_number'] ?? $episode['episode_no'] ?? 0);
        if ($sections !== [] && !isset($sections[$number])) {
            throw new RuntimeException('第' . $number . '集原文与分集标题不匹配，请重新解析并确认大纲', 422);
        }
        if (count($sections) > 1 || $source !== $original) {
            if (!isset($sections[$number])) throw new RuntimeException('剧本文档缺少可验证的分集边界，请重新解析并确认大纲', 422);
            $episode = array_replace($episode, $sections[$number]);
            $episode['source_scope'] = ['version'=>1, 'kind'=>'episode_heading', 'episode_number'=>$number,
                'document_sha256'=>hash('sha256', $source)];
        } else {
            $episode['source_content'] = $source;
        }
        unset($episode['content']);
        return $episode;
    }

    public static function episodes(array $episodes, string $parseMode = ''): array
    {
        if ($parseMode === 'split_by_ai') $episodes = ShortDramaSourceAlignment::map($episodes);
        $seen = []; $ranges = [];
        foreach ($episodes as $index => &$episode) {
            $episode = self::episode($episode);
            $text = trim((string)($episode['source_content'] ?? ''));
            if ($text === '') continue;
            $hash = hash('sha256', $text);
            if (isset($seen[$hash])) throw new RuntimeException('多集引用了同一份剧本原文，分集范围无效，请重新解析并确认大纲', 422);
            $seen[$hash] = true;
            $range = $episode['source_range'] ?? [];
            if (isset($range['start_line'], $range['end_line'])) {
                $start = (int)$range['start_line']; $end = (int)$range['end_line'];
                if ($start < 1 || $end < $start) throw new RuntimeException('剧本原文行号范围无效，请重新解析', 422);
                // Corrected envelopes share decoded-document coordinates. Legacy
                // unwrapped imports use parser coordinates; don't mix the two.
                $space = $episode['source_scope']['document_sha256'] ?? 'parser';
                foreach ($ranges[$space] ?? [] as [$a, $b]) {
                    if ($start <= $b && $end >= $a) throw new RuntimeException('剧本分集原文范围重叠，请重新解析并确认大纲', 422);
                }
                $ranges[$space][] = [$start, $end];
            }
        }
        unset($episode);
        return $episodes;
    }

    public static function assertRequest(array $request): void
    {
        $episode = $request['series_context']['current_episode'] ?? [];
        if (!$episode || ShortDramaImportedScript::sourceContent($episode) === '') return;
        $normalized = self::episode($episode);
        if (ShortDramaImportedScript::sourceContent($normalized) !== ShortDramaImportedScript::sourceContent($episode)) {
            throw new RuntimeException('本集原文包含其他剧集，已停止生成；请重新解析并确认分集大纲，原有结果保留', 422);
        }
    }
}
