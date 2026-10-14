<?php

namespace app\common\service\power;

/** Stateless Responses wire contract. Native continuation items never become reply text. */
final class ResponsesMessagesAdapter
{
    public static function input(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'tool') {
                $input[] = ['type' => 'function_call_output', 'call_id' => $message['tool_call_id'], 'output' => $message['content']];
                continue;
            }
            if (!empty($message['_responses_output'])) {
                foreach ($message['_responses_output'] as $item) $input[] = $item;
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as &$part) {
                    if (($part['type'] ?? '') === 'text') $part['type'] = $message['role'] === 'assistant' ? 'output_text' : 'input_text';
                    elseif (($part['type'] ?? '') === 'image_url') $part = ['type' => 'input_image', 'image_url' => $part['image_url']['url'], 'detail' => $part['image_url']['detail'] ?? 'auto'];
                }
                unset($part);
            }
            if ($content !== '' && $content !== []) $input[] = ['role' => $message['role'], 'content' => $content];
            foreach ($message['tool_calls'] ?? [] as $call) {
                $input[] = ['type' => 'function_call', 'call_id' => $call['id'], 'name' => $call['function']['name'], 'arguments' => $call['function']['arguments']];
            }
        }
        return $input;
    }

    public static function params(array $params): array
    {
        foreach (['stream_options', 'presence_penalty', 'frequency_penalty', 'enable_thinking'] as $key) unset($params[$key]);
        if (isset($params['response_format'])) {
            $format = $params['response_format'];
            $params['text']['format'] = ($format['type'] ?? '') === 'json_schema'
                ? ['type' => 'json_schema'] + ($format['json_schema'] ?? []) : $format;
            unset($params['response_format']);
        }
        if (isset($params['tools'])) {
            $params['tools'] = array_map(static function (array $tool): array {
                if (($tool['type'] ?? '') !== 'function' || !isset($tool['function'])) return $tool;
                // Existing schemas contain optional parameters; do not silently make them required.
                $function = $tool['function'];
                if (($function['parameters']['properties'] ?? null) === []) $function['parameters']['properties'] = (object)[];
                return ['type' => 'function'] + $function + ['strict' => false];
            }, $params['tools']);
        }
        if (is_array($params['tool_choice'] ?? null) && isset($params['tool_choice']['function']['name'])) {
            $params['tool_choice'] = ['type' => 'function', 'name' => $params['tool_choice']['function']['name']];
        }
        return $params;
    }

    public static function apply(array $json, array &$state, callable $emit): void
    {
        $state['responses'] = true;
        $type = (string)($json['type'] ?? '');
        $response = (array)($json['response'] ?? []);
        $id = (string)($response['id'] ?? '');
        if ($id !== '') {
            $state['request_id'] = $id;
            if ($id !== $state['emitted_request_id']) {
                $state['emitted_request_id'] = $id;
                $emit('provider_request', ['provider_request_id' => $id]);
            }
        }
        if (!empty($response['usage'])) $state['usage'] = array_replace($state['usage'], $response['usage']);
        $index = (int)($json['output_index'] ?? 0);
        if (in_array($type, ['response.output_item.added', 'response.output_item.done'], true)) {
            $state['responses_items'][$index] = (array)($json['item'] ?? []);
        } elseif ($type === 'response.function_call_arguments.delta') {
            $state['responses_items'][$index]['arguments'] = ($state['responses_items'][$index]['arguments'] ?? '') . (string)($json['delta'] ?? '');
        } elseif ($type === 'response.function_call_arguments.done') {
            $state['responses_items'][$index]['arguments'] = (string)($json['arguments'] ?? '');
        } elseif (in_array($type, ['response.output_text.delta', 'response.refusal.delta'], true)) {
            self::text($state, $index . ':' . (int)($json['content_index'] ?? 0), (string)($json['delta'] ?? ''), true, $emit);
        } elseif (in_array($type, ['response.output_text.done', 'response.refusal.done'], true)) {
            self::text($state, $index . ':' . (int)($json['content_index'] ?? 0), (string)($json['text'] ?? $json['refusal'] ?? ''), false, $emit);
        } elseif (in_array($type, ['response.failed', 'response.incomplete'], true)) {
            $state['error'] = '模型回复未完整结束，请勿重复提交，费用待核对';
        } elseif ($type === 'response.completed') {
            $state['responses_complete'] = ($response['status'] ?? '') === 'completed';
            // Completion is authoritative; never concatenate full arguments to deltas.
            $items = array_key_exists('output', $response) ? (array)$response['output'] : (array)($state['responses_items'] ?? []);
            ksort($items);
            $continuation = [];
            $calls = [];
            foreach ($items as $itemIndex => $item) {
                $allowed = match ($item['type'] ?? '') {
                    'message' => ['type', 'id', 'role', 'status', 'content', 'phase'],
                    'function_call' => ['type', 'id', 'call_id', 'name', 'arguments', 'status'],
                    'reasoning' => ['type', 'id', 'summary', 'encrypted_content', 'status'],
                    default => [],
                };
                if ($allowed === [] || (($item['type'] ?? '') === 'message' && ($item['role'] ?? '') !== 'assistant')) {
                    $state['error'] = '模型返回了不支持的输出类型，未执行本轮工具';
                    return;
                }
                $continuation[] = array_intersect_key($item, array_flip($allowed));
                if (($item['type'] ?? '') === 'function_call') {
                    $calls[] = ['id' => $item['call_id'] ?? '', 'type' => 'function', 'function' => ['name' => $item['name'] ?? '', 'arguments' => $item['arguments'] ?? '']];
                } elseif (($item['type'] ?? '') === 'message') {
                    foreach ($item['content'] ?? [] as $partIndex => $part) {
                        if (in_array($part['type'] ?? '', ['output_text', 'refusal'], true)) self::text($state, $itemIndex . ':' . $partIndex, (string)($part['text'] ?? $part['refusal'] ?? ''), false, $emit);
                    }
                }
            }
            $state['responses_output'] = $continuation;
            $state['tool_calls'] = $calls;
            $state['finish_reason'] = $calls === [] ? 'stop' : 'tool_calls';
        }
        // Other native events, including reasoning summaries, contain no visible answer.
    }

    private static function text(array &$state, string $key, string $text, bool $delta, callable $emit): void
    {
        $previous = (string)($state['responses_text'][$key] ?? '');
        if (!$delta) {
            if (!str_starts_with($text, $previous)) { $state['error'] = '模型流式正文不一致，未执行本轮工具'; return; }
            $text = substr($text, strlen($previous));
        }
        $state['responses_text'][$key] = $previous . $text;
        if ($text === '') return;
        $state['content'] .= $text;
        $state['delta_count'] = (int)($state['delta_count'] ?? 0) + 1;
        $emit('delta', ['delta' => $text]);
    }

    public static function assertComplete(array $state): void
    {
        if (empty($state['responses'])) return;
        if (empty($state['responses_complete'])) throw new \RuntimeException('模型回复连接中断，未执行本轮工具');
        $ids = [];
        foreach ($state['tool_calls'] as $call) {
            if (empty($call['id']) || isset($ids[$call['id']]) || empty($call['function']['name']) || !json_decode($call['function']['arguments']) instanceof \stdClass) throw new \RuntimeException('模型工具参数不完整，未执行本轮工具');
            $ids[$call['id']] = true;
        }
        foreach ($state['responses_output'] ?? [] as $item) {
            if (in_array($item['status'] ?? '', ['incomplete', 'in_progress'], true)) throw new \RuntimeException('模型输出尚未完成，未执行本轮工具');
            if ($ids !== [] && ($item['type'] ?? '') === 'reasoning' && empty($item['encrypted_content'])) throw new \RuntimeException('模型未返回工具续接信息，未执行本轮工具');
        }
    }
}
