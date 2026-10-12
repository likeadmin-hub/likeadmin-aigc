<?php
namespace app\common\service\open_platform;

/** Observes a copy of native text responses; never adapts the bytes sent to clients. */
final class OpenTextProtocol
{
    public static function protocol(string $path): string
    {
        return match ($path) {
            '/api/v1/chat/completions' => 'openai_chat',
            '/api/v1/completions' => 'openai_completions',
            '/api/v1/responses' => 'openai_responses',
            '/api/v1/messages' => 'anthropic_messages',
            default => '',
        };
    }

    /** complete is protocol termination, not a guarantee that billing usage exists. */
    public static function observe(array $wire, string $protocol): array
    {
        $state = ['data'=>[], 'complete'=>false, 'failed'=>false, 'started'=>false];
        if (!in_array($protocol, ['openai_chat','openai_completions','openai_responses','anthropic_messages'], true)) return $state;
        $state['failed'] = (int)($wire['status'] ?? 200) >= 400;
        $type = '';
        foreach ((array)($wire['headers'] ?? []) as $name => $value) {
            if (strtolower((string)$name) === 'content-type' && is_string($value)) $type = strtolower(trim(explode(';', $value, 2)[0]));
        }
        $body = (string)($wire['body'] ?? '');
        if ($type === 'text/event-stream') {
            $valid = true;
            foreach (self::events($body, $valid) as $event) {
                if (trim($event['data']) === '[DONE]') {
                    if (in_array($protocol, ['openai_chat','openai_completions'], true) && $state['started']) $state['complete'] = true;
                    continue;
                }
                $data = self::object($event['data']);
                if ($data === null) { $valid = false; continue; }
                self::event($state, $data, $event['event'], $protocol, $valid);
            }
            // A malformed or unterminated frame prevents a reliable terminal assertion.
            if (!$valid) $state['complete'] = false;
        } elseif ($type === '' || $type === 'application/json' || str_ends_with($type, '+json')) {
            $data = self::object($body);
            if ($data !== null) self::json($state, $data, $protocol);
        }
        if (isset($state['data']['usage']) && is_array($state['data']['usage']) && ($state['data']['usage'] === [] || !array_is_list($state['data']['usage']))) {
            $state['data']['usage'] = self::canonical($state['data']['usage'], $protocol);
        } else {
            unset($state['data']['usage']);
        }
        return $state;
    }

    private static function object(string $json): ?array
    {
        $value = json_decode($json);
        return $value instanceof \stdClass ? json_decode($json, true) : null;
    }

