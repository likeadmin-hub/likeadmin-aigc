<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

/** Extracts only the assistant_reply string from portable Agent JSON streams. */
final class AssistantReplyStreamDecoder
{
    private const UNDECIDED = 'undecided';
    private const PLAIN_TEXT = 'plain_text';
    private const JSON = 'json';
    private const BLOCKED = 'blocked';

    private string $mode = self::UNDECIDED;
    private string $prefix = '';
    private string $phase = 'start';
    private string $key = '';
    private bool $keyEscape = false;
    private string $skipMode = '';
    private int $skipDepth = 0;
    private bool $skipEscape = false;
    private bool $replyEscape = false;
    private bool $replyUnicodeActive = false;
    private string $replyUnicode = '';
    private bool $replyComplete = false;

    public function push(string $chunk): string
    {
        if ($chunk === '' || $this->mode === self::BLOCKED || $this->replyComplete) return '';

        if ($this->mode === self::UNDECIDED) {
            $this->prefix .= $chunk;
            $trimmed = ltrim($this->prefix);
            if ($trimmed === '') return '';
            if (str_starts_with($trimmed, '{')) {
                $this->mode = self::JSON;
                $chunk = $this->prefix;
                $this->prefix = '';
            } elseif ($this->startsUnsafePayload($trimmed)) {
                $this->mode = self::BLOCKED;
                $this->prefix = '';
                return '';
            } elseif (strlen($this->prefix) < 16) {
                return '';
            } else {
                $this->mode = self::PLAIN_TEXT;
                $chunk = $this->prefix;
                $this->prefix = '';
            }
        }
        if ($this->mode === self::PLAIN_TEXT) return AgentResponseProtocol::isInternalTrace($chunk) ? '' : $chunk;
        return $this->decodeJson($chunk);
    }

    private function decodeJson(string $chunk): string
    {
        $visible = '';
        for ($index = 0, $length = strlen($chunk); $index < $length; $index++) {
            $char = $chunk[$index];
            if ($this->phase === 'finished') break;
            if ($this->phase === 'reply') {
                $visible .= $this->decodeReplyCharacter($char);
                continue;
            }
            $this->consumeJsonCharacter($char);
            if ($this->mode === self::BLOCKED) return '';
        }
        return $visible;
    }

    private function consumeJsonCharacter(string $char): void
    {
        if ($this->phase === 'start') {
            if (ctype_space($char)) return;
            if ($char !== '{') { $this->block(); return; }
            $this->phase = 'key_or_end';
            return;
        }
        if ($this->phase === 'key_or_end') {
            if (ctype_space($char)) return;
            if ($char === '}') { $this->phase = 'finished'; return; }
            if ($char !== '"') { $this->block(); return; }
            $this->key = '';
            $this->keyEscape = false;
            $this->phase = 'key';
            return;
        }
        if ($this->phase === 'key') {
            if ($this->keyEscape) { $this->key .= $char; $this->keyEscape = false; return; }
            if ($char === '\\') { $this->keyEscape = true; return; }
            if ($char === '"') { $this->phase = 'after_key'; return; }
            $this->key .= $char;
            return;
        }
        if ($this->phase === 'after_key') {
            if (ctype_space($char)) return;
            if ($char !== ':') { $this->block(); return; }
            $this->phase = 'value_start';
            return;
        }
        if ($this->phase === 'value_start') {
            if (ctype_space($char)) return;
            if ($this->key === 'assistant_reply') {
                if ($char !== '"') { $this->block(); return; }
                $this->phase = 'reply';
                return;
            }
            $this->beginSkip($char);
            return;
        }
        if ($this->phase === 'skip') { $this->consumeSkippedValue($char); return; }
        if ($this->phase === 'after_value') {
            if (ctype_space($char)) return;
            if ($char === ',') { $this->phase = 'key_or_end'; return; }
            if ($char === '}') { $this->phase = 'finished'; return; }
            $this->block();
        }
    }

    private function decodeReplyCharacter(string $char): string
    {
        if ($this->replyUnicodeActive) {
            $this->replyUnicode .= $char;
            if (strlen($this->replyUnicode) < 4) return '';
            $hex = $this->replyUnicode;
            $this->replyUnicode = '';
            $this->replyUnicodeActive = false;
            $this->replyEscape = false;
            $decoded = json_decode('"\\u' . $hex . '"', true);
            if (!is_string($decoded)) { $this->block(); return ''; }
            return $decoded;
        }
        if ($this->replyEscape) {
            if ($char === 'u') { $this->replyUnicode = ''; $this->replyUnicodeActive = true; return ''; }
            $this->replyEscape = false;
            return match ($char) {
                '"', '\\', '/' => $char,
                'b' => "\b", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t",
                default => $this->invalidReplyEscape(),
            };
        }
        if ($char === '\\') { $this->replyEscape = true; return ''; }
        if ($char === '"') { $this->replyComplete = true; $this->phase = 'finished'; return ''; }
        if (ord($char) < 0x20) { $this->block(); return ''; }
        return $char;
    }

    private function beginSkip(string $char): void
    {
        $this->phase = 'skip';
        if ($char === '"') { $this->skipMode = 'string'; $this->skipEscape = false; return; }
        if ($char === '{' || $char === '[') { $this->skipMode = 'compound'; $this->skipDepth = 1; $this->skipEscape = false; return; }
        if (preg_match('/[-0-9tfn]/', $char) === 1) { $this->skipMode = 'primitive'; return; }
        $this->block();
    }

    private function consumeSkippedValue(string $char): void
    {
        if ($this->skipMode === 'primitive') {
            if ($char === ',' || $char === '}') { $this->phase = 'after_value'; $this->consumeJsonCharacter($char); }
            return;
        }
        if ($this->skipEscape) { $this->skipEscape = false; return; }
        if ($char === '\\') { $this->skipEscape = true; return; }
        if ($this->skipMode === 'string') { if ($char === '"') $this->phase = 'after_value'; return; }
        if ($char === '"') { $this->skipMode = $this->skipMode === 'compound_string' ? 'compound' : 'compound_string'; return; }
        if ($this->skipMode === 'compound_string') return;
        if ($char === '{' || $char === '[') { $this->skipDepth++; return; }
        if ($char === '}' || $char === ']') {
            $this->skipDepth--;
            if ($this->skipDepth < 0) { $this->block(); return; }
            if ($this->skipDepth === 0) $this->phase = 'after_value';
        }
    }

    private function startsUnsafePayload(string $text): bool
    {
        return preg_match('/^(?:\[|```(?:json)?\b|<\/?(?:tool|function|analysis|reasoning)\b)/iu', $text) === 1
            || AgentResponseProtocol::isInternalTrace($text)
            || preg_match(
                '/(?:^|[\s,{"\'])'
                . '(?:selected_skill_contract|selected_skill_key|task_decision|project_memory|retrieved_skills|'
                . 'binding_mode|allowed_tools|available_tools|tool_calls|workspace_actions|runtime_allowed_tools|'
                . 'pending_skill_context|delivery_specs|function_calls|output_contract|response_format|system_prompt|'
                . 'tool_choice|enable_thinking|agent_trace|execution_mode)\s*["\']?\s*[:=]/i',
                $text
            ) === 1;
    }

    private function invalidReplyEscape(): string { $this->block(); return ''; }
    private function block(): void { $this->mode = self::BLOCKED; $this->phase = 'finished'; }
}
