<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\service\app\aigc_canvas\agent\runtime\AgentResponseProtocol;
use Throwable;

/**
 * Compact short-term thread memory for follow-up instructions. It keeps the
 * model grounded in prior targets and constraints without replaying every
 * message or leaking model/provider configuration into prompts.
 */
final class ConversationMemoryBuilder
{
    public static function build(int $tenantId, int $userId, int $threadId, int $currentMessageId = 0): array
    {
        if ($threadId <= 0) {
            return [];
        }
        try {
            $query = AigcCanvasAgentMessage::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'thread_id' => $threadId,
                'delete_time' => 0,
            ]);
            if ($currentMessageId > 0) {
                $query->where('id', '<>', $currentMessageId);
            }
            $rows = $query->order('id', 'desc')->limit(8)->select()->toArray();
        } catch (Throwable) {
            return [];
        }
        $messages = [];
        $constraints = [];
        $references = [];
        foreach (array_reverse($rows) as $row) {
            $json = is_array($row['content_json'] ?? null) ? $row['content_json'] : [];
            $content = trim((string)($row['content'] ?? ''));
            if ((string)($row['role'] ?? '') === 'assistant' && AgentResponseProtocol::isInternalTrace($content)) {
                $content = '';
            }
            if ($content !== '') {
                $messages[] = [
                    'role' => (string)($row['role'] ?? ''),
                    'content' => mb_substr($content, 0, 600, 'UTF-8'),
                ];
            }
            foreach (['target_scope', 'preserved_constraints', 'preserve', 'requested_changes', 'changes', 'next_action'] as $key) {
                if (array_key_exists($key, $json) && $json[$key] !== [] && $json[$key] !== '') {
                    $constraints[$key] = self::sanitize($json[$key]);
                }
            }
            foreach ((array)($json['workspace_actions'] ?? []) as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $input = is_array($action['input'] ?? null) ? $action['input'] : [];
                if (!empty($input['target_element_id']) || !empty($input['selected_element_id'])) {
                    $constraints['last_canvas_target'] = [
                        'target_element_id' => (string)($input['target_element_id'] ?? $input['selected_element_id'] ?? ''),
                        'action_type' => (string)($action['action_type'] ?? ''),
                    ];
                }
            }
            foreach (['uploaded_references', 'attachments'] as $key) {
                foreach ((array)($json[$key] ?? []) as $reference) {
                    if (!is_array($reference)) {
                        continue;
                    }
                    $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $references[$url] = [
                        'type' => (string)($reference['type'] ?? $reference['asset_type'] ?? 'image'),
                        'url' => $url,
                        'uri' => $url,
                        'name' => (string)($reference['name'] ?? $reference['title'] ?? ''),
                        'role' => (string)($reference['role'] ?? 'reference_image'),
                    ];
                }
            }
        }
        return [
            'recent_messages' => array_slice($messages, -6),
            'constraints' => $constraints,
            // Follow-up messages must keep the user's recently supplied reference assets.
            'uploaded_references' => array_slice(array_values($references), -4),
        ];
    }

    private static function sanitize($value)
    {
        if (!is_array($value)) {
            return is_string($value) ? mb_substr($value, 0, 500, 'UTF-8') : $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string)$key), ['sku', 'sku_id', 'market_sku_id', 'market_input_sku_id', 'market_output_sku_id', 'api_key', 'token', 'secret'], true)) {
                continue;
            }
            $result[$key] = self::sanitize($item);
        }
        return $result;
    }
}
