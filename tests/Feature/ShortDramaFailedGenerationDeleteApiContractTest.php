<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaFailedGenerationDeleteApiContractTest extends TestCase
{
    public function testDeleteRouteIsDeclaredHandledAndInstalled(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = json_decode((string)file_get_contents($root . '/app/apps/aigc_short_drama/api_schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $routes = array_values(array_filter((array)($schema['apis'] ?? []), static fn(array $api): bool => ($api['api_path'] ?? '') === 'app.aigc_short_drama.generation/delete'));
        self::assertCount(1, $routes);
        self::assertSame('POST', $routes[0]['api_method']);
        self::assertSame('user', $routes[0]['scene']);
        self::assertSame(1, $routes[0]['need_login']);

        $controller = (string)file_get_contents($root . '/app/api/controller/app/aigc_short_drama/GenerationController.php');
        self::assertStringContainsString('public function delete()', $controller);
        self::assertStringContainsString('AigcShortDramaService::deleteFailedGenerationTask(', $controller);

        foreach ([
            'app/apps/aigc_short_drama/migrations/install.sql',
            'public/install/db/like.sql',
            'upgrade/20260913_short_drama_failed_generation_delete.sql',
            'public/upgrade/20260913_short_drama_failed_generation_delete.sql',
        ] as $file) {
            self::assertStringContainsString('app.aigc_short_drama.generation/delete', (string)file_get_contents($root . '/' . $file), $file);
        }
    }
}
