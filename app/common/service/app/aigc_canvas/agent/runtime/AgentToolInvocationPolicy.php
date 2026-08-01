<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;

/**
 * Applies deterministic safeguards to a concrete model tool proposal. The
 * model decides which capability is useful; this class decides whether that
 * exact invocation is safe to submit now.
 */
final class AgentToolInvocationPolicy
{
    private const MEDIA_TOOLS = ['generate_image', 'generate_video', 'generate_music'];

    public static function evaluate(
        string $toolCode,
        array $input,
        array $taskDecision = [],
        array $skillContract = [],
        bool $confirmed = false
    ): array {
        $toolCode = trim($toolCode);
        $input = self::normalize($toolCode, $input);
        $allowedTools = (array)($skillContract['allowed_tools'] ?? []);
        $strict = in_array((string)($taskDecision['binding_mode'] ?? 'none'), ['contract', 'generic_contract'], true)
            && $allowedTools !== [];

        if ($toolCode === '') {
            return self::deny($input, 'missing_tool_code');
        }
        if ($strict && !in_array($toolCode, $allowedTools, true)) {
            return self::deny($input, 'skill_policy');
        }

        $reason = '';
        if (in_array($toolCode, self::MEDIA_TOOLS, true) && (int)($input['quantity'] ?? 1) > 1) {
            $reason = 'batch_generation';
        } elseif (self::isDestructiveCanvasInvocation($toolCode, $input)) {
            $reason = 'destructive_canvas_mutation';
        }

        return [
            'allowed' => true,
            'requires_confirmation' => $reason !== '' && !$confirmed,
            'reason' => $reason,
            'normalized_input' => $input,
        ];
    }

    private static function normalize(string $toolCode, array $input): array
    {
        if (in_array($toolCode, self::MEDIA_TOOLS, true) && array_key_exists('quantity', $input)) {
            $input['quantity'] = max(1, min(50, (int)$input['quantity']));
        }
        if (in_array($toolCode, [CanvasProtocol::TOOL_MUTATION, CanvasProtocol::TOOL_SELECTION_ACTION, 'canvas_patch'], true)) {
            $input['operation'] = strtolower(trim((string)($input['operation'] ?? '')));
        }
        return $input;
    }

    private static function isDestructiveCanvasInvocation(string $toolCode, array $input): bool
    {
        if (!in_array($toolCode, [CanvasProtocol::TOOL_MUTATION, CanvasProtocol::TOOL_SELECTION_ACTION, 'canvas_patch'], true)) {
            return false;
        }
        return in_array((string)($input['operation'] ?? ''), ['delete', 'remove', 'clear', 'replace', 'overwrite'], true);
    }

    private static function deny(array $input, string $reason): array
    {
        return [
            'allowed' => false,
            'requires_confirmation' => false,
            'reason' => $reason,
            'normalized_input' => $input,
        ];
    }
}
