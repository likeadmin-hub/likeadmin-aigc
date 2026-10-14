<?php

namespace app\common\service\power;

/** Wire translation only: business tools and billing retain the canonical contract. */
final class AnthropicMessagesAdapter
{
    public static function messages(array $messages): array
    {
        $result = [];
        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? 'user');
            $blocks = [];
            if ($role === 'tool') {
                $role = 'user';
                $blocks[] = ['type' => 'tool_result', 'tool_use_id' => $message['tool_call_id'], 'content' => (string)$message['content']];
            } else {
                $content = $message['content'] ?? '';
                if (is_string($content) && $content !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $content];
                } elseif (is_array($content)) {
                    foreach ($content as $part) {
                        if (($part['type'] ?? '') !== 'image_url') {
                            $blocks[] = $part;
                            continue;
                        }
                        $url = (string)($part['image_url']['url'] ?? '');
                        if (preg_match('~^data:(image/(?:jpeg|png|gif|webp));base64,(.+)$~s', $url, $match)) {
                            $source = ['type' => 'base64', 'media_type' => $match[1], 'data' => $match[2]];
                        } else {
                            $source = ['type' => 'url', 'url' => $url];
                        }
                        $blocks[] = ['type' => 'image', 'source' => $source];
                    }
                }
                foreach ($message['tool_calls'] ?? [] as $call) {
                    $input = json_decode((string)($call['function']['arguments'] ?? '{}'), false, 512, JSON_THROW_ON_ERROR);
                    if (!$input instanceof \stdClass) throw new \InvalidArgumentException('工具参数必须为 JSON 对象');
                    $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['function']['name'], 'input' => $input];
                }
            }
            if ($blocks === []) continue;
            // Parallel results belong to one user turn, before any following text.
            $last = count($result) - 1;
            if ($last >= 0 && $result[$last]['role'] === $role) {
                $result[$last]['content'] = array_merge($result[$last]['content'], $blocks);
            } else {
                $result[] = ['role' => $role, 'content' => $blocks];
            }
        }
        return $result;
    }

    /** Native block types must never be recursively flattened into visible prose. */
    public static function event(array $json, string $type): ?array
    {
        if (!in_array($type, ['message', 'message_start', 'message_delta', 'message_stop', 'content_block_start', 'content_block_delta', 'content_block_stop', 'ping'], true)) return null;
        if ($type === 'message' && !is_array($json['content'] ?? null)) return null;
        $event = [
            'type' => 'usage', 'anthropic' => true,
            'usage' => $json['usage'] ?? $json['message']['usage'] ?? [],
            'provider_request_id' => $json['message']['id'] ?? ($type === 'message' ? ($json['id'] ?? '') : ''),
        ];
        if ($type === 'message_delta') $event['finish_reason'] = $json['delta']['stop_reason'] ?? '';
        if ($type === 'message_stop') $event['anthropic_complete'] = true;
        if ($type === 'content_block_stop') $event['tool_block_stop'] = (int)($json['index'] ?? 0);
        if ($type === 'content_block_start') {
            $block = $json['content_block'] ?? [];
            if (($block['type'] ?? '') === 'tool_use') {
                $event['tool_calls'] = [self::call($block, (int)($json['index'] ?? 0), false)];
            } elseif (($block['type'] ?? '') === 'text') {
                $event['content'] = (string)($block['text'] ?? '');
            }
        }
        if ($type === 'content_block_delta') {
            $delta = $json['delta'] ?? [];
            if (($delta['type'] ?? '') === 'text_delta') $event['content'] = (string)($delta['text'] ?? '');
            if (($delta['type'] ?? '') === 'input_json_delta') {
                $event['tool_calls'] = [['index' => (int)($json['index'] ?? 0), 'function' => ['arguments' => (string)($delta['partial_json'] ?? '')]]];
            }
            // thinking_delta, signature_delta and redacted thinking are not answers.
        }
        if ($type === 'message') {
            $event['anthropic_complete'] = true;
            $event['finish_reason'] = (string)($json['stop_reason'] ?? '');
            foreach ($json['content'] ?? [] as $index => $block) {
                if (($block['type'] ?? '') === 'text') $event['content'] = ($event['content'] ?? '') . (string)($block['text'] ?? '');
                if (($block['type'] ?? '') === 'tool_use') $event['tool_calls'][] = self::call($block, $index, true);
            }
        }
        if (($event['content'] ?? '') !== '') $event['type'] = 'delta';
        return $event;
    }

    private static function call(array $block, int $index, bool $complete): array
    {
        return ['index' => $index, 'id' => (string)($block['id'] ?? ''), 'type' => 'function', 'function' => [
            'name' => (string)($block['name'] ?? ''),
            // Streaming starts with input: {}; arguments arrive in subsequent deltas.
            'arguments' => $complete ? json_encode((object)($block['input'] ?? []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '',
        ]];
    }

    public static function assertComplete(array $state): void
    {
        if (empty($state['anthropic'])) return;
        if (empty($state['anthropic_complete'])) throw new \RuntimeException('模型回复连接中断，请重试；未执行本轮工具');
        if (empty($state['tool_calls'])) return;
        if (($state['finish_reason'] ?? '') !== 'tool_use') throw new \RuntimeException('模型工具回复未完整结束；未执行本轮工具');
        foreach ($state['tool_calls'] as $call) {
            $input = json_decode((string)($call['function']['arguments'] ?? ''), false);
            if (empty($call['id']) || empty($call['function']['name']) || !$input instanceof \stdClass) {
                throw new \RuntimeException('模型工具参数不完整；未执行本轮工具');
            }
        }
    }
}
