<?php

namespace app\common\service\storage;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/** Static SVG images only. Rebuild an allow-listed document before any storage driver sees it. */
final class SvgSanitizer
{
    private const NS = 'http://www.w3.org/2000/svg';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'title', 'desc', 'linearGradient', 'radialGradient', 'stop',
        'clipPath', 'mask', 'pattern',
    ];
    private const ATTRIBUTES = [
        'id', 'viewBox', 'preserveAspectRatio', 'version', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'width', 'height', 'rx', 'ry', 'cx', 'cy', 'r', 'd', 'points', 'transform',
        'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust', 'pathLength',
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'fx', 'fy', 'fr', 'offset',
        'clipPathUnits', 'maskUnits', 'maskContentUnits', 'patternUnits', 'patternContentUnits',
        'patternTransform',
    ];
    private const PRESENTATION = [
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'opacity', 'color', 'stop-color', 'stop-opacity',
        'clip-path', 'clip-rule', 'mask', 'display', 'visibility', 'overflow',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
        'dominant-baseline', 'alignment-baseline', 'letter-spacing', 'word-spacing',
        'vector-effect', 'paint-order', 'shape-rendering',
    ];

    public static function sanitizeFile(string $path): void
    {
        $source = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($source === false) {
            throw new InvalidArgumentException('无法读取 SVG 图片');
        }
        $clean = self::sanitize($source);
        if (file_put_contents($path, $clean, LOCK_EX) !== strlen($clean)) {
            throw new InvalidArgumentException('SVG 图片处理失败');
        }
        clearstatcache(true, $path);
    }

    public static function sanitize(string $source): string
    {
        if ($source === '' || strlen($source) > self::MAX_BYTES) {
            throw new InvalidArgumentException('SVG 图片不能为空且不能超过 5MB');
        }
        // Accept UTF-8 only so declarations cannot be hidden in an alternate encoding.
        if (strpos($source, "\0") !== false || !preg_match('//u', $source)
            || preg_match('/<!DOCTYPE|<!ENTITY/i', $source)) {
            throw new InvalidArgumentException('SVG 图片包含不支持的编码或文档声明');
        }
        if (!class_exists(DOMDocument::class)) {
            throw new InvalidArgumentException('服务器未启用 SVG 所需的 DOM 扩展');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $input = new DOMDocument();
            $input->resolveExternals = false;
            $input->substituteEntities = false;
            if (!$input->loadXML($source, LIBXML_NONET) || $input->doctype
                || !$input->documentElement || $input->documentElement->localName !== 'svg'
                || $input->documentElement->namespaceURI !== self::NS) {
                throw new InvalidArgumentException('文件不是有效的 SVG 图片');
            }
            $output = new DOMDocument('1.0', 'UTF-8');
            self::copyElement($input->documentElement, $output, $output);
            return $output->saveXML();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function copyElement(DOMElement $source, DOMNode $parent, DOMDocument $output): void
    {
        if ($source->namespaceURI !== self::NS || !in_array($source->localName, self::ELEMENTS, true)) {
            return;
        }
        $element = $output->createElementNS(self::NS, $source->localName);
        $parent->appendChild($element);
        foreach ($source->attributes as $attribute) {
            // No foreign namespaces, event handlers, href, animation or embedded resources.
            if ($attribute->namespaceURI) {
                continue;
            }
            $name = $attribute->name;
            $value = trim($attribute->value);
            if ($name === 'style') {
                $declarations = [];
                foreach (explode(';', $value) as $declaration) {
                    $pair = explode(':', $declaration, 2);
                    if (count($pair) === 2) {
                        $property = strtolower(trim($pair[0]));
                        $cssValue = trim($pair[1]);
                        if (in_array($property, self::PRESENTATION, true) && self::safePaintValue($cssValue)) {
                            $declarations[] = $property . ':' . $cssValue;
                        }
                    }
                }
                if ($declarations) {
                    $element->setAttribute('style', implode(';', $declarations));
                }
            } elseif (in_array($name, self::ATTRIBUTES, true)
                || (in_array($name, self::PRESENTATION, true) && self::safePaintValue($value))) {
                $element->setAttribute($name, $value);
            }
        }
        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMElement) {
                self::copyElement($child, $element, $output);
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $element->appendChild($output->createTextNode($child->textContent));
            }
        }
    }

    private static function safePaintValue(string $value): bool
    {
        // Only local paint/clip references and simple CSS values; no escapes, imports or external URLs.
        if (preg_match('/^url\(\s*([\'\"]?)#[a-zA-Z_][a-zA-Z0-9_.-]*\1\s*\)$/D', $value)) {
            return true;
        }
        $value = preg_replace('/(?:rgb|rgba|hsl|hsla)\([\d\s.,%+\-]+\)/i', '', $value);
        return (bool)preg_match('/^[a-zA-Z0-9\s#.,%+\-]*$/D', $value);
    }
}
