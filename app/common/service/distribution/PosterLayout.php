<?php
declare(strict_types=1);
namespace app\common\service\distribution;

use InvalidArgumentException;

/** Validates published poster geometry independently of the rendering platform. */
final class PosterLayout
{
    /** Check edits against the published template, including its permissions. */
    public static function validateUserEdit(array $published, array $edited): void
    {
        self::validate($published);
        self::validate($edited);
        if (($published['canvas'] ?? null) != ($edited['canvas'] ?? null)
            || ($published['schema_version'] ?? null) !== ($edited['schema_version'] ?? null)
            || count($published['layers']) !== count($edited['layers'])) {
            throw new InvalidArgumentException('海报模板已改变');
        }
        $originalById = [];
        foreach ($published['layers'] as $layer) $originalById[$layer['id']] = $layer;
        foreach ($edited['layers'] as $layer) {
            $original = $originalById[$layer['id']] ?? null;
            if (!$original) throw new InvalidArgumentException('海报图层已改变');
            $allowed = [];
            if (!empty($original['user_move'])) $allowed = array_merge($allowed, ['x', 'y']);
            if (!empty($original['user_resize'])) $allowed = array_merge($allowed, ['w', 'h']);
            if (($original['type'] ?? '') === 'text' && !empty($original['user_edit'])) $allowed[] = 'text';
            foreach ($allowed as $key) { unset($original[$key], $layer[$key]); }
            if ($original != $layer) throw new InvalidArgumentException('该图层不允许此修改');
        }
    }

    public static function validate(array $layout): void
    {
        $canvas = $layout['canvas'] ?? [];
        $width = (int)($canvas['width'] ?? 0);
        $height = (int)($canvas['height'] ?? 0);
        if (($layout['schema_version'] ?? null) !== 1 || $width < 480 || $height < 480 || $width > 2048 || $height > 2048 || $width * $height > 3000000 || (int)($canvas['background_asset_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('海报画布或背景无效');
        }
        $layers = $layout['layers'] ?? null;
        if (!is_array($layers) || count($layers) < 1 || count($layers) > 30) throw new InvalidArgumentException('海报图层数量无效');
        $ids = []; $code = null; $boxes = [];
        foreach ($layers as $layer) {
            $id = $layer['id'] ?? '';
            $type = $layer['type'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) || isset($ids[$id]) || !in_array($type, ['invite_code', 'avatar', 'nickname', 'text'], true)) throw new InvalidArgumentException('海报图层无效');
            $ids[$id] = true;
            foreach (['x', 'y', 'w', 'h'] as $key) {
                if (!isset($layer[$key]) || !is_numeric($layer[$key]) || !is_finite((float)$layer[$key])) throw new InvalidArgumentException('图层位置无效');
            }
            $x=(float)$layer['x']; $y=(float)$layer['y']; $w=(float)$layer['w']; $h=(float)$layer['h'];
            if ($x < 0 || $y < 0 || $w <= 0 || $h <= 0 || $x + $w > 1.000001 || $y + $h > 1.000001) throw new InvalidArgumentException('图层超出画布');
            if (in_array($type, ['nickname','text'], true)) {
                if (isset($layer['color']) && (!is_string($layer['color']) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $layer['color']))) throw new InvalidArgumentException('文字颜色无效');
                if (isset($layer['font_size']) && (!is_numeric($layer['font_size']) || $layer['font_size'] < 12 || $layer['font_size'] > 200)) throw new InvalidArgumentException('文字大小无效');
                if (isset($layer['text']) && (!is_string($layer['text']) || mb_strlen($layer['text']) > 300)) throw new InvalidArgumentException('文字内容过长');
            }
            if ($type === 'invite_code') {
                if ($code !== null || $w*$width < 180 || $h*$height < 180 || abs($w*$width - $h*$height) > 2) throw new InvalidArgumentException('推广码尺寸或数量无效');
                $code = [$x,$y,$w,$h];
            } else $boxes[] = [$x,$y,$w,$h];
        }
        if ($code === null) throw new InvalidArgumentException('请添加推广码');
        foreach ($boxes as $box) {
            if ($box[0] < $code[0]+$code[2] && $box[0]+$box[2] > $code[0] && $box[1] < $code[1]+$code[3] && $box[1]+$box[3] > $code[1]) throw new InvalidArgumentException('图层不能覆盖推广码');
        }
    }
}
