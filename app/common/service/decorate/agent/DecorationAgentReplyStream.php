<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

/** Reads only a JSON object's root reply string; never forwards the model envelope. */
final class DecorationAgentReplyStream
{
    public const MAX_REPLY_CHARS = 8000;
    private string $buffer = '';
    private string $text = '';
    public function append(string $delta): string
    {
        if (strlen($this->buffer) >= 262144) return $this->text;
        $this->buffer .= substr($delta, 0, 262144 - strlen($this->buffer));
        $json = ltrim($this->buffer);
        if (str_starts_with($json, '```')) {
            if (!preg_match('/^```(?:json)?\s*\n/i', $json, $match)) return $this->text;
            $json = ltrim(substr($json, strlen($match[0])));
        }
        if (($json[0] ?? '') !== '{') return $this->text;
        $i = 1;
        while ($i < strlen($json)) {
            $this->whitespace($json, $i);
            if (($json[$i] ?? '') !== '"') break;
            $keyEnd = $this->stringEnd($json, $i);
            if ($keyEnd === null) break;
            try { $key = json_decode(substr($json, $i, $keyEnd - $i + 1), true, 64, JSON_THROW_ON_ERROR); } catch (\Throwable $e) { break; }
            $i = $keyEnd + 1; $this->whitespace($json, $i);
            if (($json[$i++] ?? '') !== ':') break;
            $this->whitespace($json, $i);
            if ($key === 'reply') {
                if (($json[$i] ?? '') !== '"') break;
                $end = $this->stringEnd($json, $i);
                $raw = substr($json, $i + 1, ($end ?? strlen($json)) - $i - 1);
                $decoded = $this->decodePrefix($raw, $end !== null);
                $decoded = mb_substr($decoded, 0, self::MAX_REPLY_CHARS);
                // Malformed later bytes cannot replace an already emitted safe prefix.
                if (str_starts_with($decoded, $this->text)) $this->text = $decoded;
                break;
            }
            $start = $i; $stack = []; $string = false; $escaped = false;
            for (; $i < strlen($json); $i++) {
                $c = $json[$i];
                if ($string) { if ($escaped) $escaped=false; elseif ($c==='\\') $escaped=true; elseif ($c==='"') $string=false; continue; }
                if ($c === '"') $string=true;
                elseif ($c === '{' || $c === '[') $stack[]=$c;
                elseif ($c === '}' || $c === ']') { if (!$stack) break; $open=array_pop($stack); if (($open==='{' && $c!=='}') || ($open==='[' && $c!==']')) return $this->text; }
                elseif ($c === ',' && !$stack) break;
            }
            if ($i === strlen($json)) break;
            try { json_decode(substr($json,$start,$i-$start),true,64,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { break; }
            if ($json[$i++] !== ',') break;
        }
        return $this->text;
    }
    private function whitespace(string $text, int &$i): void { while (isset($text[$i]) && str_contains(" \t\r\n",$text[$i])) $i++; }
    private function stringEnd(string $text,int $start): ?int
    {
        $escaped=false;
        for ($i=$start+1;$i<strlen($text);$i++) { if ($escaped) $escaped=false; elseif ($text[$i]==='\\') $escaped=true; elseif ($text[$i]==='"') return $i; }
        return null;
    }
    private function decodePrefix(string $raw, bool $complete): string
    {
        // Only the tail may be incomplete: UTF8 (up to 3 bytes), escape or surrogate (up to 12).
        $attempts=$complete?0:min(12,strlen($raw));
        for ($cut=0;$cut<=$attempts;$cut++) {
            $value=json_decode('"'.substr($raw,0,strlen($raw)-$cut).'"',true);
            if (is_string($value)) return $value;
        }
        return $this->text;
    }
}
