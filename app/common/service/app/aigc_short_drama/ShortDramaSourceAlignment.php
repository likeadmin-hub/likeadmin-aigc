<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Recover source coordinates for an AI outline; never generate or rewrite source text. */
final class ShortDramaSourceAlignment
{
    public static function map(array $episodes): array
    {
        if (count($episodes) < 2) return $episodes;
        $documents = array_map(static fn(array $episode): string => ShortDramaSourceBoundary::text(ShortDramaImportedScript::sourceContent($episode)), $episodes);
        // This repair applies only to the parser's repeated whole-document result.
        // Separate excerpts and explicit episode headings keep their existing validation.
        $source = $documents[0];
        if ($source === '' || count(array_unique($documents)) !== 1 || ShortDramaSourceBoundary::sections($source) !== []) return $episodes;
        $lines = self::lines($source);
        $ranges = array_column($episodes, 'source_range');
        if (count($ranges) !== count($episodes) || !self::isPartition($ranges, count($lines))) {
            $ranges = self::align($episodes, $lines);
            $kind = 'outline_alignment';
        } else {
            $kind = 'document_line_range';
        }
        foreach ($episodes as $index => &$episode) {
            $range = $ranges[$index];
            $body = implode('', array_slice($lines, $range['start_line'] - 1, $range['end_line'] - $range['start_line'] + 1));
            $episode['source_content'] = $body;
            $episode['source_range'] = $range;
            $episode['source_scope'] = ['version'=>2, 'kind'=>$kind,
                'episode_number'=>(int)($episode['episode_number'] ?? $episode['episode_no'] ?? 0),
                'document_sha256'=>hash('sha256', $source), 'document_line_count'=>count($lines),
                'content_sha256'=>hash('sha256', $body), 'start_line'=>$range['start_line'], 'end_line'=>$range['end_line'],
                // Lexical alignment is an inferred editorial boundary, reviewed with the imported outline.
                'review_required'=>$kind === 'outline_alignment'];
            unset($episode['content']);
        }
        unset($episode);
        return $episodes;
    }

    /** Keep separators in their original bytes, including CRLF and the last newline. */
    public static function lines(string $source): array
    {
        return preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function isPartition(array $ranges, int $lineCount): bool
    {
        $next = 1;
        foreach ($ranges as $range) {
            if (!is_array($range) || !is_int($range['start_line'] ?? null) || !is_int($range['end_line'] ?? null)
                || $range['start_line'] !== $next || $range['end_line'] < $next || $range['end_line'] > $lineCount) return false;
            $next = $range['end_line'] + 1;
        }
        return $next === $lineCount + 1;
    }

    /** Ordered, nonempty segments maximizing exact phrase evidence in the AI outline. */
    private static function align(array $episodes, array $lines): array
    {
        $count = count($episodes); $length = count($lines);
        // Bound both memory and work. Never guess equal-sized slices when evidence is absent.
        if ($length < $count || $count * $length > 250000 || array_sum(array_map('strlen', $lines)) > 2000000) self::fail();
        $vocabularies = []; $frequency = [];
        foreach ($episodes as $episode) {
            $fields = [];
            foreach (['title','summary','story_outline','conflict_point','ending_hook'] as $field) {
                if (is_string($episode[$field] ?? null)) $fields[] = $episode[$field];
            }
            foreach (['opening_state','closing_state'] as $field) {
                if (is_string($episode['continuity'][$field] ?? null)) $fields[] = $episode['continuity'][$field];
            }
            if (array_sum(array_map('strlen', $fields)) > 50000) self::fail();
            $tokens = self::tokens(implode("\n", $fields));
            $vocabularies[] = $tokens;
            foreach ($tokens as $token => $_) $frequency[$token] = ($frequency[$token] ?? 0) + 1;
        }
        $scores = array_fill(0, $count, []); $anchors = array_fill(0, $count, []);
        foreach ($lines as $lineIndex => $line) {
            $tokens = self::tokens($line);
            foreach ($vocabularies as $episodeIndex => $vocabulary) {
                $score = 0.0; $longest = 0;
                foreach (array_intersect_key($tokens, $vocabulary) as $token => $_) {
                    // Character names and other phrases shared by every episode cannot locate a boundary.
                    if ($frequency[$token] === $count) continue;
                    $size = mb_strlen($token, 'UTF-8');
                    $score += ($size - 1) ** 2 * log(1 + $count / $frequency[$token]);
                    if ($frequency[$token] === 1) $longest = max($longest, $size);
                }
                $scores[$episodeIndex][$lineIndex] = $score;
                $anchors[$episodeIndex][$lineIndex] = $longest;
            }
        }
        // O(episodes * lines): each line belongs to the current segment or starts the next.
        $previous = []; $trace = [];
        for ($episode = 0; $episode < $count; $episode++) {
            $current = []; $trace[$episode] = [];
            for ($line = $episode; $line < $length - ($count - $episode - 1); $line++) {
                $stay = $current[$line - 1] ?? -INF;
                $advance = $episode === 0 ? ($line === 0 ? 0 : -INF) : ($previous[$line - 1] ?? -INF);
                $start = $advance > $stay;
                $current[$line] = max($advance, $stay) + $scores[$episode][$line];
                $trace[$episode][$line] = $start;
            }
            $previous = $current;
        }
        $ranges = []; $episode = $count - 1; $end = $length;
        for ($line = $length - 1; $line >= 0; $line--) {
            if (!$trace[$episode][$line]) continue;
            $ranges[$episode] = ['start_line'=>$line + 1, 'end_line'=>$end];
            $end = $line; $episode--;
        }
        ksort($ranges);
        if (!self::isPartition($ranges, $length)) self::fail();
        // Each inferred segment needs its own exact, distinctive phrase and a clear
        // best matching line. Generic summaries or reordered/unrelated outlines fail closed.
        foreach ($ranges as $episode => $range) {
            $supported = false;
            for ($line = $range['start_line'] - 1; $line < $range['end_line']; $line++) {
                $others = 0.0;
                foreach ($scores as $other => $values) if ($other !== $episode) $others = max($others, $values[$line]);
                if ($anchors[$episode][$line] >= 4 && $scores[$episode][$line] > $others * 1.25) $supported = true;
            }
            if (!$supported) self::fail();
        }
        return array_values($ranges);
    }

    private static function tokens(string $text): array
    {
        // Do not form phrases across punctuation or paragraph boundaries.
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $matches);
        $tokens = [];
        foreach ($matches[0] as $word) {
            $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            $length = count($characters);
            for ($size = 2; $size <= min(6, $length); $size++) {
                for ($offset = 0; $offset <= $length - $size; $offset++) $tokens[implode('', array_slice($characters, $offset, $size))] = true;
            }
        }
        return $tokens;
    }

    private static function fail(): never
    {
        throw new RuntimeException('自动拆集大纲无法可靠对应原文，请补充分集标题后重新解析；原文已保留', 422);
    }
}
