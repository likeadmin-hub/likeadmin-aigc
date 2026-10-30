<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Never manufacture closing braces or accept a partial document as completed work. */
final class ShortDramaStructuredResponse
{
    /** Keep only independently complete top-level properties as repair input, never as a completed plan. */
    public static function partialObjectPrefix(array $response): array
    {
        if (in_array($response['finish_reason'] ?? '', ['content_filter','refusal','safety'], true)) return [];
        try { $content=trim(self::text($response['content'] ?? null)); } catch(RuntimeException $error) { return []; }
        $content=preg_replace('/^\x{FEFF}|^```(?:json)?\s*/u','',$content) ?? $content;
        try { self::uniqueKeys($content); } catch (RuntimeException $error) { return []; }
        for ($depth=0;$depth<3;$depth++) {
            if (!preg_match('/^\{\s*"(?:data|result|plan|payload)"\s*:\s*(?=\{)/',$content,$wrapper)) break;
            $content=substr($content,strlen($wrapper[0]));
        }
        if (!str_starts_with($content,'{')) return [];
        $allowed=array_flip(['title','type_judgement','core_theme','story_outline','script_lines','series_bible','subjects','locations','art_style','scene_beats','scene_screenplays']);
        $result=[]; $seen=[]; $stack=['{']; $quoted=false; $escaped=false; $start=1;
        for ($i=1,$length=strlen($content);$i<$length;$i++) {
            $char=$content[$i];
            if ($quoted) { if ($escaped) $escaped=false; elseif ($char==='\\') $escaped=true; elseif ($char==='"') $quoted=false; continue; }
            if ($char==='"') { $quoted=true; continue; }
            if ($char==='{' || $char==='[') { $stack[]=$char; continue; }
            if ($char==='}' || $char===']') {
                if (array_pop($stack) !== ($char==='}' ? '{' : '[')) return [];
                if (!$stack) break;
                continue;
            }
            if ($char!==',' || count($stack)!==1) continue;
            $part=json_decode('{'.substr($content,$start,$i-$start).'}',true);
            if (json_last_error()!==JSON_ERROR_NONE || !is_array($part) || count($part)!==1) return [];
            $key=array_key_first($part);
            if (!is_string($key) || isset($seen[$key])) return [];
            $seen[$key]=true;
            if (isset($allowed[$key])) $result[$key]=$part[$key];
            $start=$i+1;
        }
        return $result;
    }

