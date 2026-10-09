<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** A documented plain-text view, including referenced notes. Never silently drops image/OCR material. */
final class DocxTextView
{
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const MAX_BYTES = 10485760;

    public static function extract(string $path): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('当前服务暂不支持 DOCX，请改用 TXT 或 MD', 422);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('DOCX 文件损坏或加密，请检查后上传', 422);
        $parts = []; $notes = []; $reading = []; $chunks = [];
        try {
            $document = self::part($zip, 'word/document.xml', $parts);
            if ($document->documentElement->namespaceURI !== self::WORD || $document->documentElement->localName !== 'document'
                || $document->getElementsByTagNameNS(self::WORD, 'body')->length !== 1) throw new RuntimeException('DOCX 正文结构无法确认，请另存为 TXT 后上传', 422);
            $walk = static function (\DOMNode $node, string $part = 'word/document.xml') use (&$walk, $zip, &$parts, &$notes, &$reading, &$chunks): string {
                $word = $node->namespaceURI === self::WORD;
                if ($word && in_array($node->localName, ['del', 'moveFrom'], true)) return '';
                if ($word && $node->localName === 'altChunk') {
                    if (!$node instanceof \DOMElement || count($chunks) >= 128) throw new RuntimeException('DOCX 嵌入正文过多，请拆分文件', 422);
                    $chunk = DocxEmbeddedText::extract($zip, $node, $part, static function (string $name) use ($zip, &$parts): string { return self::bytes($zip, $name, $parts); });
                    $index = count($chunks); $chunks[] = $chunk;
                    return "\0altChunk:".$index."\0".$chunk['text']."\0/altChunk:".$index."\0";
                }
                if (($node->namespaceURI === 'http://schemas.openxmlformats.org/drawingml/2006/main' && $node->localName === 'blip')
                    || ($node->namespaceURI === 'urn:schemas-microsoft-com:vml' && $node->localName === 'imagedata')) {
                    throw new RuntimeException('DOCX 正文含图片，尚未核对图片文字，不能把部分文字当作完整原文；请提供已核对的文字版本', 422);
                }
                if ($word && in_array($node->localName, ['footnoteReference', 'endnoteReference'], true)) {
                    $kind = $node->localName === 'footnoteReference' ? 'footnote' : 'endnote';
                    $id = $node instanceof \DOMElement ? $node->getAttributeNS(self::WORD, 'id') : '';
                    if (!preg_match('/^\d+$/D', $id)) throw new RuntimeException('DOCX 注释标识无效', 422);
                    $key = $kind . ':' . $id;
                    if (isset($reading[$key])) throw new RuntimeException('DOCX 注释存在循环引用，无法确认完整原文', 422);
                    if (!isset($notes[$kind])) {
                        $dom = self::part($zip, 'word/' . $kind . 's.xml', $parts); $notes[$kind] = [];
                        if ($dom->documentElement->namespaceURI !== self::WORD || $dom->documentElement->localName !== $kind . 's') throw new RuntimeException('DOCX 注释结构无效', 422);
                        foreach ($dom->documentElement->childNodes as $note) {
                            if (!$note instanceof \DOMElement || $note->namespaceURI !== self::WORD || $note->localName !== $kind) continue;
                            $noteId = $note->getAttributeNS(self::WORD, 'id');
                            if (isset($notes[$kind][$noteId])) throw new RuntimeException('DOCX 注释标识重复', 422);
                            $notes[$kind][$noteId] = $note;
                        }
                    }
                    if (!isset($notes[$kind][$id])) throw new RuntimeException('DOCX 引用的注释正文缺失，不能完整提取', 422);
                    $reading[$key] = true; $text = $walk($notes[$kind][$id], 'word/' . $kind . 's.xml'); unset($reading[$key]);
                    $label = $kind === 'footnote' ? '脚注' : '尾注';
                    return '〔' . $label . $id . '〕' . $text . '〔/' . $label . $id . '〕';
                }
                if ($word && $node->localName === 't') return $node->textContent;
                if ($word && in_array($node->localName, ['tab', 'br', 'cr'], true)) return $node->localName === 'tab' ? "\t" : "\n";
                if ($node->namespaceURI === 'http://schemas.openxmlformats.org/markup-compatibility/2006' && $node->localName === 'AlternateContent') {
                    $alternatives = [];
                    foreach ($node->childNodes as $child) if ($child instanceof \DOMElement && in_array($child->localName, ['Choice', 'Fallback'], true)) {
                        $countBefore = count($chunks); $alternatives[] = $walk($child, $part);
                        if (count($chunks) !== $countBefore) throw new RuntimeException('DOCX 备选正文含嵌入文档，请先合入唯一正文', 422);
                    }
                    if (!$alternatives || count(array_unique($alternatives)) > 1) throw new RuntimeException('DOCX 存在不同版本的备选正文，不能确认原文；请另存为 TXT 后上传', 422);
                    return $alternatives[0];
                }
                $text = ''; foreach ($node->childNodes as $child) {
                    $text .= $walk($child, $part);
                    if (strlen($text) > self::MAX_BYTES) throw new RuntimeException('DOCX 提取正文超过10MB，请拆分文件', 422);
                }
                if ($word && $node->localName === 'p') $text .= "\n";
                if (strlen($text) > self::MAX_BYTES) throw new RuntimeException('DOCX 提取正文超过10MB，请拆分文件', 422);
                return $text;
            };
            $text = $walk($document->documentElement);
            $extraction = ['version' => $notes ? 3 : 2, 'scope' => $notes ? 'word_main_document_with_referenced_notes' : 'word_main_document_text'];
            if ($notes) $extraction += ['note_projection' => 'inline_labeled_reference', 'parts' => $parts];
            if ($chunks) {
                $raw = str_replace(["\r\n","\r"],"\n",$text);
                $leadingBom = str_starts_with($raw,"\xEF\xBB\xBF"); if ($leadingBom) $raw = substr($raw,3);
                $text = ''; $cursor = 0; $characters = 0; $mapping = [];
                foreach ($chunks as $index => $chunk) {
                    $open = "\0altChunk:".$index."\0"; $close = "\0/altChunk:".$index."\0";
                    $position = strpos($raw, $open, $cursor);
                    if ($position === false) throw new RuntimeException('DOCX 嵌入正文位置无法核对', 422);
                    $prefix = substr($raw, $cursor, $position-$cursor); $text .= $prefix;
                    $characters += mb_strlen($prefix,'UTF-8'); $start = $characters;
                    $contentPosition = $position+strlen($open); $endPosition = $contentPosition+strlen($chunk['text']);
                    if (substr($raw,$contentPosition,strlen($chunk['text']))!==$chunk['text'] || substr($raw,$endPosition,strlen($close))!==$close) throw new RuntimeException('DOCX 嵌入正文边界无法核对',422);
                    $text .= $chunk['text']; $characters += mb_strlen($chunk['text'],'UTF-8'); $cursor = $endPosition+strlen($close); unset($chunk['text']);
                    $mapping[] = $chunk + ['start'=>$start, 'end'=>$characters];
                }
                $text .= substr($raw,$cursor);
                $extraction = array_replace($extraction, ['version'=>4, 'scope'=>'word_main_document_with_embedded_text',
                    'embedded_projection'=>'document_order_text', 'embedded_parts'=>$mapping, 'parts'=>$parts,
                    'projection_line_endings'=>'LF', 'projection_leading_bom_removed'=>$leadingBom]);
            }
            return ['text' => $text, 'extraction' => $extraction];
        } finally { $zip->close(); }
    }

    private static function part(\ZipArchive $zip, string $name, array &$parts): \DOMDocument
    {
        $xml = self::bytes($zip, $name, $parts);
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('DOCX 正文格式无法安全读取', 422);
        $old = libxml_use_internal_errors(true);
        try { $dom = new \DOMDocument(); $loaded = $dom->loadXML($xml, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        if (!$loaded) throw new RuntimeException('DOCX 正文 XML 不完整', 422);
        return $dom;
    }

    private static function bytes(\ZipArchive $zip, string $name, array &$parts): string
    {
        $stat = $zip->statName($name);
        if (!$stat || $stat['size'] <= 0 || $stat['size'] > self::MAX_BYTES
            || array_sum(array_column($parts, 'bytes')) - ($parts[$name]['bytes'] ?? 0) + $stat['size'] > self::MAX_BYTES) throw new RuntimeException('DOCX 正文或注释缺失或超过提取容量，请检查后上传', 422);
        $xml = $zip->getFromName($name, self::MAX_BYTES + 1);
        if (!is_string($xml) || strlen($xml) !== (int)$stat['size']) throw new RuntimeException('DOCX 正文格式无法安全读取', 422);
        $parts[$name] = ['bytes' => strlen($xml), 'sha256' => hash('sha256', $xml)];
        return $xml;
    }
}
