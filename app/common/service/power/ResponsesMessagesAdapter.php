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

    public static function apply(array $json, array &$state, callable $emit, string $requestKey = ''): void
    {
        $state['responses'] = true;
        $type = (string)($json['type'] ?? '');
        $state['native_event_type'] = $type;
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
        if(str_starts_with($type,'response.reasoning') && array_key_exists('delta',$json))$state['native_reasoning_delta_count']=(int)($state['native_reasoning_delta_count']??0)+1;
        if($type==='response.function_call_arguments.delta')$state['native_function_delta_count']=(int)($state['native_function_delta_count']??0)+1;
        $index = (int)($json['output_index'] ?? 0);
        if (in_array($type, ['response.output_item.added', 'response.output_item.done'], true)) {
            $state['responses_items'][$index] = (array)($json['item'] ?? []);
            $state['native_output_item_count']=max((int)($state['native_output_item_count']??0),count($state['responses_items']));
        } elseif ($type === 'response.function_call_arguments.delta') {
            $state['responses_items'][$index]['arguments'] = ($state['responses_items'][$index]['arguments'] ?? '') . (string)($json['delta'] ?? '');
        } elseif ($type === 'response.function_call_arguments.done') {
            $state['responses_items'][$index]['arguments'] = (string)($json['arguments'] ?? '');
        } elseif (in_array($type, ['response.output_text.delta', 'response.refusal.delta'], true)) {
            self::textPart($json, $state, $emit);
        } elseif (in_array($type, ['response.output_text.done', 'response.refusal.done'], true)) {
            self::textPart($json, $state, $emit, true);
        } elseif (in_array($type, ['response.failed', 'response.incomplete'], true)) {
            $state['native_response_status']=self::code((string)($response['status']??''));
            $state['native_provider_type']=self::code((string)($response['error']['type']??''),$requestKey);
            $state['native_provider_message']=self::diagnosticMessage((string)($response['error']['message']??''),$requestKey);
            $state['native_output_present']=array_key_exists('output',$response)&&is_array($response['output']);
            $items=$state['native_output_present']?$response['output']:($state['responses_items']??[]);
            $state['native_output_item_count']=max((int)($state['native_output_item_count']??0),count($items),count($state['responses_items']??[]));
            // A terminal failed snapshot may contain final visible fragments without
            // output_text.delta. Retain only assistant text, never reasoning or calls.
            $snapshotText='';foreach($items as $item)if(($item['type']??'')==='message'&&($item['role']??'')==='assistant')foreach($item['content']??[] as $part)if(in_array($part['type']??'',['output_text','refusal'],true))$snapshotText.=(string)($part['text']??$part['refusal']??'');
            if($snapshotText!==''&&str_starts_with($snapshotText,(string)$state['content']))$state['content']=$snapshotText;
            $state['native_error_code'] = $type;
            $state['native_provider_code'] = self::code((string)($response['error']['code'] ?? $response['incomplete_details']['reason'] ?? ''),$requestKey);
            $state['error'] = '模型回复未完整结束，请勿重复提交，费用待核对';
        } elseif ($type === 'response.completed') {
            $state['native_response_status'] = self::code((string)($response['status'] ?? ''));
            $state['native_output_present'] = array_key_exists('output', $response) && is_array($response['output']);
            $state['native_output_item_count'] = count($state['native_output_present'] ? $response['output'] : ($state['responses_items'] ?? []));
            $state['responses_complete'] = ($response['status'] ?? '') === 'completed';
            if (($state['error'] ?? '') !== '') return;
            // Completion is authoritative; never concatenate full arguments to deltas.
            $items = array_key_exists('output', $response) ? (array)$response['output'] : (array)($state['responses_items'] ?? []);
            ksort($items);
            $continuation = [];
            $calls = [];
            $finalText = '';
            $finalParts = [];
            foreach ($items as $itemIndex => $item) {
                $allowed = match ($item['type'] ?? '') {
                    'message' => ['type', 'id', 'role', 'status', 'content', 'phase'],
                    'function_call' => ['type', 'id', 'call_id', 'name', 'arguments', 'status'],
                    'reasoning' => ['type', 'id', 'summary', 'encrypted_content', 'status'],
                    default => [],
                };
                if ($allowed === [] || (($item['type'] ?? '') === 'message' && ($item['role'] ?? '') !== 'assistant')) {
                    $state['native_error_code'] = 'responses_output_type';
                    $state['error'] = '模型返回了不支持的输出类型，未执行本轮工具';
                    return;
                }
                $continuation[] = array_intersect_key($item, array_flip($allowed));
                if (($item['type'] ?? '') === 'function_call') {
                    $calls[] = ['id' => $item['call_id'] ?? '', 'type' => 'function', 'function' => ['name' => $item['name'] ?? '', 'arguments' => $item['arguments'] ?? '']];
                } elseif (($item['type'] ?? '') === 'message') {
                    $parts = (array)($item['content'] ?? []);
                    ksort($parts, SORT_NUMERIC);
                    foreach ($parts as $partIndex => $part) {
                        if (in_array($part['type'] ?? '', ['output_text', 'refusal'], true)) {
                            $text = (string)($part['text'] ?? $part['refusal'] ?? '');
                            $finalParts[$itemIndex . ':' . $partIndex] = $text;
                            $finalText .= $text;
                        }
                    }
                }
            }
            $state['responses_output'] = $continuation;
            // Downgrading to legacy arrival order cannot erase already proven
            // indexed prefixes, explicitly indexed done snapshots, or head facts.
            foreach ($state['responses_indexed_prefix'] ?? [] as $key => $text) {
                if (!array_key_exists($key, $finalParts) || !str_starts_with($finalParts[$key], $text)) {
                    self::textMismatch($state);
                    return;
                }
            }
            foreach ($state['responses_text_done'] ?? [] as $key => $text) {
                if (!array_key_exists($key, $finalParts) || $finalParts[$key] !== $text) {
                    self::textMismatch($state);
                    return;
                }
            }
            foreach ($state['responses_head_prefix'] ?? [] as $itemIndex => $type) {
                if (($items[$itemIndex]['type'] ?? '') !== $type) {
                    self::textMismatch($state);
                    return;
                }
            }
            if (($state['responses_text_mode'] ?? '') !== 'indexed'
                && !str_starts_with($finalText, (string)($state['responses_arrival_content'] ?? $state['content']))) {
                // Missing/mixed indices additionally retain the conservative
                // whole-answer arrival-prefix contract.
                self::textMismatch($state);
                return;
            }
            if (!str_starts_with($finalText, (string)$state['content'])) {
                self::textMismatch($state);
                return;
            }
            self::visibleText($state, substr($finalText, strlen((string)$state['content'])), $emit);
            $state['tool_calls'] = $calls;
            $state['finish_reason'] = $calls === [] ? 'stop' : 'tool_calls';
        }
        // Other native events, including reasoning summaries, contain no visible answer.
    }

    private static function code(string $value, string $requestKey = ''): string
    {
        if ($requestKey !== '') $value = str_replace($requestKey, '[redacted]', $value);
        return substr((string)preg_replace('/[^a-zA-Z0-9_.-]/', '', $value), 0, 80);
    }

    private static function diagnosticMessage(string $value, string $requestKey = ''): string
    {
        // Remove the transport's actual key before truncation or HTML cleanup.
        if ($requestKey !== '') $value = str_replace($requestKey, '[redacted credential]', $value);
        $value = strip_tags($value);
        $value = (string)preg_replace('~https?://[^\s<>]+~iu', '[redacted URL]', $value);
        $value = (string)preg_replace(
            '~(?<![\w-])["\']?(?:x[-_]?api[-_]?key|api[-_]?key|access[-_]?token|refresh[-_]?token|client[-_]?secret|authorization|token|secret)["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|(?:Bearer|Basic)\s+[^\s,;]+|[^\s,;]+)~iu',
            '[redacted credential]', $value
        );
        $value = (string)preg_replace('~\b(?:(?:Bearer|Basic)\s+[^\s,;]+|sk-[A-Za-z0-9_-]+|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)~iu', '[redacted credential]', $value);
        return mb_substr(trim((string)preg_replace('/[\x00-\x20]+/u', ' ', $value)), 0, 1000, 'UTF-8');
    }

    private static function textPart(array $event, array &$state, callable $emit, bool $done = false): void
    {
        $text = (string)($event[$done ? 'text' : 'delta'] ?? $event['refusal'] ?? '');
        // Received text remains output evidence even if its indices/closure are invalid.
        if (!$done && $text !== '') $state['delta_count'] = (int)($state['delta_count'] ?? 0) + 1;
        foreach (['output_index', 'content_index'] as $field) {
            if (array_key_exists($field, $event) && (!is_int($event[$field]) || $event[$field] < 0)) {
                self::textMismatch($state);
                return;
            }
        }
        $indexed = isset($event['output_index'], $event['content_index']);
        // A gateway's unindexed done snapshot cannot identify which part it closes.
        if ($done && !$indexed) return;
        $mode = $state['responses_text_mode'] ?? 'indexed';
        $state['responses_text_mode'] = $indexed ? $mode : 'legacy';
        $output = $event['output_index'] ?? 0;
        $part = $event['content_index'] ?? 0;
        $key = $output . ':' . $part;
        $previous = (string)($state['responses_text'][$key] ?? '');
        if ($done) {
            $known = (string)($state['responses_indexed_prefix'][$key] ?? '');
            if (!str_starts_with($text, $known)
                || (isset($state['responses_text_done'][$key]) && $state['responses_text_done'][$key] !== $text)) {
                self::textMismatch($state);
                return;
            }
            $state['responses_text_done'][$key] = $text;
            return;
        }
        if ($text === '') return;
        if ($indexed && array_key_exists($key, $state['responses_text_done'] ?? [])) {
            self::textMismatch($state);
            return;
        }
        // Once an unindexed gap occurs, later indexed deltas cannot prove a
        // contiguous prefix from that part's beginning. Freeze earlier facts.
        if ($indexed && $mode !== 'legacy') {
            $state['responses_indexed_prefix'][$key] = ($state['responses_indexed_prefix'][$key] ?? '') . $text;
        }
        $state['responses_text'][$key] = $previous . $text;
        $state['responses_arrival_content'] = ($state['responses_arrival_content'] ?? '') . $text;
        if ($state['responses_text_mode'] === 'legacy') {
            $arrival = (string)$state['responses_arrival_content'];
            if (!str_starts_with($arrival, (string)$state['content'])) {
                self::textMismatch($state);
                return;
            }
            self::visibleText($state, substr($arrival, strlen((string)$state['content'])), $emit);
            return;
        }
        if (!isset($state['responses_stream_head']) && $part === 0) {
            $prefix = [];
            for ($i = 0; $i < $output; $i++) {
                $type = $state['responses_items'][$i]['type'] ?? '';
                if (!in_array($type, ['reasoning', 'function_call'], true)) return;
                $prefix[$i] = $type;
            }
            $state['responses_stream_head'] = $key;
            $state['responses_head_prefix'] = $prefix;
        }
        // Later parts may arrive while the head still grows. Buffer them rather
        // than emitting a sequence that would need a caller-wide reset protocol.
        if (($state['responses_stream_head'] ?? null) === $key) {
            $head = $state['responses_text'][$key];
            if (!str_starts_with($head, (string)$state['content'])) {
                self::textMismatch($state);
                return;
            }
            self::visibleText($state, substr($head, strlen((string)$state['content'])), $emit);
        }
    }

    private static function textMismatch(array &$state): void
    {
        $state['native_error_code'] = 'responses_text_mismatch';
        $state['error'] = '模型流式正文不一致，未执行本轮工具';
    }

    private static function visibleText(array &$state, string $text, callable $emit): void
    {
        if ($text === '') return;
        $state['content'] .= $text;
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
