<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Map parser excerpts to the frozen upload; published text always comes from that upload. */
final class ShortDramaUploadedSourceAlignment
{
    public static function map(array $episodes, string $source): array
    {
        if ($episodes === [] || $source === '') return $episodes;
        if (strlen($source) > 2000000 || count($episodes) > 500) self::fail();
        $lines = ShortDramaSourceAlignment::lines($source);
        $document = ''; $ends = [];
        foreach ($lines as $line) {
            $document .= self::comparable($line);
            $ends[] = strlen($document);
        }
        if ($document === '') self::fail();
        $bodies = []; $scoped = 0;
        foreach ($episodes as $index => $episode) {
            if ((int)($episode['episode_number'] ?? $episode['episode_no'] ?? 0) !== $index + 1) self::fail();
            $body = ShortDramaImportedScript::sourceContent($episode);
            if (($episode['source_scope']['version'] ?? 0) === 2) {
                // An existing source scope is immutable: never repair a changed persisted receipt.
                ShortDramaSourceBoundary::episode($episode);
                if (($episode['source_scope']['document_sha256'] ?? '') !== hash('sha256', $source)) self::fail();
                $range = $episode['source_range'];
                if ($body !== implode('', array_slice($lines, $range['start_line'] - 1, $range['end_line'] - $range['start_line'] + 1))) self::fail();
                $scoped++;
            }
            $bodies[] = self::comparable(ShortDramaSourceBoundary::text($body));
        }
        if ($scoped) {
            if ($scoped !== count($episodes)) self::fail();
            return $episodes;
        }

        // Heading discovery uses logical lines; emitted ranges still slice the untouched source bytes.
        $sections = array_values(ShortDramaSourceBoundary::sections(str_replace(["\r\n", "\r"], "\n", $source)));
        if ($sections && count($sections) !== count($episodes)) self::fail();
        if (count(array_unique($bodies)) === 1 && $bodies[0] === $document) {
            if ($sections) {
                $starts = array_column(array_column($sections, 'source_range'), 'start_line');
                return self::partition($episodes, $source, $lines, $starts);
            }
            if (count($episodes) === 1) return self::partition($episodes, $source, $lines, [1]);
            // Preserve the existing evidence-based split of repeated whole-document envelopes.
            foreach ($episodes as &$episode) {
                $episode['source_content'] = $source;
                unset($episode['content']);
            }
            unset($episode);
            return ShortDramaSourceAlignment::map($episodes);
        }

        $starts = []; $previousEndLine = 0;
        foreach ($episodes as $index => $episode) {
            $body = $bodies[$index];
            if ($body === '') self::fail();
            $position = strpos($document, $body);
            if ($position === false) self::fail();
            $next = strpos($document, $body, $position + 1);
            if ($next !== false) {
                // Repeated dialogue is ambiguous unless the parser's original range independently matches.
                $range = $episode['source_range'] ?? [];
                $start = $range['start_line'] ?? null; $end = $range['end_line'] ?? null;
                if (!is_int($start) || !is_int($end) || $start < 1 || $end < $start || $end > count($lines)
                    || self::comparable(implode('', array_slice($lines, $start - 1, $end - $start + 1))) !== $body) self::fail();
                $position = $start > 1 ? $ends[$start - 2] : 0;
            }
            $startLine = self::lineAt($ends, $position);
            $endLine = self::lineAt($ends, $position + strlen($body) - 1);
            if ($startLine <= $previousEndLine) self::fail();
            if ($sections) {
                $sectionStart = $index === 0 ? 1 : $sections[$index]['source_range']['start_line'];
                $sectionEnd = isset($sections[$index + 1]) ? $sections[$index + 1]['source_range']['start_line'] - 1 : count($lines);
                if ($startLine < $sectionStart || $endLine > $sectionEnd) self::fail();
                $starts[] = $sectionStart;
            } else {
                $starts[] = $startLine;
            }
            $previousEndLine = $endLine;
        }
        return self::partition($episodes, $source, $lines, $starts);
    }

    /** Ignore presentation-only punctuation/spacing for matching, never for stored content. */
    private static function comparable(string $text): string
    {
        return preg_replace('/[\s\p{Z}\p{P}]+/u', '', mb_convert_kana($text, 'as', 'UTF-8')) ?? '';
    }

    private static function lineAt(array $ends, int $offset): int
    {
        $low = 0; $high = count($ends) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($ends[$middle] > $offset) $high = $middle;
            else $low = $middle + 1;
        }
        return $low + 1;
    }

    private static function partition(array $episodes, string $source, array $lines, array $starts): array
    {
        $starts[0] = 1;
        foreach ($episodes as $index => &$episode) {
            $start = $starts[$index]; $end = isset($starts[$index + 1]) ? $starts[$index + 1] - 1 : count($lines);
            if ($start < 1 || $end < $start) self::fail();
            $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));
            $episode['source_content'] = $body;
            $episode['source_range'] = ['start_line'=>$start, 'end_line'=>$end];
            $episode['source_scope'] = ['version'=>2, 'kind'=>'document_line_range', 'episode_number'=>$index + 1,
                'document_sha256'=>hash('sha256', $source), 'document_line_count'=>count($lines),
                'content_sha256'=>hash('sha256', $body), 'start_line'=>$start, 'end_line'=>$end, 'review_required'=>false];
            unset($episode['content']);
        }
        unset($episode);
        return $episodes;
    }

    private static function fail(): never
    {
        throw new RuntimeException('解析分集无法可靠对应上传原文，请核对分集内容与顺序；原文和解析回包已保留', 422);
    }
}
