<?php
namespace app\common\service {
    class ConfigService {
        public static $value = null;
        public static function get($type, $name, $default) { return self::$value ?? $default; }
        public static function set($type, $name, $value) { self::$value = $value; }
    }
}
namespace {
    require __DIR__.'/../../app/common/service/PcDefaultFontService.php';
    use app\common\service\PcDefaultFontService as Font;
    function check($value) { if (!$value) throw new \RuntimeException('Default font regression'); }
    check(Font::read()==='default');
    foreach (Font::OPTIONS as $font) { Font::save(['pc_default_font'=>$font]); check(Font::read()===$font); }
    Font::save([]); check(Font::read()==='quicksand');
    foreach (['url(evil)', [], null] as $invalid) {
        try { Font::validate(['pc_default_font'=>$invalid]); throw new \RuntimeException('Accepted invalid font'); }
        catch (\InvalidArgumentException $expected) {}
    }
    \app\common\service\ConfigService::$value='unknown'; check(Font::read()==='default');
    echo "Default font: allowed values, legacy preservation and fallback passed\n";
}
