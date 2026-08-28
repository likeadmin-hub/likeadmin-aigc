<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class TenantUserEditContractTest extends TestCase
{
    public function testTenantUserEditUsesItsOwnValidationScene(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/app/tenantapi/controller/user/UserController.php');
        $validator = (string)file_get_contents($root . '/app/tenantapi/validate/user/UserValidate.php');

        self::assertStringContainsString("goCheck('setInfo')", $controller);
        self::assertStringContainsString('public function sceneSetInfo()', $validator);
        self::assertStringContainsString("return \$this->only(['id', 'field', 'value']);", $validator);
    }
}
