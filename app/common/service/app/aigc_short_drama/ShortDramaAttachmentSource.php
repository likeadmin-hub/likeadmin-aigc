<?php
namespace app\common\service\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\SourceTrace;
use RuntimeException;

/** Frozen attachment text is independent of a provider's interpretation of that file. */
final class ShortDramaAttachmentSource
{
    public const VERSION = 1;
    private const MAX_BYTES = 10485760;

    public static function capture(string $path, string $extension, int $tenant, int $user, string $task): array
    {
        if ($tenant <= 0 || $user <= 0 || $task === '' || !in_array($extension, ['txt', 'md', 'docx'], true)) throw new RuntimeException('附件来源身份或类型无效', 422);
        $bytes = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new RuntimeException('附件为空或超过10MB', 422);
        $encoding = 'UTF-8'; $removedBom = false;
        if ($extension === 'docx') $text = self::docx($path);
        else {
            $text = $bytes;
            if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
                $encoding = str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE'; $text = substr($text, 2);
                $removedBom = true;
            } elseif (!mb_check_encoding($text, 'UTF-8')) {
                $encoding = mb_detect_encoding($text, ['GB18030', 'BIG5'], true) ?: '';
                if ($encoding === '') throw new RuntimeException('附件编码无法识别，请另存为 UTF-8 后上传', 422);
            }
            if ($encoding !== 'UTF-8') {
                $decoded = mb_convert_encoding($text, 'UTF-8', $encoding);
                if (mb_convert_encoding($decoded, $encoding, 'UTF-8') !== $text) throw new RuntimeException('附件编码转换不完整，请另存为 UTF-8 后上传', 422);
                $text = $decoded;
            }
        }
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) throw new RuntimeException('附件不是有效文本，请检查文件格式', 422);
        // Preserve blank lines and trailing text. Coordinates always refer to this documented text view.
        if (str_starts_with($text, "\xEF\xBB\xBF")) { $text = substr($text, 3); $removedBom = true; }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (strlen($text) > self::MAX_BYTES) throw new RuntimeException('附件提取正文超过10MB，请拆分文件', 422);
        ContentDocument::text($text, 'attachment.text');
        $package = ['schema_version' => self::VERSION, 'tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task,
            'file_sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes), 'format' => $extension,
            'extraction' => ['version' => $extension === 'docx' ? 2 : 1, 'encoding' => $encoding, 'scope' => $extension === 'docx' ? 'word_main_document_text' : 'text_file',
                'line_endings' => 'LF', 'leading_bom_removed' => $removedBom, 'blank_lines_preserved' => true],
            'source' => SourceTrace::capture('attachment_' . $task, '1', $text, 'faithful')];
        $package['package_hash'] = ContentDocument::hash($package);
        return $package;
    }

    private static function docx(string $path): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('当前服务暂不支持 DOCX，请改用 TXT 或 MD', 422);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('DOCX 文件损坏或加密，请检查后上传', 422);
        try {
            $stat = $zip->statName('word/document.xml');
            if (!$stat || $stat['size'] <= 0 || $stat['size'] > self::MAX_BYTES) throw new RuntimeException('DOCX 正文为空或超过提取容量，请拆分文件', 422);
            $xml = $zip->getFromName('word/document.xml', self::MAX_BYTES + 1);
            if (!is_string($xml) || strlen($xml) !== (int)$stat['size'] || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('DOCX 正文格式无法安全读取', 422);
            $old = libxml_use_internal_errors(true);
            try { $dom = new \DOMDocument(); $loaded = $dom->loadXML($xml, LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
            if (!$loaded) throw new RuntimeException('DOCX 正文 XML 不完整', 422);
            $wordNamespace='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
            if ($dom->documentElement->namespaceURI!==$wordNamespace || $dom->documentElement->localName!=='document'
                || $dom->getElementsByTagNameNS($wordNamespace,'body')->length!==1) throw new RuntimeException('DOCX 正文结构无法确认，请另存为 TXT 后上传',422);
            $walk = static function (\DOMNode $node) use (&$walk): string {
                $word = $node->namespaceURI === 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
                if ($word && in_array($node->localName, ['del', 'moveFrom'], true)) return '';
                if ($word && in_array($node->localName,['footnoteReference','endnoteReference','altChunk'],true)) throw new RuntimeException('DOCX 包含尚不能完整定位的脚注、尾注或嵌入文档，请将这些内容合入正文并另存为 TXT 后上传',422);
                if ($word && $node->localName === 't') return $node->textContent;
                if ($word && in_array($node->localName, ['tab', 'br', 'cr'], true)) return $node->localName === 'tab' ? "\t" : "\n";
                if ($node->namespaceURI==='http://schemas.openxmlformats.org/markup-compatibility/2006' && $node->localName==='AlternateContent') {
                    $alternatives=[];
                    foreach($node->childNodes as $child) if($child instanceof \DOMElement && in_array($child->localName,['Choice','Fallback'],true)) $alternatives[]=$walk($child);
                    if (!$alternatives || count(array_unique($alternatives))>1) throw new RuntimeException('DOCX 存在不同版本的备选正文，不能确认原文；请另存为 TXT 后上传',422);
                    // Word chooses one branch. Identical shape/fallback text must
                    // not become repeated dialogue in the extracted screenplay.
                    return $alternatives[0];
                }
                $text='';foreach ($node->childNodes as $child) $text.=$walk($child);
                if ($word && $node->localName === 'p') $text .= "\n";
                return $text;
            };
            return $walk($dom->documentElement);
        } finally { $zip->close(); }
    }

    public static function assertPackage(array $package): void
    {
        $unsigned = $package; unset($unsigned['package_hash']);
        if (($package['schema_version'] ?? 0) !== self::VERSION || ($package['package_hash'] ?? '') !== ContentDocument::hash($unsigned)
            || ($package['source']['hash'] ?? '') !== hash('sha256', $package['source']['text'] ?? '')) throw new RuntimeException('附件来源版本校验失败', 409);
    }

    /** Headings repair omitted/cross-episode model excerpts; otherwise require exact unambiguous complete ranges. */
    public static function bind(array $package, array $episodes): array
    {
        self::assertPackage($package); $source = $package['source']; $text = $source['text'];
        $sections = ShortDramaSourceBoundary::sections($text); $cursor = 0;
        if ($sections && (count($sections) !== count($episodes) || array_keys($sections) !== range(1, count($episodes)))) {
            throw new RuntimeException('解析分集与上传原文标题不一致，原文件及解析回包已保留', 422);
        }
        foreach ($episodes as $index => &$episode) {
            $number = (int)($episode['episode_number'] ?? $episode['episode_no'] ?? 0);
            if ($number !== $index + 1) throw new RuntimeException('附件分集编号不连续', 422);
            $parsed = ShortDramaImportedScript::sourceContent($episode);
            $original = $sections ? $sections[$number]['source_content'] : str_replace(["\r\n", "\r"], "\n", ShortDramaSourceBoundary::text($parsed));
            if ($original === '') throw new RuntimeException('解析结果缺少可核对的分集原文，请提供明确分集标题后重新解析', 422);
            $start = mb_strpos($text, $original, $cursor, 'UTF-8');
            if ($start === false || (!$sections && mb_strpos($text, $original, $start + 1, 'UTF-8') !== false)) throw new RuntimeException('分集原文无法唯一对应上传文件，请核对分集边界后重新解析', 422);
            if (!$sections && trim(mb_substr($text, $cursor, $start - $cursor, 'UTF-8')) !== '') throw new RuntimeException('解析结果遗漏了上传原文，原文件及回包已保留', 422);
            $end = $start + mb_strlen($original, 'UTF-8');
            $episode['source_content'] = $original; unset($episode['content']);
            $episode['source_range'] = ['start_line' => substr_count(mb_substr($text, 0, $start, 'UTF-8'), "\n") + 1,
                'end_line' => substr_count(mb_substr($text, 0, $end, 'UTF-8'), "\n") + (str_ends_with($original, "\n") ? 0 : 1)];
            $episode['source_scope'] = ['version' => 1, 'kind' => 'attachment_verified', 'episode_number' => $number, 'document_sha256' => $source['hash']];
            $episode['attachment_trace'] = ['schema_version' => self::VERSION, 'package_hash' => $package['package_hash'],
                'source_task_id' => $package['task_id'],
                'file_sha256' => $package['file_sha256'], 'source_id' => $source['source_id'], 'source_version' => $source['version'],
                'source_hash' => $source['hash'], 'start' => $start, 'end' => $end, 'text_hash' => hash('sha256', $original),
                'parser_text_hash' => hash('sha256', $parsed), 'binding' => $sections ? 'episode_heading' : 'exact_text'];
            $cursor = $end;
        }
        unset($episode);
        if (trim(mb_substr($text, $cursor, null, 'UTF-8')) !== '') throw new RuntimeException('解析结果遗漏上传原文结尾，原文件及回包已保留', 422);
        return $episodes;
    }

    public static function assertEpisode(array $episode): void
    {
        if (!isset($episode['attachment_trace'])) return;
        $trace = $episode['attachment_trace']; $text = ShortDramaImportedScript::sourceContent($episode);
        if (($trace['schema_version'] ?? 0) !== self::VERSION || ($trace['text_hash'] ?? '') !== hash('sha256', $text)
            || !is_int($trace['start'] ?? null) || !is_int($trace['end'] ?? null) || $trace['start'] < 0
            || $trace['end'] - $trace['start'] !== mb_strlen($text, 'UTF-8')
            || ($trace['source_hash'] ?? '') !== ($episode['source_scope']['document_sha256'] ?? null)) {
            throw new RuntimeException('本集附件原文或引用已变化，请重新核对原文；已有结果保留', 409);
        }
    }

    public static function present(array $data): bool
    {
        if (isset($data['_attachment_source']) || isset($data['attachment_trace'])) return true;
        foreach ($data as $value) if (is_array($value) && self::present($value)) return true;
        return false;
    }
}
