<?php
declare(strict_types=1);
namespace app\common\service;

/** History reads frozen submission text, without running today's admission rules. */
final class WorkspaceCreationPrompt
{
    public static function fromRequest(array $request): string
    {
        if (is_string($request['prompt'] ?? null) && trim($request['prompt']) !== '') return $request['prompt'];
        $snapshot = is_array($request['snapshot'] ?? null) ? $request['snapshot'] : [];
        $script = $snapshot['script'] ?? null;
        if (is_string($script)) return $script;
        if (is_array($script) && is_string($script['summary'] ?? null)) return $script['summary'];
        return is_string($snapshot['prompt'] ?? null) ? $snapshot['prompt'] : '';
    }
}
