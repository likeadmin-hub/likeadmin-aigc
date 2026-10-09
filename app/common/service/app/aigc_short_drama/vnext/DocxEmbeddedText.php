<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Bounded internal altChunk projection. Never loads URLs or executes embedded content. */
final class DocxEmbeddedText
{
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    public static function extract(\ZipArchive $zip, \DOMElement $node, string $sourcePart, callable $read): array
    {
        $id = $node->getAttributeNS(self::REL, 'id');
        if ($id === '') throw new RuntimeException('DOCX 嵌入正文缺少关系标识', 422);
        $relationships = self::xml($read(dirname($sourcePart).'/_rels/'.basename($sourcePart).'.rels'));
        if ($relationships->documentElement->namespaceURI !== self::PACKAGE_REL || $relationships->documentElement->localName !== 'Relationships') throw new RuntimeException('DOCX 嵌入正文关系格式无效', 422);
        $matches = []; $ids = [];
        foreach ($relationships->documentElement->childNodes as $relation) {
            if (!$relation instanceof \DOMElement) continue;
            if ($relation->namespaceURI !== self::PACKAGE_REL || $relation->localName !== 'Relationship' || isset($ids[$relation->getAttribute('Id')])) throw new RuntimeException('DOCX 嵌入关系不唯一或无效', 422);
            $ids[$relation->getAttribute('Id')] = true;
            if ($relation->getAttribute('Id') === $id) $matches[] = $relation;
        }
        if (count($matches) !== 1) throw new RuntimeException('DOCX 嵌入正文关系缺失', 422);
        $relation = $matches[0];
        if (!in_array($relation->getAttribute('Type'), [self::REL.'/aFChunk', self::REL.'/afChunk'], true)
            || !in_array($relation->getAttribute('TargetMode'), ['', 'Internal'], true)) throw new RuntimeException('DOCX 嵌入正文须为内部文档，不能读取外部地址', 422);
        $target = $relation->getAttribute('Target');
        if ($target === '' || preg_match('/[\\\\:\x00-\x20%?#]/u', $target)) throw new RuntimeException('DOCX 嵌入正文路径无法确认', 422);
        $path = [];
        foreach (explode('/',str_starts_with($target,'/') ? substr($target,1) : dirname($sourcePart).'/'.$target) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') { if (!$path) throw new RuntimeException('DOCX 嵌入路径越界',422); array_pop($path); }
            else $path[] = $segment;
        }
        $part = implode('/',$path);
        $types = self::xml($read('[Content_Types].xml')); $override=[]; $defaults=[];
        if ($types->documentElement->namespaceURI !== self::TYPES || $types->documentElement->localName !== 'Types') throw new RuntimeException('DOCX 嵌入正文类型无效',422);
        foreach ($types->documentElement->childNodes as $type) {
            if (!$type instanceof \DOMElement) continue;
            if ($type->namespaceURI !== self::TYPES || !in_array($type->localName,['Override','Default'],true)) throw new RuntimeException('DOCX 内容类型格式无效',422);
            if ($type->localName === 'Override') {
                $key=$type->getAttribute('PartName');if (isset($override[$key])) throw new RuntimeException('DOCX 内容类型重复',422);$override[$key]=$type->getAttribute('ContentType');
            } else {
                $key=$type->getAttribute('Extension');if (isset($defaults[$key])) throw new RuntimeException('DOCX 内容类型重复',422);$defaults[$key]=$type->getAttribute('ContentType');
            }
        }
        $mime=$override['/'.$part] ?? $defaults[pathinfo($part,PATHINFO_EXTENSION)] ?? '';
        if (!in_array($mime,['text/plain','text/html','application/xhtml+xml'],true)) throw new RuntimeException('DOCX 嵌入格式暂不能完整提取，请将内容合入正文后上传',422);
        $bytes=$read($part);
        if (!mb_check_encoding($bytes,'UTF-8') || str_contains($bytes,"\0")) throw new RuntimeException('DOCX 嵌入文字须为完整 UTF-8，请另存后上传',422);
        $text=str_replace(["\r\n","\r"],"\n",str_starts_with($bytes,"\xEF\xBB\xBF") ? substr($bytes,3) : $bytes);
        if ($mime !== 'text/plain') $text=str_replace(["\r\n","\r"],"\n",self::html($text));
        if ($text === '') throw new RuntimeException('DOCX 嵌入正文为空或无法核对',422);
        return ['relationship_id'=>$id,'source_part'=>$sourcePart,'part'=>$part,'content_type'=>$mime,
            'sha256'=>hash('sha256',$bytes),'text_hash'=>hash('sha256',$text),'text'=>$text];
    }
    private static function xml(string $xml): \DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)) throw new RuntimeException('DOCX 嵌入关系不能包含外部实体',422);
        $old=libxml_use_internal_errors(true);
        try {$dom=new \DOMDocument();$loaded=$dom->loadXML($xml,LIBXML_NONET);}
        finally {libxml_clear_errors();libxml_use_internal_errors($old);}
        if (!$loaded) throw new RuntimeException('DOCX 嵌入关系 XML 不完整',422);
        return $dom;
    }
    private static function html(string $html): string
    {
        if (preg_match('/<!ENTITY|<!DOCTYPE[^>]*(?:SYSTEM|PUBLIC)|<\?(?!xml\b)/i',$html)) throw new RuntimeException('DOCX 嵌入 HTML 格式无法安全读取',422);
        $old=libxml_use_internal_errors(true);
        try {$dom=new \DOMDocument();$loaded=$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);$errors=libxml_get_errors();}
        finally {libxml_clear_errors();libxml_use_internal_errors($old);}
        foreach ($errors as $error) if ($error->level >= LIBXML_ERR_ERROR) throw new RuntimeException('DOCX 嵌入 HTML 不完整，请先合入正文',422);
        if (!$loaded || $dom->getElementsByTagName('body')->length !== 1) throw new RuntimeException('DOCX 嵌入 HTML 无法定位正文',422);
        // CSS, active content, images and arbitrary layouts can hide or replace words.
        foreach ($dom->getElementsByTagName('*') as $element) {
            $tag=strtolower($element->tagName);
            if (!in_array($tag,['html','head','title','meta','body','p','div','span','b','strong','i','em','u','s','a','br','pre','code','blockquote','h1','h2','h3','h4','h5','h6','ul','ol','li','table','tbody','thead','tfoot','tr','td','th','hr','sup','sub'],true)) throw new RuntimeException('DOCX 嵌入 HTML 含图片、活动内容或复杂版面，须核对后合入正文',422);
            foreach ($element->attributes as $attribute) if (!in_array(strtolower($attribute->name),['lang','dir','id','class','title','href','colspan','rowspan','charset','http-equiv','content','name'],true)) throw new RuntimeException('DOCX 嵌入 HTML 样式可能影响正文，须先核对文字版本',422);
            if ($tag==='meta' && $element->hasAttribute('http-equiv') && strtolower($element->getAttribute('http-equiv'))!=='content-type') throw new RuntimeException('DOCX 嵌入 HTML 含活动跳转，无法核对',422);
            if ($tag==='meta' && preg_match('/charset\s*=\s*([^;\s]+)/i',$element->getAttribute('content'),$encoding) && strtoupper(trim($encoding[1],"\"'"))!=='UTF-8') throw new RuntimeException('DOCX 嵌入 HTML 字符编码不一致',422);
            if ($tag==='meta' && $element->hasAttribute('charset') && strtoupper($element->getAttribute('charset'))!=='UTF-8') throw new RuntimeException('DOCX 嵌入 HTML 字符编码不一致',422);
        }
        $walk=static function(\DOMNode $node,int $depth=0) use (&$walk): string {
            if ($depth>128) throw new RuntimeException('DOCX 嵌入 HTML 层级超过容量',422);
            if ($node instanceof \DOMText) return $node->textContent;
            if (!$node instanceof \DOMElement) return '';
            $tag=strtolower($node->tagName);if ($tag==='br'||$tag==='hr') return "\n";
            $text='';foreach($node->childNodes as $child)$text.=$walk($child,$depth+1);
            if (in_array($tag,['td','th'],true)) return $text."\t";
            if (in_array($tag,['p','div','pre','blockquote','h1','h2','h3','h4','h5','h6','li','tr'],true)) return $text."\n";
            return $text;
        };
        return $walk($dom->getElementsByTagName('body')->item(0));
    }
}
