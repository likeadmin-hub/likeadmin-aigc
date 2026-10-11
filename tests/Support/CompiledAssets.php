<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/** Resolves the modules that the packaged HTML entry actually loads. */
final class CompiledAssets
{
    private static array $reachable = [];

    public static function root(): string { return dirname(__DIR__, 2); }

    public static function source(string $relative): string
    {
        foreach ([dirname(self::root()) . '/web', '/'] as $base) {
            $path = $base . '/' . $relative;
            if (is_file($path)) return (string)file_get_contents($path);
        }
        Assert::fail('Frontend source is missing: ' . $relative);
    }

    public static function entry(string $scope = 'pc'): string
    {
        $html = self::root() . '/public/' . $scope . '/index.html';
        Assert::assertFileExists($html);
        $source = (string)file_get_contents($html);
        Assert::assertSame(1, preg_match('~<script[^>]*type="module"[^>]*src="([^"?]+\.js)"~', $source, $matches), $html);
        $url = $matches[1];
        return ltrim(str_starts_with($url, '/assets/') ? '/' . $scope . $url : $url, '/');
    }

    public static function reachable(string $scope = 'pc'): array
    {
        if (isset(self::$reachable[$scope])) return self::$reachable[$scope];
        $queue = [self::entry($scope)]; $visited = [];
        while ($queue) {
            $relative = array_pop($queue);
            if (isset($visited[$relative])) continue;
            $path = self::root() . '/public/' . $relative;
            Assert::assertFileExists($path, 'Missing dependency of ' . $scope . ' entry: ' . $relative);
            $visited[$relative] = true;
            if (!in_array(pathinfo($path, PATHINFO_EXTENSION), ['js', 'css'], true)) continue;
            $text = (string)file_get_contents($path);
            preg_match_all('~["\']((?:\./|\.\./|/_nuxt/|/assets/)?[^"\'\s<>]*?\.(?:js|css|svg))(?:\?[^"\']*)?["\']~', $text, $matches);
            foreach ($matches[1] as $dependency) {
                if (str_contains($dependency, '://') || str_starts_with($dependency, 'data:')) continue;
                if (str_starts_with($dependency, '/')) $next = ltrim($dependency, '/');
                else $next = dirname($relative) . '/' . $dependency;
                $segments = [];
                foreach (explode('/', $next) as $part) {
                    if ($part === '..') array_pop($segments);
                    elseif ($part !== '.' && $part !== '') $segments[] = $part;
                }
                $next = implode('/', $segments);
                // Only build dependencies: textual URLs in application data are not imports.
                if (str_starts_with($dependency, './') && preg_match('~[.-][A-Za-z0-9_-]{8}\.(js|css)$~', $dependency)) {
                    Assert::assertFileExists(self::root() . '/public/' . $next, 'Broken built dependency: ' . $relative . ' -> ' . $dependency);
                }
                if (is_file(self::root() . '/public/' . $next)) $queue[] = $next;
            }
        }
        return self::$reachable[$scope] = array_keys($visited);
    }

    public static function path(string $scope, string $stem, string $extension = 'js', array $markers = []): string
    {
        $directory = $scope === 'pc' ? '_nuxt' : $scope . '/assets';
        $matches = [];
        foreach (self::reachable($scope) as $relative) {
            if (dirname($relative) !== $directory || !preg_match('~^' . preg_quote($stem, '~') . '[.-][^.]+\.' . preg_quote($extension, '~') . '$~', basename($relative))) continue;
            $text = (string)file_get_contents(self::root() . '/public/' . $relative);
            foreach ($markers as $marker) if (!str_contains($text, $marker)) continue 2;
            $matches[] = $relative;
        }
        Assert::assertCount(1, $matches, 'Expected one reachable ' . $scope . ' module: ' . $stem . '.' . $extension . ' containing ' . implode(', ', $markers));
        return $matches[0];
    }

    public static function read(string $scope, string $stem, string $extension = 'js', array $markers = []): string
    {
        return (string)file_get_contents(self::root() . '/public/' . self::path($scope, $stem, $extension, $markers));
    }

    public static function containing(string $scope, string $marker): string
    {
        $matches = [];
        foreach (self::reachable($scope) as $relative) {
            if (pathinfo($relative, PATHINFO_EXTENSION) !== 'js') continue;
            $text = (string)file_get_contents(self::root() . '/public/' . $relative);
            if (str_contains($text, $marker)) $matches[] = $text;
        }
        Assert::assertCount(1, $matches, 'Expected one reachable module containing ' . $marker);
        return $matches[0];
    }

    public static function vitePage(string $scope, string $page): string
    {
        $key = '/src/views/' . $page . '.vue';
        $entry = (string)file_get_contents(self::root() . '/public/' . self::entry($scope));
        $pattern = '~' . preg_quote('"' . $key . '":', '~') . '[^;]{0,80}?import\("\./([^"?]+\.js)"\)~';
        Assert::assertSame(1, preg_match($pattern, $entry, $matches), 'Route missing from packaged ' . $scope . ' entry: ' . $key);
        $relative = $scope . '/assets/' . $matches[1];
        Assert::assertContains($relative, self::reachable($scope));
        return self::module($relative);
    }

    private static function module(string $relative): string
    {
        $text = (string)file_get_contents(self::root() . '/public/' . $relative);
        // Vite may extract a page setup script and emit a small re-export wrapper.
        preg_match_all('~from[\"\']\./([^\"\']+\.vue_vue_type_script[^\"\']+\.js)[\"\']~', $text, $matches);
        foreach ($matches[1] as $file) $text .= "\n" . (string)file_get_contents(self::root() . '/public/' . dirname($relative) . '/' . $file);
        return $text;
    }

    public static function pcPage(string $page, string $extension = 'js'): string
    {
        foreach ([dirname(self::root()) . '/web/pc', '/pc'] as $base) {
            $manifest = $base . '/.nuxt/prerender/chunks/build/client.manifest.mjs';
            if (!is_file($manifest)) continue;
            $text = (string)file_get_contents($manifest);
            Assert::assertSame(1, preg_match('/const client_manifest = (\{[\s\S]*\});\s*export/', $text, $matches));
            $entries = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            $key = 'pages/' . $page . ($extension === 'js' ? '.vue' : '.css');
            Assert::assertArrayHasKey($key, $entries);
            $relative = '_nuxt/' . $entries[$key]['file'];
            Assert::assertContains($relative, self::reachable(), 'Manifest route must be reachable from the packaged PC entry');
            return (string)file_get_contents(self::root() . '/public/' . $relative);
        }
        Assert::fail('Nuxt client build manifest is required for route resolution');
    }
}
