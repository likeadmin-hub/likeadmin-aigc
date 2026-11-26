<?php
namespace app\common\service\app\aigc_depth_replication;

use InvalidArgumentException;

final class Lighting
{
    // Public schema is also used to construct the PC sliders.
    public const CONTROLS = [
        'azimuth' => ['主光方向', -180, 180, -45, 1],
        'elevation' => ['主光仰角', 0, 90, 48, 1],
        'exposure' => ['整体亮度', -1, 1, 0, 0.05],
        'shadow' => ['阴影深浅', 0, 100, 45, 1],
        'key' => ['主光强度', 0, 100, 65, 1],
        'fill' => ['补光强度', 0, 100, 30, 1],
        'relief' => ['深度强度', 0, 2, 1, 0.05],
        'edge' => ['灰边抑制', 0, 100, 50, 1],
        'detail' => ['细节保留', 0, 100, 35, 1],
    ];

    public static function values(array $input): array
    {
        $values = [];
        foreach (self::CONTROLS as $key => [, $min, $max, $initial]) {
            $v = $input[$key] ?? $initial;
            if (!is_numeric($v) || !is_finite((float)$v) || $v < $min || $v > $max) {
                throw new InvalidArgumentException('灯光参数超出范围');
            }
            $values[$key] = (float)$v;
        }
        return $values;
    }

    /** Identical packed slope and detail lookup tables for Canvas preview and MP4 encoding. */
    public static function table(array $settings): string
    {
        $s = self::values($settings);
        $a = deg2rad($s['azimuth']);
        $e = deg2rad($s['elevation']);
        $lx = sin($a)*cos($e);
        $ly = -cos($a)*cos($e);
        $lz = sin($e);
        $gain = 2 ** $s['exposure'];
        $result = '';
        for ($x = 0; $x < 127; ++$x) {
            for ($y = 0; $y < 127; ++$y) {
                $gx = ($x-63)/15.75;
                $gy = ($y-63)/15.75;
                $scale = $s['relief'] / (1 + $s['edge']*0.0015*($gx*$gx+$gy*$gy));
                $nx = -$gx*$scale;
                $ny = -$gy*$scale;
                $length = sqrt(1+$nx*$nx+$ny*$ny);
                $dot = $nx*$lx+$ny*$ly;
                $key = max(0, ($dot+$lz)/$length);
                $fill = max(0, (-$dot+0.45)/$length);
                $light = .25+$s['key']*.011*$key+$s['fill']*.0055*$fill;
                $shade = .82+($light-.82)*sqrt($s['shadow']/100);
                $result .= chr((int)round(255*(1-exp(-2.35*$gain*$shade))));
            }
        }
        for ($i=0; $i<256; ++$i) {
            $result .= chr(max(0,min(255,128+(int)round(($i-128)*$s['detail']*.022))));
        }
        return base64_encode($result);
    }
}

