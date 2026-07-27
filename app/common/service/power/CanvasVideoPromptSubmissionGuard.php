<?php

namespace app\common\service\power;

use Exception;

/** Enforces the Canvas video prompt contract immediately before billing. */
final class CanvasVideoPromptSubmissionGuard
{
    public static function assertPrepared(string $appCode, array $selection): void
    {
        if ($appCode !== 'aigc_canvas') return;
        $prompt = trim((string)($selection['prompt'] ?? ''));
        $compiled = trim((string)($selection['compiled_prompt'] ?? ''));
        $hash = (string)($selection['prompt_hash'] ?? '');
        $version = trim((string)($selection['compiler_version'] ?? ''));
        if (empty($selection['submission_prepared']) || $prompt === '' || $compiled === '' || empty($selection['prompt_spec_json']) || $hash === '' || $version === ''
            || $prompt !== $compiled || !hash_equals($hash, hash('sha256', $prompt)) || (($selection['preflight']['status'] ?? '') !== 'passed')) {
            throw new Exception('Canvas video task is missing a valid compiled prompt snapshot. Submission was blocked.');
        }
    }
}
