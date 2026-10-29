<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use app\common\service\power\TextModelCapacity;

final class ShortDramaPlanningBudget
{
    /** Existing paid inputs stay unchanged unless the local preflight cannot submit them. */
    public static function fitMessages(array &$messages, array $model, string $stage, int $desired = 8192, bool $boundedMemory = false): array
    {
        if ($boundedMemory) {
            foreach ([10000, 5000, 2000] as $memoryBudget) {
                $projected = ShortDramaNarrativeMemory::messages($messages, $memoryBudget);
                try {
                    $budget = self::stage($projected['system_prompt'] . $projected['content'], $model, $stage, $desired);
                    $messages = $projected;
                    return $budget;
                } catch (RuntimeException $error) { if ($error->getCode() !== 413) throw $error; }
                // The built-in full-script template embeds this same JSON in
                // prose. Only recover inputs which still fail preflight, so
                // already-submittable paid units retain their exact signatures.
                $embedded = self::projectEmbeddedContext($messages, $projected);
                if ($embedded['content'] === $projected['content']) continue;
                try {
                    $budget = self::stage($embedded['system_prompt'] . $embedded['content'], $model, $stage, $desired);
                    $messages = $embedded;
                    return $budget;
                } catch (RuntimeException $error) { if ($error->getCode() !== 413) throw $error; }
            }
            throw $error;
        }
        try { return self::stage($messages['system_prompt'] . $messages['content'], $model, $stage, $desired); }
        catch (RuntimeException $error) {
            if ($error->getCode() !== 413) throw $error;
            $projected = ShortDramaPlanningContext::withoutAuditDiagnostics($messages);
            try { $budget = self::stage($projected['system_prompt'] . $projected['content'], $model, $stage, $desired); }
            catch (RuntimeException $diagnosticError) {
                if ($diagnosticError->getCode() !== 413) throw $diagnosticError;
                // Only previously un-submittable legacy units adopt working memory;
                // valid paid inputs retain their original receipt signatures.
                $budget = self::fitMessages($projected, $model, $stage, $desired, true);
            }
            $messages = $projected;
            return $budget;
        }
    }

    private static function projectEmbeddedContext(array $original, array $projected): array
    {
        // _stage_content is supplied only by the server's built-in assembler;
        // custom templates deliberately carry null. Never scan arbitrary user
        // prose for JSON or replace a matching fragment in several locations.
        $context = $original['_stage_content'] ?? null;
        $replacement = $projected['_stage_content'] ?? null;
        if (!is_string($context) || !str_starts_with($context, '{')
            || !is_string($replacement) || $context === $replacement
            || substr_count($projected['content'], $context) !== 1) return $projected;
        $projected['content'] = str_replace($context, $replacement, $projected['content']);
        return $projected;
    }

    public static function calculate(string $input, array $model, int $remaining, bool $publicFields = false): array
    {
        $capacity = self::capacity($input, $model);
        $common = $publicFields ? 2048 : 0;
        $count = min(10, $remaining, (int)floor(($capacity['output_capacity'] - $common) / 1200));
        if ($count < 1) throw new RuntimeException('模型容量不足以容纳当前规则、灵感及一集大纲，请缩短输入或选择更大容量模型', 413);
        return ['count' => $count, 'max_tokens' => $common + $count * 1200] + $capacity;
    }

    /** Stage budgets do not pretend that story setting is a three-episode batch. */
    public static function stage(string $input, array $model, string $stage, int $desired = 8192): array
    {
        $capacity = self::capacity($input, $model);
        $minimum = $stage === 'story' ? 2048 : 1200;
        if ($capacity['output_capacity'] < $minimum) {
            throw new RuntimeException('当前模型容量不足以完成此步骤，请减少输入或选择更大容量模型；已完成内容保留', 413);
        }
        return ['count' => 1, 'stage' => $stage,
            'max_tokens' => min($capacity['output_capacity'], max($minimum, $desired))] + $capacity;
    }

    public static function capacity(string $input, array $model): array
    {
        // Byte length makes Chinese prompts look roughly three times larger
        // than they are. Use the same mixed-language heuristic used by the
        // text runtime, while retaining a UTF-8 guard for CJK-heavy input.
        // Provider usage remains the billing source of truth after submission.
        $bytes = strlen($input);
        $characters = mb_strlen($input, 'UTF-8');
        $tokens = max(1, (int)ceil(max($characters / 1.5, $bytes / 3)));
        $limits = TextModelCapacity::metadata($model);
        $output = $limits['max_output_tokens'];
        if ($output <= 0) $output = max(1200, (int)($model['_planning_fallback']['planning_output_fallback'] ?? 8192));
        $output = TextModelCapacity::output($model, $output);
        // An unknown provider context is not an application input ceiling.
        // Keep the per-call output allowance, and enforce only published limits.
        $context = $limits['context_window'];
        $source = $context > 0 ? 'model_metadata' : 'unknown';
        $margin = $context > 0 ? max(1024, (int)ceil($context * .15)) : 0;
        if ($limits['max_input_tokens'] > 0 && $tokens > $limits['max_input_tokens']) {
            throw new RuntimeException('输入内容超过当前模型的输入容量，请减少输入或选择更大容量模型', 413);
        }
        $available = $context > 0 ? min($output, $context - $margin - $tokens) : $output;
        return ['output_capacity' => max(0, $available), 'input_estimate' => $tokens,
            'input_bytes' => $bytes, 'context' => $context, 'margin' => $margin, 'source' => $source, 'estimator' => 'mixed_utf8_heuristic'];
    }

    /**
     * A JSON repair only returns the current stage's small contract. Do not
     * ask a provider to reserve the whole remaining context window merely
     * because its first response had formatting defects.
     */
    public static function repairMaxTokens(array $budget, int $count, bool $publicFields = false): int
    {
        if (!empty($budget['stage'])) return $budget['max_tokens'];
        $available = max(1, (int)($budget['output_capacity'] ?? 0));
        $requested = $publicFields
            ? max(4096, 2048 + max(1, $count) * 1200)
            : max(1600, max(1, $count) * 1500);
        return min($available, $requested);
    }

}
