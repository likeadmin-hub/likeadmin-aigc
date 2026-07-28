<?php

namespace app\common\service\power;

use Exception;

/** Enforces the Canvas image prompt contract immediately before billing. */
final class CanvasImagePromptSubmissionGuard
{
    public static function assertPrepared(string $appCode, array $selection): void
    {
        if ($appCode !== 'aigc_canvas') {
            return;
        }

        $prompt = trim((string)($selection['prompt'] ?? ''));
        $compiled = trim((string)($selection['compiled_prompt'] ?? ''));
        $spec = $selection['prompt_spec_json'] ?? [];
        $hash = (string)($selection['prompt_hash'] ?? '');
        $version = trim((string)($selection['compiler_version'] ?? ''));
        $preflight = is_array($selection['preflight'] ?? null) ? $selection['preflight'] : [];

        if (empty($selection['submission_prepared']) || $prompt === '' || $compiled === '' || !is_array($spec) || $spec === [] || $hash === '' || $version === '') {
            throw new Exception('无限画布图片任务缺少统一 Prompt 编译快照，已阻止提交。');
        }
        if ($prompt !== $compiled || !hash_equals($hash, hash('sha256', $prompt))) {
            throw new Exception('无限画布图片任务 Prompt 快照校验失败，已阻止提交。');
        }
        if (($preflight['status'] ?? '') !== 'passed') {
            throw new Exception('无限画布图片任务未通过提交前检查，已阻止提交。');
        }
    }
}
