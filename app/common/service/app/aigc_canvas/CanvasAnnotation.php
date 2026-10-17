<?php
namespace app\common\service\app\aigc_canvas;

/** Portable, inert annotation data shared by projects and reusable presets. */
class CanvasAnnotation
{
    public static function normalize(array $raw): array
    {
        $pick = static fn($value, array $allowed, $default) => in_array($value, $allowed, true) ? $value : $default;
        $color = static fn($value, string $default) => is_string($value) && preg_match('/^(#[a-f\d]{6}([a-f\d]{2})?|transparent)$/iD', $value) ? $value : $default;
        $number = static fn($value, float $default, float $min, float $max) => is_numeric($value) && is_finite((float)$value) ? max($min, min($max, (float)$value)) : $default;
        $kind = $pick($raw['kind'] ?? '', ['shape','sticky','text'], 'shape');
        $preset = $pick($raw['textPreset'] ?? '', ['heading','subheading','body'], 'body');
        return [
            'kind'=>$kind, 'textPreset'=>$preset,
            'shape'=>$pick($raw['shape'] ?? '', ['square','circle','triangle','rectangle','hexagon','star','diamond','line'], 'square'),
            'fill'=>$color($raw['fill'] ?? '', $kind === 'sticky' ? '#fffa66' : '#c7c7c7'),
            'stroke'=>$color($raw['stroke'] ?? '', '#e0e0e0'),
            'strokeStyle'=>$pick($raw['strokeStyle'] ?? '', ['none','solid','dashed','long-dashed','dot'], ($raw['shape'] ?? '') === 'line' ? 'solid' : 'none'),
            'strokeWidth'=>$number($raw['strokeWidth'] ?? 2, 2, 0, 10),
            'radius'=>$number($raw['radius'] ?? 0, 0, 0, 100),
            'text'=>mb_substr(is_scalar($raw['text'] ?? '') ? (string)($raw['text'] ?? '') : '', 0, 20000),
            'color'=>$color($raw['color'] ?? '', $kind === 'sticky' ? '#000000' : '#ffffff'),
            'fontFamily'=>$pick($raw['fontFamily'] ?? '', ['Goldman','Inter','Poppins','Instrument Serif','Gorditas','Hammersmith One','Roboto','Playfair Display','Lato','Montserrat','Open Sans','Oswald','Raleway','Merriweather'], 'Inter'),
            'fontSize'=>$number($raw['fontSize'] ?? ($kind !== 'text' ? 16 : ($preset === 'heading' ? 28 : ($preset === 'subheading' ? 20 : 18))), 16, 6, 224),
            'fontWeight'=>$number($raw['fontWeight'] ?? ($kind !== 'text' ? 400 : ($preset === 'heading' ? 600 : ($preset === 'subheading' ? 500 : 400))), 400, 100, 900),
            'autoResize'=>($raw['autoResize'] ?? false) === true,
            'bold'=>($raw['bold'] ?? false) === true || (!array_key_exists('bold', $raw) && is_numeric($raw['fontWeight'] ?? null) && (float)$raw['fontWeight'] >= 700),
            'italic'=>($raw['italic'] ?? false) === true,
            'align'=>$pick($raw['align'] ?? '', ['left','center','right'], $kind === 'shape' ? 'center' : 'left'),
            'flipX'=>($raw['flipX'] ?? false) === true, 'flipY'=>($raw['flipY'] ?? false) === true,
        ];
    }
}
