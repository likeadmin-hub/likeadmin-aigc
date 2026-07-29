<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use Exception;

/**
 * Item-scoped continuation contract. It deliberately knows nothing about a
 * particular Skill, model, ratio or business vertical.
 */
final class PendingActionProtocol
{
    public const TYPES = [
        'fill_slot', 'choose_option', 'confirm_execution', 'revise_item',
        'approve_plan', 'retry_item', 'resolve_failure',
    ];

    public static function normalize(array $action): array
    {
        $type = trim((string)($action['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) return [];
        return [
            'action_id' => mb_substr(trim((string)($action['action_id'] ?? '')), 0, 120, 'UTF-8'),
            'type' => $type,
            'required_input_schema' => is_array($action['required_input_schema'] ?? null) ? $action['required_input_schema'] : [],
            'options' => array_values(array_filter((array)($action['options'] ?? []), 'is_array')),
            'on_success_transition' => self::status((string)($action['on_success_transition'] ?? 'ready')),
            'on_reject_transition' => self::status((string)($action['on_reject_transition'] ?? 'clarifying')),
            'expires_at' => max(0, (int)($action['expires_at'] ?? 0)),
        ];
    }

    public static function forMissingSlots(array $slots): array
    {
        $slots = array_values(array_filter(array_map('strval', $slots)));
        if ($slots === []) return [];
        return self::normalize([
            'action_id' => 'fill_slot:' . implode(',', $slots),
            'type' => 'fill_slot',
            'required_input_schema' => [
                'type' => 'object',
                'required' => $slots,
                'properties' => array_fill_keys($slots, ['type' => 'string']),
            ],
            'on_success_transition' => 'ready',
            'on_reject_transition' => 'clarifying',
        ]);
    }

    public static function confirmation(string $type = 'confirm_execution'): array
    {
        return self::normalize([
            'action_id' => $type . ':default',
            'type' => $type,
            'required_input_schema' => ['type' => 'boolean'],
            'options' => [
                ['value' => true, 'label' => 'confirm'],
                ['value' => false, 'label' => 'reject'],
            ],
            // Only the execution service may put an item in the queue.
            'on_success_transition' => 'ready',
            'on_reject_transition' => 'ready',
        ]);
    }

    public static function isPending(array $action): bool
    {
        $action = self::normalize($action);
        return $action !== [] && ((int)$action['expires_at'] <= 0 || (int)$action['expires_at'] >= time());
    }

    /**
     * Applies a structured continuation and returns an item patch. Natural
     * language fallback is intentionally only used for a single pending item.
     */
    public static function resolve(array $item, array $params, string $content = ''): array
    {
        $pending = self::normalize((array)($item['pending_action'] ?? []));
        if (!self::isPending($pending)) return [];
        $requestedAction = trim((string)($params['action'] ?? $params['pending_action_type'] ?? ''));
        $requestedId = trim((string)($params['action_id'] ?? $params['pending_action_id'] ?? ''));
        if ($requestedAction !== '' && $requestedAction !== $pending['type']) return [];
        if ($requestedId !== '' && $requestedId !== $pending['action_id']) return [];
        if ($requestedAction === '' && $requestedId === '' && trim($content) === '') return [];

        $value = $params['structured_value'] ?? $params['value'] ?? null;
        if ($value === null && trim($content) !== '') $value = trim($content);
        $isReject = self::isReject($value);
        $status = $isReject ? $pending['on_reject_transition'] : $pending['on_success_transition'];
        $patch = ['pending_action_json' => []];

        if ($pending['type'] === 'fill_slot' && !$isReject) {
            $slots = (array)($item['slots'] ?? []);
            $required = array_values((array)($pending['required_input_schema']['required'] ?? []));
            $values = is_array($value) ? $value : ($required === [] ? [] : [$required[0] => $value]);
            foreach ($required as $key) {
                $key = (string)$key;
                if (!array_key_exists($key, $values) || self::isEmpty($values[$key])) {
                    throw new Exception('Pending action requires input for ' . $key);
                }
                $slots[$key] = $values[$key];
            }
            $patch['slots_json'] = $slots;
        }

        if ($pending['type'] === 'choose_option' && !$isReject) {
            self::validateOption($pending, $value);
            $patch['meta_json'] = array_merge((array)($item['meta'] ?? []), ['selected_option' => $value]);
        }

        if ($pending['type'] === 'revise_item' && !$isReject) {
            if (!is_array($value)) throw new Exception('Revision action requires structured_value object');
            $patch = array_merge($patch, self::revisionPatch($item, $value));
        }

        if ($pending['type'] === 'resolve_failure' && !$isReject && is_array($value)) {
            $resolution = trim((string)($value['resolution'] ?? ''));
            if ($resolution === 'retry') $status = 'ready';
            if ($resolution === 'cancel') $status = 'canceled';
        }

        return [
            'status' => $status,
            'patch' => $patch,
            'action' => $pending,
            'accepted' => !$isReject,
        ];
    }

    /** A policy-controlled revision is available even when no pending card exists. */
    public static function revise(array $item, array $params): array
    {
        $itemId = (int)($item['id'] ?? 0);
        $actionId = trim((string)($params['action_id'] ?? ''));
        if ((string)($params['action'] ?? '') !== 'revise_item' || $actionId !== 'revise_item:' . $itemId) return [];
        if (!in_array((string)($item['status'] ?? ''), ['ready', 'awaiting_confirmation', 'failed', 'completed'], true)) {
            throw new Exception('This delivery item cannot be revised in its current state');
        }
        $value = $params['structured_value'] ?? null;
        if (!is_array($value)) throw new Exception('Revision action requires structured_value object');
        return [
            'status' => 'ready',
            'patch' => array_merge(['pending_action_json' => self::confirmation()], self::revisionPatch($item, $value)),
            'action' => self::normalize(['action_id' => $actionId, 'type' => 'revise_item']),
            'accepted' => true,
        ];
    }

    private static function revisionPatch(array $item, array $value): array
    {
        $patch = [];
        if (isset($value['slots']) && is_array($value['slots'])) {
            $patch['slots_json'] = array_merge((array)($item['slots'] ?? []), $value['slots']);
        }
        if (isset($value['delivery']) && is_array($value['delivery'])) {
            $delivery = array_intersect_key($value['delivery'], array_flip(['ratio', 'quantity', 'purpose', 'type']));
            $patch['delivery_json'] = array_merge((array)($item['delivery'] ?? []), $delivery);
        }
        return $patch;
    }

    private static function validateOption(array $pending, mixed $value): void
    {
        $allowed = array_map(static fn(array $option): string => (string)($option['value'] ?? ''), (array)$pending['options']);
        $candidate = is_scalar($value) ? (string)$value : '';
        if ($allowed !== [] && !in_array($candidate, $allowed, true)) throw new Exception('Selected option is not allowed');
    }

    private static function isReject(mixed $value): bool
    {
        if ($value === false || $value === 0 || $value === '0') return true;
        $text = mb_strtolower(trim(is_scalar($value) ? (string)$value : (string)($value['decision'] ?? $value['resolution'] ?? '')), 'UTF-8');
        return in_array($text, ['reject', 'cancel', 'no', 'false', '取消', '拒绝', '不用'], true);
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private static function status(string $status): string
    {
        return in_array($status, DeliveryItemService::STATUSES, true) ? $status : 'ready';
    }
}
