<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\service\FileService;
use app\common\service\open_platform\{OpenStore,OpenSafeHttp,OpenException};

/** Bounded, read-only extraction. Call only after session/topic attachment authorization. */
final class AgentDocumentReader
{
    public const MAX_BYTES = 10485760;
    public const MAX_CHARS = 200000;
    private const MAX_XML_BYTES = 4194304;
    public const EXTENSIONS = ['txt','md','markdown','csv','docx'];

    public static function supports(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    public static function read(int $tenant, int $user, string $file): string
    {
        $row = OpenStore::owner('file', $tenant, $user)->where('file_id', $file)->find();
        if (!$row) throw new \InvalidArgumentException('文档不存在');
        if (!self::supports((string)$row['name'])) throw new \InvalidArgumentException('当前可读取 TXT、Markdown、CSV 和 DOCX 正文，请转换格式后上传');
        if ((int)$row['size'] > self::MAX_BYTES) throw new \InvalidArgumentException('文档不能超过10MB，请拆分后上传');
        if ((string)$row['storage_engine'] === 'local') {
            $root = realpath(public_path().'uploads');
            $path = realpath(public_path().ltrim((string)$row['uri'], '/'));
            if (!$root || !$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)) throw new \InvalidArgumentException('文档文件不可用，请重新上传');
            return self::extract($path, (string)$row['name']);
        }
        $temp = tempnam(sys_get_temp_dir(), 'agent-doc-');
        if ($temp === false) throw new \InvalidArgumentException('文档暂时无法读取，请稍后重试');
        try {
            $url = FileService::getFileUrlByStorage($row['uri'], $row['storage_scope'], $row['storage_engine'], $row['storage_domain']);
            $response = OpenSafeHttp::get($url, self::MAX_BYTES, 15);
            if (file_put_contents($temp, $response['body']) !== strlen($response['body'])) throw new \InvalidArgumentException('文档暂时无法读取，请稍后重试');
            return self::extract($temp, (string)$row['name']);
        } catch (OpenException $e) {
            throw new \InvalidArgumentException('文档下载失败或超过10MB，请重新上传或稍后重试', 0, $e);
        } finally { @unlink($temp); }
    }

    /** Also validates an uploaded temporary file before storing it. No external calls. */
    public static function extract(string $path, string $name): string
    {
        if (!self::supports($name)) throw new \InvalidArgumentException('当前可读取 TXT、Markdown、CSV 和 DOCX 正文');
        $size = @filesize($path);
        if (!$size || $size > self::MAX_BYTES) throw new \InvalidArgumentException('请选择非空且10MB以内的文档');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'docx') $text = self::docx($path);
        else {
            $text = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
            if (!is_string($text) || strlen($text) > self::MAX_BYTES) throw new \InvalidArgumentException('文档读取失败或超过大小限制');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($text);
            if (!in_array($mime, ['text/plain','text/csv','text/markdown','text/x-markdown','application/csv','application/octet-stream'], true)) throw new \InvalidArgumentException('文件不是可读取的纯文本文档');
            if (str_starts_with($text, "\xFF\xFE")) $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
            elseif (str_starts_with($text, "\xFE\xFF")) $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
            elseif (!mb_check_encoding($text, 'UTF-8')) {
                $encoding = mb_detect_encoding($text, ['GB18030','BIG-5'], true);
                if (!$encoding) throw new \InvalidArgumentException('文档编码无法识别，请另存为 UTF-8 后上传');
                $text = mb_convert_encoding($text, 'UTF-8', $encoding);
            }
        }
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) throw new \InvalidArgumentException('文档包含无法读取的二进制内容');
        $text = trim(str_replace(["\xEF\xBB\xBF", "\r\n", "\r"], ['', "\n", "\n"], $text));
        if ($text === '') throw new \InvalidArgumentException('文档没有可读取的文字正文；图片或扫描内容请另行提供');
        if (mb_strlen($text) > self::MAX_CHARS) throw new \InvalidArgumentException('文档正文超过20万字，请拆分后上传');
        return $text;
    }

    private static function docx(string $path): string
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) throw new \InvalidArgumentException('当前服务暂不支持 DOCX，请另存为 TXT 后上传');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw new \InvalidArgumentException('DOCX 文件损坏或已加密，请重新上传');
        try {
            $stat = $zip->statName('word/document.xml');
            if (!$stat || $stat['size'] > self::MAX_XML_BYTES) throw new \InvalidArgumentException('DOCX 正文不存在或过大，请拆分后上传');
            $xml = $zip->getFromName('word/document.xml', self::MAX_XML_BYTES + 1);
        } finally { $zip->close(); }
        if (!is_string($xml) || strlen($xml) > self::MAX_XML_BYTES || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) throw new \InvalidArgumentException('DOCX 正文格式不受支持');
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->resolveExternals = false; $doc->substituteEntities = false;
            if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null) throw new \InvalidArgumentException('DOCX 正文格式损坏');
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $body = $xpath->query('/w:document/w:body')->item(0);
            if (!$body) throw new \InvalidArgumentException('DOCX 没有可读取的正文');
            return self::wordText($body);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private static function wordText(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement && $node->namespaceURI === 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') {
            if (in_array($node->localName, ['del','instrText','drawing','pict','object'], true)) return '';
            if ($node->localName === 't') return $node->textContent;
            if ($node->localName === 'tab') return "\t";
            if (in_array($node->localName, ['br','cr'], true)) return "\n";
        }
        $text = '';
        foreach ($node->childNodes as $child) $text .= self::wordText($child);
        return match ($node->localName) {
            'p', 'tr' => $text."\n",
            'tc' => trim($text)."\t",
            default => $text,
        };
    }
}