    /** Extract only complete objects from an explicitly top-level storyboard array.
     * The caller must validate IDs, ordering, fields and scene ownership. */
    public static function partialStoryboard(array $response, bool $allowPrefix = false, bool $extendedShapes = false, bool $strict = false): ?array
    {
        if (in_array($response['finish_reason'] ?? '', ['content_filter','refusal','safety'], true)) return null;
        try { $content=trim(self::text($response['content'] ?? null)); } catch(RuntimeException $error) { return null; }
        $content = preg_replace('/^\x{FEFF}/u', '', $content) ?? $content;
        $content = preg_replace('/^```(?:json)?\s*/u', '', $content) ?? $content;
        // Prefix recovery may not undo strict validation by silently replacing
        // duplicate properties or choosing between complete business objects.
        try { self::uniqueKeys($content); } catch (RuntimeException $error) { return null; }
        if ($strict) {
            try { self::decode(['content'=>$content], true); }
            catch (RuntimeException $error) {
                if ($error->getMessage() !== '模型输出不是完整 JSON，已保存返回内容') return null;
            }
        }
        if ($extendedShapes) {
            // Only the documented single-key envelope names are recognized;
            // arbitrary nested objects are never interpreted as a storyboard.
            for ($depth=0; $depth<3; $depth++) {
                if (!preg_match('/^\{\s*"(?:data|result|plan|payload)"\s*:\s*(?=\{)/', $content, $wrapper)) break;
                $content=substr($content,strlen($wrapper[0]));
            }
        }
        $keyPattern=$extendedShapes ? '(?:storyboard|shots)' : 'storyboard';
        $offset = null;
        if (preg_match('/^\{\s*"' . $keyPattern . '"\s*:\s*\[/', $content, $match)) $offset = strlen($match[0]);
        elseif ($allowPrefix && str_starts_with($content, '{')) {
            // The preceding fields must form a complete top-level object by
            // themselves. A quoted or nested storyboard can never satisfy this.
            $search = 0;
            for ($attempt = 0; $attempt < 32 && preg_match('/"' . $keyPattern . '"\s*:\s*\[/', $content, $match, PREG_OFFSET_CAPTURE, $search); $attempt++) {
                [$token, $position] = $match[0];
                $search = $position + strlen($token);
                $prefix = substr($content, 0, $position);
                $decoded = json_decode($prefix . '"__recovery_probe__":null}', true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)
                    || array_key_exists('storyboard', $decoded) || ($extendedShapes && array_key_exists('shots', $decoded)) || !array_key_exists('__recovery_probe__', $decoded)) continue;
                $offset = $position + strlen($token);
                break;
            }
        }
        if ($offset === null) return null;
        $length = strlen($content); $shots = []; $expectComma = false;
        while ($offset < $length) {
            while ($offset < $length && str_contains(" \t\r\n", $content[$offset])) $offset++;
            if ($offset >= $length) break;
            if ($content[$offset] === ']') {
                // Two returned shot arrays have ambiguous ownership.
                if ($extendedShapes && preg_match('/"(?:storyboard|shots)"\s*:/', substr($content,$offset+1))) return null;
                break;
            }
            if ($expectComma) {
                if ($content[$offset] !== ',') return null;
                $offset++;
                while ($offset < $length && str_contains(" \t\r\n", $content[$offset])) $offset++;
                if ($offset >= $length) break;
            }
            if ($content[$offset] !== '{') return null;
            $start = $offset; $stack = []; $quoted = false; $escaped = false; $complete = false;
            for (; $offset < $length; $offset++) {
                $char = $content[$offset];
                if ($quoted) {
                    if ($escaped) $escaped = false;
                    elseif ($char === '\\') $escaped = true;
                    elseif ($char === '"') $quoted = false;
                    continue;
                }
                if ($char === '"') { $quoted = true; continue; }
                if ($char === '{' || $char === '[') $stack[] = $char;
                elseif ($char === '}' || $char === ']') {
                    if (array_pop($stack) !== ($char === '}' ? '{' : '[')) return null;
                    if (!$stack) { $offset++; $complete = true; break; }
                }
            }
            if (!$complete) break;
            $shot = json_decode(substr($content, $start, $offset - $start), true);
            if (!is_array($shot) || json_last_error() !== JSON_ERROR_NONE) return null;
            $shots[] = $shot; $expectComma = true;
        }
        return $shots ? ['storyboard'=>$shots] : null;
    }

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

    public static function decode(array $response, bool $strict = true): array
    {
        $reason = $response['finish_reason'] ?? '';
        if (!is_string($reason)) throw new RuntimeException('模型输出字段 finish_reason 类型无效', 422);
        if (in_array($reason, ['content_filter', 'refusal', 'safety'], true)) {
            throw new RuntimeException('模型未接受此次内容，请调整输入后再试', 403);
        }
        if (in_array($reason, ['length', 'max_tokens', 'max_output_tokens'], true)) {
            throw new RuntimeException('本次输出达到模型长度上限，已保存返回内容', 413);
        }
        $content = trim(self::text($response['content'] ?? null));
        $content = preg_replace('/^\x{FEFF}/u', '', $content) ?? $content;
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        // Only a complete outer object is accepted. Explanations around JSON
        // are tolerated, but a nested object in a truncated response is not.
        $start = strpos($content, '{');
        if ($start !== false) {
            // A root array/string is a different contract, not prose before an object.
            if (preg_match('/[\["]/u', substr($content, 0, $start))) throw new RuntimeException('模型输出不是唯一 JSON 对象，已保存返回内容', 422);
            $stack = []; $quoted = false; $escaped = false;
            for ($i = $start, $length = strlen($content); $i < $length; $i++) {
                $c = $content[$i];
                if ($quoted) {
                    if ($escaped) $escaped = false;
                    elseif ($c === '\\') $escaped = true;
                    elseif ($c === '"') $quoted = false;
                    continue;
                }
                if ($c === '"') $quoted = true;
                elseif ($c === '{' || $c === '[') $stack[] = $c;
                elseif ($c === '}' || $c === ']') {
                    if (array_pop($stack) !== ($c === '}' ? '{' : '[')) break;
                    if ($stack) continue;
                    // Two objects (including an incomplete second one) have ambiguous ownership.
                    $tail = trim(substr($content, $i + 1));
                    if (preg_match('/[\{\[]/u', $tail)) {
                        $diagnostic = $strict ? null : json_decode(preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/u','',$tail),true);
                        // Only this documented historical second object is compatible.
                        if(!is_array($diagnostic) || array_keys($diagnostic)!==['diagnostic']) {
                            throw new RuntimeException('模型输出含多个 JSON 候选，已保存返回内容', 422);
                        }
                    }
                    $json = substr($content, $start, $i - $start + 1);
                    $data = json_decode($json, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) break;
                    self::uniqueKeys($json);
                    for ($level = 0; $level < 16; $level++) {
                        $unwrapped = false;
                        foreach (['data', 'result', 'plan', 'payload', 'storyboard_plan_v2'] as $key) {
                            if (count($data) === 1 && is_array($data[$key] ?? null)) {
                                if ($data[$key] !== [] && array_is_list($data[$key])) throw new RuntimeException('模型输出封装内不是 JSON 对象', 422);
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

    /** Only declared final-text blocks are concatenated, never reasoning or tool arguments. */
    private static function text(mixed $content): string
    {
        if (is_string($content)) return $content;
        if (is_array($content) && array_is_list($content) && $content !== []) {
            $parts = [];
            foreach ($content as $block) {
                if (!is_array($block) || !in_array($block['type'] ?? '', ['text','output_text'], true) || !is_string($block['text'] ?? null)) {
                    throw new RuntimeException('模型输出字段 content 必须为最终文本', 422);
                }
                $parts[] = $block['text'];
            }
            return implode('', $parts);
        }
        throw new RuntimeException('模型输出字段 content 必须为文本', 422);
    }

    /** json_decode silently overwrites duplicate keys, including escaped spellings. */
    private static function uniqueKeys(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $json, $tokens);
        $stack = [];
        foreach ($tokens[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object'=>$token === '{','key'=>true,'seen'=>[]];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($stack) {
                $index = count($stack) - 1;
                if (!$stack[$index]['object']) continue;
                if ($token === ':') $stack[$index]['key'] = false;
                elseif ($token === ',') $stack[$index]['key'] = true;
                elseif ($token[0] === '"' && $stack[$index]['key']) {
                    $key = json_decode($token, true);
                    if (isset($stack[$index]['seen'][$key])) throw new RuntimeException('模型输出 JSON 存在重复字段，已保存返回内容', 422);
                    $stack[$index]['seen'][$key] = true;
                }
            }
        }
    }
}
