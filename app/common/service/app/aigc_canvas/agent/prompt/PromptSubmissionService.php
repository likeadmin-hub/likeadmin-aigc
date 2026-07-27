<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService;

/** Single submission gateway for every canvas image task. */
final class PromptSubmissionService
{
    /** Mark a database-backed retry snapshot as trusted within this PHP request. */
    public static function withTrustedRetrySnapshot(array $input): array
    {
        $input['__prompt_retry_ticket'] = new PromptSubmissionRetryTicket();
        return $input;
    }

    public static function prepareImageRequest(int $tenantId, array $input): array
    {
        try {
            $mode = self::mode($input);
            $requestUserId = (int)($input['__request_user_id'] ?? 0);
            $trustedRetry = $mode === 'retry'
                && ($input['__prompt_retry_ticket'] ?? null) instanceof PromptSubmissionRetryTicket;
            unset($input['__prompt_retry_ticket'], $input['__request_user_id']);
            if ($trustedRetry && self::hasSnapshot($input)) {
                $trace = PromptSpecCompiler::reuseSnapshot($input);
            } else {
                $input = self::normalizeLegacyStructuredInput($input);
                if ($mode === 'direct') {
                    $input = PromptEnrichmentService::enrichDirect($tenantId, $requestUserId, $input);
                }
                if ($mode === 'planned') {
                    $delivery = self::delivery($input);
                    $trace = PromptSpecCompiler::compilePlanned(
                        self::creativeContext($input),
                        $delivery,
                        (string)($delivery['type'] ?? $input['delivery_type'] ?? 'image'),
                        $input
                    );
                } elseif ($mode === 'edit') {
                    $trace = PromptSpecCompiler::compileEdit(
                        self::userRequest($input),
                        self::array($input['original_identity'] ?? $input['product_identity'] ?? []),
                        $input
                    );
                } else {
                    $trace = PromptSpecCompiler::compileDirect(self::userRequest($input), $input);
                }
            }
            $prepared = array_merge($input, [
                'prompt' => $trace['compiled_prompt'],
                'compiled_prompt' => $trace['compiled_prompt'],
                'creative_spec_json' => $trace['creative_spec_json'],
                'prompt_spec_json' => $trace['prompt_spec_json'],
                'compiler_version' => $trace['compiler_version'],
                'evidence_ids' => $trace['evidence_ids'],
                'claim_ids' => $trace['claim_ids'],
                'prompt_hash' => $trace['prompt_hash'],
                'prompt_mode' => $mode,
            ]);
            $prepared = CanvasModelRouterService::applyToToolInput($tenantId, 'generate_image', $prepared);
            $prepared['preflight'] = ProviderSubmissionValidator::validateImage($tenantId, $prepared);
            $prepared['submission_prepared'] = true;
            return $prepared;
        } catch (PromptSubmissionException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new PromptSubmissionException($e->getMessage(), [
                'provider_error_code' => 'prompt_policy_rejected',
                'submitted_prompt_hash' => (string)($input['prompt_hash'] ?? ''),
            ]);
        }
    }

    private static function mode(array $input): string
    {
        $mode = strtolower(trim((string)($input['prompt_mode'] ?? $input['compile_mode'] ?? '')));
        if (in_array($mode, ['direct', 'planned', 'edit', 'retry'], true)) return $mode;
        if (!empty($input['retry_of_task_id'])) return 'retry';
        if (!empty($input['edit_mode']) || !empty($input['redrawMode']) || !empty($input['original_identity'])) return 'edit';
        // Context alone must not convert a free-form request into a delivery plan.
        // Doing so duplicated the request as both purpose and user_request.
        return !empty($input['delivery']) || !empty($input['section']) ? 'planned' : 'direct';
    }

    private static function hasCurrentSnapshot(array $input): bool
    {
        return (string)($input['compiler_version'] ?? '') === PromptSpecCompiler::VERSION
            && !empty($input['prompt_spec_json']) && !empty($input['prompt_hash']) && trim((string)($input['prompt'] ?? '')) !== '';
    }

    private static function hasSnapshot(array $input): bool { return self::hasCurrentSnapshot($input); }

    private static function delivery(array $input): array
    {
        $delivery = self::array($input['delivery'] ?? $input['section'] ?? []);
        if ($delivery === []) {
            $delivery = [
                'type' => (string)($input['delivery_type'] ?? 'image'),
                'purpose' => (string)($input['purpose'] ?? ''),
                'narrative' => (string)($input['narrative'] ?? ''),
                'ratio' => (string)($input['ratio'] ?? $input['size'] ?? ''),
                'evidence_ids' => (array)($input['evidence_ids'] ?? []),
                'copy_content' => self::array($input['copy_content'] ?? []),
                'visual_direction' => self::array($input['visual_direction'] ?? []),
            ];
        }
        $delivery['type'] = (string)($delivery['type'] ?? $input['delivery_type'] ?? 'image');
        $delivery['ratio'] = (string)($delivery['ratio'] ?? $input['ratio'] ?? $input['size'] ?? '');
        $delivery['user_request'] = self::userRequest($input);
        return $delivery;
    }

    private static function creativeContext(array $input): array
    {
        return self::array($input['creative_context'] ?? $input['creative_brief'] ?? $input['enriched_context'] ?? []);
    }

    private static function userRequest(array $input): string
    {
        return trim((string)($input['user_request'] ?? $input['creative_intent'] ?? $input['raw_prompt'] ?? $input['prompt'] ?? $input['content'] ?? ''));
    }

    /**
     * Older agent turns persisted presentation labels as a provider prompt.
     * Only retain the actual intent; old evidence is deliberately discarded
     * because it may belong to a previous upload or selection.
     */
    private static function normalizeLegacyStructuredInput(array $input): array
    {
        $raw = self::userRequest($input);
        if (!preg_match('/(?:section\s+purpose|narrative|visible\s+evidence\s+to\s+preserve|use\s+ratio)\s*:/i', $raw)) {
            return $input;
        }

        $purpose = self::legacyField($raw, 'Section purpose');
        $narrative = self::legacyField($raw, 'Narrative');
        $intent = $purpose !== '' ? $purpose : $narrative;
        if ($intent !== '') {
            $input['user_request'] = $intent;
            $input['creative_intent'] = $intent;
            $input['raw_prompt'] = $intent;
            $input['prompt'] = $intent;
        }
        if (trim((string)($input['ratio'] ?? $input['size'] ?? '')) === '') {
            $ratio = self::legacyRatio($raw);
            if ($ratio !== '') {
                $input['ratio'] = $ratio;
            }
        }
        return $input;
    }

    private static function legacyField(string $prompt, string $field): string
    {
        $next = 'Section\\s+purpose|Narrative|Visible\\s+evidence\\s+to\\s+preserve|Use\\s+ratio|Do\\s+not\\s+invent';
        $pattern = '/(?:^|\\R)\\s*' . preg_quote($field, '/') . '\\s*:\\s*(.+?)(?=\\R\\s*(?:' . $next . ')\\s*(?::|$)|\\z)/isu';
        return preg_match($pattern, $prompt, $matches) === 1 ? trim((string)$matches[1]) : '';
    }

    private static function legacyRatio(string $prompt): string
    {
        return preg_match('/(?:^|\\R)\\s*Use\\s+ratio\\s*:?\\s*([0-9]+:[0-9]+)/iu', $prompt, $matches) === 1
            ? trim((string)$matches[1])
            : '';
    }

    private static function array($value): array { return is_array($value) ? $value : []; }
}

/** Internal marker that cannot be supplied through an HTTP request payload. */
final class PromptSubmissionRetryTicket
{
}
