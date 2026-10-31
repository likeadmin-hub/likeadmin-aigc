<?php

namespace app\common\service\ai;

/** Extracts provider result URLs from result-only response containers. */
class AiTaskResultUrlService
{
    private const URL_FIELDS = [
        'url', 'uri', 'src', 'href', 'link', 'file', 'fileurl', 'download', 'downloadurl',
        'outputurl', 'sourceurl', 'originurl', 'originalurl', 'resulturl', 'previewurl', 'thumbnailurl',
        'image', 'imageurl', 'videourl', 'audiourl', 'mediaurl',
    ];

    private const CONTAINER_FIELDS = [
        'data', 'result', 'results', 'output', 'outputs', 'files', 'assets', 'media',
        'images', 'videos', 'audios', 'imageurls', 'videourls', 'audiourls', 'items', 'content', 'response', 'task', 'file', 'payload',
    ];

    /** @return array<int,string> */
    public static function collect(array $payload): array
    {
        $urls = [];
        self::collectValue($payload, $urls, 0, false);
        return array_values(array_unique($urls));
    }

    /** Image consumers may also receive the already-supported standard data URI form. */
    public static function collectImages(array $payload): array
    {
        $urls=[];self::collectValue($payload,$urls,0,false,true);return array_values(array_unique($urls));
    }

    /** @param array<int,string> $urls */
    private static function collectValue(mixed $value, array &$urls, int $depth, bool $allowScalarUrl, bool $inlineImages=false): void
    {
        if ($depth > 10) {
            return;
        }
        if (is_string($value)) {
            if ($allowScalarUrl && self::isResultUrl($value,$inlineImages)) {
                $urls[] = trim($value);
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $field = strtolower(str_replace(['_', '-'], '', (string)$key));
            if (is_scalar($item)) {
                if ((in_array($field, self::URL_FIELDS, true)||($allowScalarUrl&&(is_int($key)||ctype_digit((string)$key)))) && self::isResultUrl((string)$item,$inlineImages)) {
                    $urls[] = trim((string)$item);
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $isResultContainer = is_int($key) || ctype_digit((string)$key) || in_array($field, self::CONTAINER_FIELDS, true);
            if ($isResultContainer) {
                self::collectValue($item, $urls, $depth + 1, true,$inlineImages);
            }
        }
    }

    private static function isResultUrl(string $value,bool $inlineImages): bool
    {
        if(str_starts_with($value,'data:'))return $inlineImages&&strlen($value)<=27962112&&preg_match('~^data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=]+$~iD',$value)===1;
        return self::isHttpUrl($value);
    }

    private static function isHttpUrl(string $value): bool
    {
        $parts = parse_url(trim($value));
        return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string)($parts['host'] ?? '')) !== '';
    }
}
