<?php

namespace Tests\Feature;

use app\common\service\decorate\DecorateTemplateService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class DecorateLegacyPayloadRepairTest extends TestCase
{
    private function repair(string $json, string $meta = ''): string
    {
        $method = new ReflectionMethod(DecorateTemplateService::class, 'repairExtraClosingBracket');
        $method->setAccessible(true);
        return $method->invoke(null, $json, $meta);
    }

    private function validate(string $json): void
    {
        $method = new ReflectionMethod(DecorateTemplateService::class, 'validatePagePayload');
        $method->setAccessible(true);
        $method->invoke(null, $json, '');
    }

    private function legacySeed(): string
    {
        return trim(file_get_contents(dirname(__DIR__) . '/fixtures/decoration-pc-home-extra-brace.json'));
    }

    public function testReportedPcSeedFailsValidationBeforeRepair(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('页面组件数据格式无效');
        $this->validate($this->legacySeed());
    }

    public function testRepairsInternalBraceWithoutChangingAnyComponentContent(): void
    {
        $raw = $this->legacySeed();
        $expected = str_replace('"height":"340px"}}}', '"height":"340px"}}', $raw);
        $repaired = $this->repair($raw);
        self::assertSame($expected, $repaired);
        $this->validate($repaired);
        self::assertSame(['pc-banner', 'pc-tool-config'], array_column(json_decode($repaired, true), 'name'));
        self::assertSame($repaired, $this->repair($repaired));
    }

    public function testFreshInstallAndNewTenantSeedsAreValidAndMatchRepairedLegacyData(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['public/install/db/like.sql', 'app/platformapi/db/tenantData.sql'] as $file) {
            $matched = 0;
            foreach (file($root . '/' . $file) as $line) {
                if (strpos($line, 'lajcn8d0hzhed') === false) {
                    continue;
                }
                $matched++;
                // The seed is a single SQL-escaped, quoted JSON string followed by a comma.
                $json = stripcslashes(substr(trim($line), 1, -2));
                $this->validate($json);
                self::assertSame($this->repair($this->legacySeed()), $json, $file);
            }
            self::assertSame(1, $matched, $file);
        }
    }

    public function testPreservesEscapedStringsUnknownFieldsAndTrailingBracketCompatibility(): void
    {
        $json = json_encode([[
            'name' => 'future-widget',
            'content' => ['text' => 'braces } ] ) and "quoted" text with \\ backslash'],
            'future_field' => ['keep' => true],
        ]], JSON_UNESCAPED_UNICODE);
        self::assertSame($json, $this->repair($json));
        foreach ([']', '}', ')'] as $bracket) {
            self::assertSame($json . '  ', $this->repair($json . $bracket . '  '));
        }
        $internal = substr($json, 0, -1) . '},' . '{"name":"spacer"}]';
        self::assertSame(json_decode($json, true)[0], json_decode($this->repair($internal), true)[0]);
        self::assertCount(2, json_decode($this->repair($internal), true));
    }

    public function testAmbiguousCorruptionAndInvalidWidgetsAreNotSilentlyReplaced(): void
    {
        foreach ([
            $this->legacySeed() . '}',
            '[{"name":"banner"} {"name":"nav"}]',
            '[{"name":"banner"}',
            '[{"name":"banner","content":"invalid"}}]',
            '[{"name":"rich-text","content":{"html":"<script>alert(1)</script>"}}}]',
            '[{"name":"banner","id":"same"}},{"name":"nav","id":"same"}]',
            '{"name":"banner"}',
        ] as $raw) {
            self::assertSame($raw, $this->repair($raw));
        }
        self::assertSame($this->legacySeed(), $this->repair($this->legacySeed(), 'invalid meta'));
    }
}
