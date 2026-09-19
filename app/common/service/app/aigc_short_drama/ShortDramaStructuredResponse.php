<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Never manufacture closing braces or accept a partial document as completed work. */
final class ShortDramaStructuredResponse
{
    public static function decode(array $response): array
    {
        $reason = (string)($response['finish_reason'] ?? '');
        if (in_array($reason, ['content_filter', 'refusal', 'safety'], true)) {
            throw new RuntimeException('模型未接受此次内容，请调整输入后再试', 403);
        }
        if (in_array($reason, ['length', 'max_tokens', 'max_output_tokens'], true)) {
            throw new RuntimeException('本次输出达到模型长度上限，已保存返回内容', 413);
        }
        $content = trim((string)($response['content'] ?? ''));
        $content = preg_replace('/^\x{FEFF}/u', '', $content) ?? $content;
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        // Only a complete outer object is accepted. Explanations around JSON
        // are tolerated, but a nested object in a truncated response is not.
        $start = strpos($content, '{');
        if ($start !== false) {
            $depth = 0; $quoted = false; $escaped = false;
            for ($i = $start, $length = strlen($content); $i < $length; $i++) {
                $c = $content[$i];
                if ($quoted) {
                    if ($escaped) $escaped = false;
                    elseif ($c === '\\') $escaped = true;
                    elseif ($c === '"') $quoted = false;
                    continue;
                }
                if ($c === '"') $quoted = true;
                elseif ($c === '{') $depth++;
                elseif ($c === '}' && --$depth === 0) {
                    $data = json_decode(substr($content, $start, $i - $start + 1), true);
                    if (!is_array($data)) break;
                    for ($level = 0; $level < 3; $level++) {
                        $unwrapped = false;
                        foreach (['data', 'result', 'plan', 'payload'] as $key) {
                            if (count($data) === 1 && is_array($data[$key] ?? null)) {
                                $data = $data[$key]; $unwrapped = true; break;
                            }
                        }
                        if (!$unwrapped) break;
                    }
                    return $data;
                }
            }
        }
        throw new RuntimeException('模型输出不是完整 JSON，已保存返回内容', 422);
    }
}
