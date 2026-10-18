<?php
namespace app\common\service\app\aigc_canvas;

/** Fail closed even if an overlay deployment left an obsolete controller on disk. */
final class CanvasLegacyRoutes
{
    public static function isRetired(string $controller, string $action): bool
    {
        $parts = explode('.', strtolower($controller));
        if (count($parts) !== 3 || $parts[0] !== 'app' || $parts[1] !== 'aigc_canvas') return false;
        $name = preg_replace('/controller$/', '', str_replace('_', '', $parts[2]));
        if ($name === 'agent') return str_replace('_', '', strtolower($action)) !== 'assetregister';
        return in_array($name, ['agentchat','agentgeneration','agenttool','skill','governance','admintrace'], true);
    }
}
