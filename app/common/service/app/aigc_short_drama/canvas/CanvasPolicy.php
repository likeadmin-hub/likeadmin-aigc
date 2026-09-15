<?php

namespace app\common\service\app\aigc_short_drama\canvas;

/** Pure, testable wire validation. No provider calls and no application globals. */
final class CanvasPolicy
{
    public const NODE_TYPES = ['text', 'image', 'video', 'character', 'scene', 'storyboard'];
    public const MAX_TOOL_CALLS = 8;
    public const MAX_NODES = 500;

    public static function key($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', $value)) self::fail('INVALID_KEY', '请求标识无效');
        return $value;
    }

    public static function text($value, int $limit = 20000): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) self::fail('INVALID_TEXT', '文本格式或长度不符合要求');
        return trim($value);
    }

    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decode($value): array
    {
        $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) self::fail('INVALID_DATA', '工作区数据格式无效');
        return $decoded;
    }

    public static function hash(array $value): string { return hash('sha256', self::encode($value)); }

    public static function attachments($value): array
    {
        if (!is_array($value) || count($value) > 5) self::fail('INVALID_ATTACHMENTS', '最多支持 5 个图片或纯文本附件');
        $result = []; $keys = [];
        foreach ($value as $item) {
            if (!is_array($item)) self::fail('INVALID_ATTACHMENTS', '附件格式无效');
            $key = self::key($item['key'] ?? null);
            if (isset($keys[$key])) self::fail('INVALID_ATTACHMENTS', '附件标识重复');
            $keys[$key] = true;
            $name = self::text($item['name'] ?? '', 120);
            if (($item['kind'] ?? '') === 'text') {
                $result[] = ['key' => $key, 'kind' => 'text', 'name' => $name, 'content' => self::text($item['content'] ?? '')];
            } elseif (($item['kind'] ?? '') === 'image' && in_array($item['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
                && is_string($item['checksum'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $item['checksum'])) {
                $result[] = ['key' => $key, 'kind' => 'image', 'name' => $name, 'checksum' => $item['checksum'],
                    'mime' => $item['mime'], 'size' => (int)self::number($item['size'] ?? null, 1, 10485760)];
            } else self::fail('INVALID_ATTACHMENTS', '仅支持 JPG、PNG、WebP 图片与 UTF-8 纯文本');
        }
        return $result;
    }

    public static function layout(array $layout): array
    {
        if (strlen(self::encode($layout)) > 2097152) self::fail('LAYOUT_TOO_LARGE', '画布内容过大，请拆分视图');
        if (!is_array($layout['nodes'] ?? null) || !is_array($layout['connections'] ?? null)) self::fail('INVALID_LAYOUT', '画布节点或连线无效');
        if (count($layout['nodes']) > self::MAX_NODES || count($layout['connections']) > 1000) self::fail('LAYOUT_LIMIT', '画布节点或连线超过上限');
        $nodes = []; $ids = [];
        foreach ($layout['nodes'] as $node) {
            if (!is_array($node)) self::fail('INVALID_NODE', '节点无效');
            $id = self::key($node['id'] ?? null);
            if (isset($ids[$id]) || !in_array($node['type'] ?? '', self::NODE_TYPES, true)) self::fail('INVALID_NODE', '节点重复或类型不支持');
            $ids[$id] = true;
            $metadata = (array)($node['metadata'] ?? []);
            // Client URLs, task IDs, billing and official entity IDs are never authoritative.
            $nodes[] = ['id' => $id, 'type' => $node['type'], 'title' => self::text($node['title'] ?? '', 120),
                'position' => ['x' => self::number($node['position']['x'] ?? null), 'y' => self::number($node['position']['y'] ?? null)],
                'width' => self::number($node['width'] ?? 250, 120, 1600), 'height' => self::number($node['height'] ?? 250, 100, 1600),
                'metadata' => ['content' => self::text($metadata['content'] ?? ''), 'asset_id' => max(0, (int)($metadata['asset_id'] ?? 0))]];
        }
        $connections = []; $edges = []; $pairs = [];
        foreach ($layout['connections'] as $edge) {
            if (!is_array($edge)) self::fail('INVALID_EDGE', '连线无效');
            $id = self::key($edge['id'] ?? null); $from = $edge['fromNodeId'] ?? ''; $to = $edge['toNodeId'] ?? '';
            if (!is_string($from) || !is_string($to) || !isset($ids[$from], $ids[$to]) || $from === $to || isset($edges[$id]) || isset($pairs[$from . ':' . $to])) self::fail('INVALID_EDGE', '连线重复或引用了不存在的节点');
            $edges[$id] = true; $pairs[$from . ':' . $to] = true;
            $connections[] = ['id' => $id, 'fromNodeId' => $from, 'toNodeId' => $to];
        }
        return ['nodes' => $nodes, 'connections' => $connections, 'viewport' => [
            'x' => self::number($layout['viewport']['x'] ?? 0), 'y' => self::number($layout['viewport']['y'] ?? 0),
            'k' => self::number($layout['viewport']['k'] ?? 1, 0.05, 5)]];
    }

    public static function number($value, float $min = -1000000, float $max = 1000000): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) self::fail('INVALID_NUMBER', '画布坐标或尺寸无效');
        return (float)$value;
    }

    public static function fail(string $code, string $message): void { throw new \DomainException($code . ': ' . $message); }
}