    /** Dispatch only blank-line-terminated SSE frames; an EOF fragment is not an event. */
    private static function events(string $body, bool &$valid): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $events = []; $data = []; $event = ''; $pending = false;
        // The final split element is not a terminated line.
        $tail = array_pop($lines);
        foreach ($lines as $line) {
            if ($line === '') {
                if ($data) $events[] = ['event'=>$event, 'data'=>implode("\n", $data)];
                $data = []; $event = ''; $pending = false;
                continue;
            }
            if ($line[0] === ':') continue;
            $colon = strpos($line, ':');
            $field = $colon === false ? $line : substr($line, 0, $colon);
            $value = $colon === false ? '' : substr($line, $colon + 1);
            if (str_starts_with($value, ' ')) $value = substr($value, 1);
            if ($field === 'data') { $data[] = $value; $pending = true; }
            elseif ($field === 'event') { $event = $value; $pending = true; }
        }
        if ($pending || ($tail !== '' && !str_starts_with($tail, ':'))) $valid = false;
        return $events;
    }

    private static function json(array &$state, array $data, string $protocol): void
    {
        if (self::error($data)) $state['failed'] = true;
        if (self::error($data) && !in_array($data['object'] ?? '', ['response','chat.completion','text_completion'], true) && ($data['type'] ?? '') !== 'message') {
            $state['data'] = $data; $state['complete'] = true;
            self::usage($state, $data['usage'] ?? null);
            return;
        }
        if (in_array($protocol, ['openai_chat','openai_completions'], true)) {
            $object = $protocol === 'openai_chat' ? 'chat.completion' : 'text_completion';
            if (($data['object'] ?? '') !== $object || !is_array($data['choices'] ?? null) || !$data['choices']) return;
            $state['data'] = $data; $state['started'] = true; $state['complete'] = true;
            foreach ($data['choices'] as $choice) {
                if (!is_array($choice) || !is_string($choice['finish_reason'] ?? null) || $choice['finish_reason'] === '') $state['complete'] = false;
                if ($protocol === 'openai_chat' ? !is_array($choice['message'] ?? null) : !is_string($choice['text'] ?? null)) $state['complete'] = false;
            }
        } elseif ($protocol === 'openai_responses' && ($data['object'] ?? '') === 'response') {
            $state['data'] = $data;
            $state['started'] = !empty($data['id']) || !empty($data['output']);
            $state['complete'] = in_array($data['status'] ?? '', ['completed','incomplete','failed'], true);
            $state['failed'] = $state['failed'] || ($data['status'] ?? '') === 'failed';
        } elseif ($protocol === 'anthropic_messages' && ($data['type'] ?? '') === 'message' && ($data['role'] ?? '') === 'assistant' && is_array($data['content'] ?? null)) {
            $state['data'] = $data; $state['started'] = true;
            $state['complete'] = is_string($data['stop_reason'] ?? null) && $data['stop_reason'] !== '';
        }
        self::usage($state, $state['data']['usage'] ?? null);
    }

    private static function event(array &$state, array $data, string $event, string $protocol, bool &$valid): void
    {
        $type = (string)($data['type'] ?? $event);
        if ($event !== '' && isset($data['type']) && $event !== $data['type']) { $valid = false; return; }
        if (self::error($data) || $type === 'error') {
            $state['failed'] = true; $state['complete'] = true;
            $state['data']['error'] = $data['error'] ?? $data;
            return;
        }
        if (in_array($protocol, ['openai_chat','openai_completions'], true)) {
            $expected = $protocol === 'openai_chat' ? 'chat.completion.chunk' : 'text_completion';
            if (($data['object'] ?? '') !== $expected || !is_array($data['choices'] ?? null)) return;
            if ($data['choices'] || is_array($data['usage'] ?? null)) $state['started'] = true;
            foreach (['id','object','model'] as $key) if (isset($data[$key])) $state['data'][$key] = $data[$key];
            self::usage($state, $data['usage'] ?? null);
            // Even a finish_reason can precede more choices and the final usage frame.
        } elseif ($protocol === 'openai_responses') {
            if (!str_starts_with($type, 'response.')) return;
            if (is_array($data['response'] ?? null) && ($data['response']['object'] ?? '') === 'response') {
                $response = $data['response'];
                unset($response['usage']);
                $state['data'] = array_replace_recursive($state['data'], $response);
                $state['started'] = true;
                self::usage($state, $data['response']['usage'] ?? null);
            } elseif (in_array($type, ['response.output_text.delta','response.output_item.added','response.content_part.added','response.function_call_arguments.delta','response.reasoning_text.delta','response.reasoning_summary_text.delta'], true)) {
                $state['started'] = true;
            }
            if (in_array($type, ['response.completed','response.incomplete','response.failed'], true)) {
                $status = substr($type, strlen('response.'));
                if (($data['response']['object'] ?? '') === 'response' && ($data['response']['status'] ?? '') === $status) {
                    $state['complete'] = true;
                    $state['failed'] = $state['failed'] || $status === 'failed';
                } else $valid = false;
            }
        } elseif ($protocol === 'anthropic_messages') {
            if ($type === 'message_start' && ($data['message']['type'] ?? '') === 'message') {
                $state['data'] = $data['message']; $state['started'] = true;
                self::usage($state, $data['message']['usage'] ?? null);
            } elseif ($type === 'message_delta' && $state['started']) {
                if (is_array($data['delta'] ?? null)) $state['data'] = array_replace_recursive($state['data'], $data['delta']);
                self::usage($state, $data['usage'] ?? null);
            } elseif (in_array($type, ['content_block_start','content_block_delta'], true)) {
                $state['started'] = true;
            } elseif ($type === 'message_stop' && $state['started']) $state['complete'] = true;
        }
    }

    private static function error(array $data): bool
    {
        return isset($data['error']) && (is_array($data['error']) || is_string($data['error']));
    }

    private static function usage(array &$state, $usage): void
    {
        if (!is_array($usage)) return;
        $state['data']['usage'] = array_replace_recursive((array)($state['data']['usage'] ?? []), $usage);
        foreach (['input_tokens','output_tokens','total_tokens','prompt_tokens','completion_tokens','cache_read_input_tokens','cache_creation_input_tokens'] as $key) {
            if (self::count($usage[$key] ?? null) && $usage[$key] > 0) $state['started'] = true;
        }
    }

    private static function count($value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /** Remove unsafe counts only from the billing copy, never coerce missing/invalid to zero. */
    private static function cleanCounts(array $usage): array
    {
        foreach ($usage as $key => $value) {
            if (str_ends_with((string)$key, '_tokens')) {
                if (!self::count($value)) unset($usage[$key]);
            } elseif (str_ends_with((string)$key, '_tokens_details') || in_array($key, ['cache_details','cache_creation'], true)) {
                if (!is_array($value) || ($value !== [] && array_is_list($value))) unset($usage[$key]);
                else $usage[$key] = self::cleanCounts($value);
            } elseif (is_array($value)) {
                $usage[$key] = self::cleanCounts($value);
            }
        }
        return $usage;
    }

    private static function canonical(array $usage, string $protocol): array
    {
        $provided = $usage;
        $usage = self::cleanCounts($usage);
        $chat = in_array($protocol, ['openai_chat','openai_completions'], true);
        if ($chat) {
            foreach (['input_tokens'=>'prompt_tokens','output_tokens'=>'completion_tokens'] as $target=>$source) {
                if (!array_key_exists($target, $provided) && isset($usage[$source])) $usage[$target] = $usage[$source];
                elseif (isset($usage[$target], $usage[$source]) && $usage[$target] !== $usage[$source]) unset($usage[$target]);
            }
        }
        // Detail counters are subsets for OpenAI. Claude cache counters are separate inputs.
        if ($protocol !== 'anthropic_messages') {
            foreach (['prompt_tokens_details'=>['cached_tokens','prompt_tokens'], 'input_tokens_details'=>['cached_tokens','input_tokens'], 'completion_tokens_details'=>['reasoning_tokens','completion_tokens'], 'output_tokens_details'=>['reasoning_tokens','output_tokens']] as $detail=>[$counter,$parent]) {
                if (isset($usage[$detail][$counter], $usage[$parent]) && $usage[$detail][$counter] > $usage[$parent]) unset($usage[$detail][$counter]);
            }
        }
        $keys = $chat ? [isset($usage['prompt_tokens']) ? 'prompt_tokens' : 'input_tokens', isset($usage['completion_tokens']) ? 'completion_tokens' : 'output_tokens'] : ['input_tokens','output_tokens'];
        if ($protocol === 'anthropic_messages') $keys = array_merge($keys, ['cache_read_input_tokens','cache_creation_input_tokens']);
        $known = []; $allKnown = true;
        foreach ($keys as $key) {
            if (isset($usage[$key])) $known[] = $usage[$key];
            else $allKnown = false;
        }
        $sum = array_sum($known);
        // Sum overflow becomes float in PHP. It cannot be represented by a safe native count.
        if (isset($usage['total_tokens']) && (!is_int($sum) || $usage['total_tokens'] < $sum)) unset($usage['total_tokens']);
        // A contradictory supplied total stays unknown; never silently replace it with a guess.
        if (!array_key_exists('total_tokens', $provided) && $allKnown && is_int($sum)) $usage['total_tokens'] = $sum;
        return $usage;
    }
}
