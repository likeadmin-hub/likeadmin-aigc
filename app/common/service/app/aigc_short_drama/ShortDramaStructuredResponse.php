<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Never manufacture closing braces or accept a partial document as completed work. */
final class ShortDramaStructuredResponse
{
    /** A malformed completed audit is recoverable. Each repair has distinct
     * input/receipt identity; transport, truncation and semantic failures are
     * never replayed here. The caller must validate the complete contract. */
    public static function audit(array $input, callable $call): array
    {
        for ($attempt = 0; ; $attempt++) {
            try { return $call($input); }
            catch (RuntimeException $error) {
                $format = $error->getMessage() === '模型输出不是完整 JSON，已保存返回内容'
                    || str_starts_with($error->getMessage(), '整集校验结构：')
                    || str_starts_with($error->getMessage(), '连续性语义核对')
                    || str_starts_with($error->getMessage(), '连续性审校结构')
                    || str_starts_with($error->getMessage(), '审校事实纠正')
                    || str_starts_with($error->getMessage(), '审校证据')
                    || str_starts_with($error->getMessage(), '审校纠错')
                    || str_starts_with($error->getMessage(), '复核补丁')
                    || $error->getMessage() === '复核修复未返回有效补丁'
                    || in_array($error->getMessage(), ['连续性摘要缺失', '连续性检查结构不完整', '连续性提示格式无效'], true);
                if ($attempt >= 2 || $error->getCode() !== 422 || !$format) throw $error;
                $input['content'] .= "\n审校格式恢复第" . ($attempt + 1) . "次：上次回包未通过格式校验（"
                    . $error->getMessage() . '）。依据上述同一份正文和检查项重新输出完整合法JSON。不要改变正文、检查项标识或索引；supported必须是实际核对结论，不能为通过格式检查统一填true。字符串内英文双引号必须转义，说明可用中文引号。不得输出代码围栏或解释段落。';
            }
        }
    }

    public static function decode(array $response): array
    {
        $reason = (string)($response['finish_reason'] ?? '');
        if (in_array($reason, ['content_filter', 'refusal', 'safety'], true)) {
            throw new RuntimeException('模型未接受此次内容，请调整输入后再试', 403);
        }
        if (in_array($reason, ['length', 'max_tokens', 'max_output_tokens'], true)) {
            throw new ShortDramaOutputLimitException('本次输出达到模型长度上限，已保存返回内容', 413);
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
