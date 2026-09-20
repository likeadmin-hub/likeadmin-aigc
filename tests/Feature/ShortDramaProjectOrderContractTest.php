<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ShortDramaProjectOrderContractTest extends TestCase
{
    public function testProjectListsSortByCreationBeforePagination(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php');
        $start = strpos($source, 'public static function projectLists(');
        $end = strpos($source, 'public static function projectDetail(', $start);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString("->order(['create_time' => 'desc', 'id' => 'desc'])", $method);
        self::assertStringNotContainsString("'update_time' => 'desc'", $method);
        self::assertLessThan(strpos($method, '->page('), strpos($method, '->order('));
        self::assertStringContainsString("'tenant_id' => \$tenantId", $method);
        self::assertStringContainsString("'user_id' => \$userId", $method);
        self::assertStringContainsString("'delete_time' => 0", $method);
    }
}
